<?php

namespace Silk\Widgets;

use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Widget;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\ScrollChanged;
use Surface\NutsAndBolts\Color;

/**
 * `scrollBar(name, vertical)` as TKScrollBar: a value 0..max(0, maximum - page) over a range the app owns.
 * The user's moves (thumb drag, track click by a page, a wheel by step per line) post ScrollChanged;
 * the app's post nothing. Drawn as Raven's scrollbar by the look's variant (ScrollBars).
 */
class ScrollBar extends Widget
{
    /** The thumb's inset from the track ends. */
    public const float INSET = 2.0;

    private int $maximum = 0;

    private int $page = 0;

    private int $step = 1;

    private int $value = 0;

    private bool $hovered = false;

    /** The overlay variant's fade and widen (Raven bar->fade, bar->expand: SMOOTH). */
    private Spring $fade;

    private Spring $expand;

    private ?float $grab = null;

    public function __construct(string $name, private readonly bool $vertical = true)
    {
        parent::__construct($name);
        $this->fade = new Spring(0.0, SpringPreset::Smooth);
        $this->expand = new Spring(0.0, SpringPreset::Smooth);
    }

    public function springs(): array
    {
        return [$this->fade, $this->expand];
    }

    /** max(track x viewport / content, min(24, track)) (spec 7.3). */
    public static function thumbLength(float $track, float $viewport, float $content): float
    {
        return $content <= 0 ? $track : min($track, max($track * $viewport / $content, min(24.0, $track)));
    }

    /** The thumb along a track from $start, $track long, at $fraction (0..1) of the travel. */
    public static function thumbAt(float $start, float $track, float $length, float $fraction): float
    {
        return $start + ($track - $length) * min(1.0, max(0.0, $fraction));
    }

    public function vertical(): bool
    {
        return $this->vertical;
    }

    public function setRange(int $maximum, int $page): static
    {
        if ($maximum < 0 || $page < 0) {
            throw new InvalidArgumentException("ScrollBar '{$this->name}': maximum and page are >= 0.");
        }
        [$this->maximum, $this->page] = [$maximum, $page];
        $this->value = $this->clamp($this->value);

        return $this->invalidatePaint();
    }

    public function maximum(): int
    {
        return $this->maximum;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function setStep(int $line): static
    {
        $this->step = max(1, $line);

        return $this;
    }

    public function step(): int
    {
        return $this->step;
    }

    public function setValue(int $value): static
    {
        $this->value = $this->clamp($value);

        return $this->invalidatePaint();
    }

    public function value(): int
    {
        return $this->value;
    }

    private function clamp(int $v): int
    {
        return max(0, min($v, max(0, $this->maximum - $this->page)));
    }

    /** A user move: clamps, and posts ScrollChanged when it changed. */
    private function moveTo(int $v): bool
    {
        $v = $this->clamp($v);
        if ($v === $this->value) {
            return false;
        }
        $this->value = $v;
        $this->invalidatePaint();
        $this->post(new ScrollChanged($this->window(), $this->path(), $this->uuid(), $v));

        return true;
    }

    protected function measureContent(Size $available): Size
    {
        $t = $this->look()->metric(Metric::ScrollbarSize);

        return $this->vertical ? new Size($t, 0) : new Size(0, $t);
    }

    /** [start, track length, thumb length, thumb start] along the bar's axis: the whole bar is the track (common.c:553-558). */
    private function geometry(): array
    {
        $b = $this->bounds();
        [$start, $track] = $this->vertical ? [$b->y, $b->h] : [$b->x, $b->w];
        [$at, $len] = ScrollBars::thumb($track, $this->page, $this->maximum, $this->value);

        return [$start, $track, $len, $start + $at];
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        $at = $this->vertical ? $e->y : $e->x;
        [$start, $track, $len, $thumb] = $this->geometry();
        switch ($e->type) {
            case EventType::Enter:
            case EventType::Leave:
                $this->hovered = $e->type === EventType::Enter;
                $this->hotChanged();

                return false;
            case EventType::Wheel:
                $lines = $this->vertical ? $e->wheelY : ($e->wheelX ?: $e->wheelY);

                // positive lines move toward the end, as CanvasWheeled scrolls toward the content's bottom
                return $this->moveTo($this->value + (int) round($lines * $this->step));
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if ($at >= $thumb && $at <= $thumb + $len) {
                    $this->grab = $at - $thumb;
                    $this->hotChanged();
                } else {
                    // a track click moves a page toward the press
                    $this->moveTo($this->value + ($at < $thumb ? -1 : 1) * max(1, $this->page));
                }

                return true;
            case EventType::MouseMove:
                if ($this->grab === null) {
                    return false;
                }
                $fraction = ($at - $this->grab - $start) / max(1.0, $track - $len);
                $this->moveTo((int) round($fraction * max(0, $this->maximum - $this->page)));

                return true;
            case EventType::MouseUp:
                $was = $this->grab !== null;
                $this->grab = null;
                $this->hotChanged();

                return $was;
            default:
                return false;
        }
    }

    /** The pointer is on the bar, or its thumb is held: the overlay shows and widens (common.c:585-598). */
    private function hotChanged(): void
    {
        $hot = $this->hovered || $this->grab !== null;
        $this->fade->target($hot ? 1.0 : 0.0);
        $this->expand->target($hot ? 1.0 : 0.0);
        $this->invalidatePaint();
    }

    public function paint(Painter $p): void
    {
        [$start, , $len, $thumb] = $this->geometry();
        ScrollBars::paint($p, $this->look(), $this->bounds(), $this->vertical, $thumb - $start, $len,
            $this->hovered || $this->grab !== null, min(1.0, max(0.0, $this->fade->value())), min(1.0, max(0.0, $this->expand->value())));
    }
}
