<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\State;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Overlay\Popup;
use Silk\Painter\Icons;
use Silk\Painter\PartBox;
use Silk\Widgets\Concerns\MeasuresText;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;

/**
 * The open list of a Dropdown or ComboBox, as Raven's combo list (combo_box.c:53-232): rows of RowHeight, at
 * most ten shown (the rest scroll), below the field and at least as wide. Its look is the style's: 'slide'
 * (Raven: a Selection highlight that slides between rows, the chosen text in AccentHovered) or 'menu' (the
 * platforms: rows inset, each drawn as a menu row, a check mark on the chosen one, before or after its text).
 * A Dropdown's list takes focus; a ComboBox's leaves it in the field, which moves the highlight itself.
 * Past ten rows its scrollbar is Raven's (ScrollBars), on the right inside the padding.
 */
class DropdownList extends Popup
{
    use MeasuresText;

    /** Raven's combo list shadow (combo_box.c:116). */
    public const float SHADOW = 12.0;

    /** Rows shown before the list scrolls (combo_box.c:13). */
    public const int MAX_ROWS = 10;

    private int $highlight;

    private float $offset = 0.0;

    private Spring $highlightY;

    private bool $placed = false;

    private bool $hovered = false;

    /** The pointer on the scrollbar's track. */
    private bool $onTrack = false;

    /** Where the thumb was taken, along it; null when not held. */
    private ?float $grab = null;

    private Spring $barFade;

    private Spring $barExpand;

    /** @param list<string> $items */
    public function __construct(
        string $name,
        private readonly Chooses&Widget $owner,
        private readonly array $items,
        private readonly int $selected = -1,
        private readonly bool $focus = true,
    ) {
        parent::__construct($name, 0, 0);
        $this->focusable = $focus;
        $this->highlight = $selected;
        $this->highlightY = new Spring(0.0, SpringPreset::Snappy);
        $this->barFade = new Spring(0.0, SpringPreset::Smooth);
        $this->barExpand = new Spring(0.0, SpringPreset::Smooth);
    }

    public function takesFocus(): bool
    {
        return $this->focus;
    }

    /** The highlighted row, -1 for none. */
    public function highlight(): int
    {
        return $this->highlight;
    }

    public function setHighlight(int $index): void
    {
        $this->highlight = max(0, min(count($this->items) - 1, $index));
        $this->reveal($this->highlight);
        $this->invalidatePaint();
    }

    /** Row $index's line, in tree coordinates (scrolled). */
    public function rowRect(int $index): Rect
    {
        $b = $this->bounds();
        $row = $this->rowHeight();

        return new Rect($b->x + 1, $b->y + $this->pad() + $row * $index - $this->offset, $b->w - 2, $row);
    }

    public function motionSprings(): array
    {
        return [...parent::motionSprings(), $this->highlightY, $this->barFade, $this->barExpand];
    }

    private function list(): PartLook
    {
        return $this->look()->part('DropdownList', 'Body', State::Normal);
    }

    /** Between the edge and the rows (combo_box.c:57-62). */
    private function pad(): float
    {
        return (float) ($this->list()->extra['listPad'] ?? 1.0);
    }

    private function rowHeight(): float
    {
        return $this->look()->metric(Metric::RowHeight);
    }

    private function viewport(): float
    {
        return max(0.0, $this->bounds()->h - 2 * $this->pad());
    }

    protected function measureContent(Size $available): Size
    {
        $look = $this->look();
        $width = 0.0;
        foreach ($this->items as $item) {
            $width = max($width, $this->textSize($item)->w);
        }
        $width += 2 * $look->metric(Metric::PaddingX) + (count($this->items) > self::MAX_ROWS ? $look->metric(Metric::ScrollbarSize) : 0.0);

        return new Size(ceil($width), $this->rowHeight() * max(1, min(count($this->items), self::MAX_ROWS)) + 2 * $this->pad());
    }

    protected function arrangeContent(Rect $r): void
    {
        $this->offset = max(0.0, min($this->offset, $this->rowHeight() * count($this->items) - $this->viewport()));
    }

    /** Below the field, flipped above when there is no room, at least as wide, on whole units (window.c:616-629). */
    public function place(Rect $host): void
    {
        $f = $this->owner->bounds();
        $want = $this->measure(new Size($host->w, $host->h));
        $w = min(max($want->w, $f->w), $host->w);
        $h = min($want->h, $host->h);
        $y = $f->y + $f->h + 2;
        if ($y + $h > $host->y + $host->h && $f->y - $h >= $host->y) {
            $y = $f->y - $h;
        }
        $x = max($host->x, min($f->x, $host->x + $host->w - $w));
        $y = max($host->y, min($y, $host->y + $host->h - $h));
        $this->arrange(new Rect(floor($x), floor($y), $w, $h));
        if (! $this->placed) {
            $this->placed = true;
            $this->reveal($this->highlight);
            // the highlight starts where it is, then slides (combo_box.c:285, hot_y.started)
            $this->highlightY->snap($this->rowHeight() * max(0, $this->highlight));
        }
    }

    /** The scrollbar's track, at the right inside the padding (combo_box.c:84-88). */
    private function track(): Rect
    {
        $b = $this->bounds();
        $bar = $this->look()->metric(Metric::ScrollbarSize);

        return new Rect($b->x + $b->w - $bar - $this->pad(), $b->y + $this->pad(), $bar, $b->h - 2 * $this->pad());
    }

    private function scrolls(): bool
    {
        return $this->rowHeight() * count($this->items) > $this->viewport() + 1e-9;
    }

    /** @return array{float, float} the thumb's start and length along the track */
    private function thumb(): array
    {
        return ScrollBars::thumb($this->track()->h, $this->viewport(), $this->rowHeight() * count($this->items), $this->offset);
    }

    private function scrollTo(float $offset): void
    {
        $this->offset = max(0.0, min($offset, $this->rowHeight() * count($this->items) - $this->viewport()));
        $this->invalidatePaint();
    }

    private function barHot(bool $onTrack): void
    {
        $this->onTrack = $onTrack;
        $hot = $onTrack || $this->grab !== null;
        $this->barFade->target($hot || $this->hovered ? 1.0 : 0.0);
        $this->barExpand->target($hot ? 1.0 : 0.0);
        $this->invalidatePaint();
    }

    /** Raven rvn_scrollbar_event (common.c:645-680): a press on the track brings the thumb's middle under it, then drags. */
    private function barEvent(SilkEvent $e): bool
    {
        if (! $this->scrolls() && $this->grab === null) {
            return false;
        }
        $track = $this->track();
        [$start, $length] = $this->thumb();
        $travel = $track->h - $length;
        $max = $this->rowHeight() * count($this->items) - $this->viewport();
        $at = $e->y - $track->y;
        switch ($e->type) {
            case EventType::MouseDown:
                if ($e->button !== MouseButton::LEFT || ! $track->contains($e->x, $e->y)) {
                    return false;
                }
                if ($at < $start || $at > $start + $length) {
                    $travel > 0 && $this->scrollTo(($at - $length / 2) / $travel * $max);
                    [$start] = $this->thumb();
                }
                $this->grab = $at - $start;
                $this->barHot(true);

                return true;
            case EventType::MouseMove:
                if ($this->grab === null) {
                    return false;
                }
                $travel > 0 && $this->scrollTo(($at - $this->grab) / $travel * $max);

                return true;
            case EventType::MouseUp:
                if ($this->grab === null || $e->button !== MouseButton::LEFT) {
                    return false;
                }
                $this->grab = null;
                $this->barHot($track->contains($e->x, $e->y));

                return true;
            default:
                return false;
        }
    }

    private function rowAt(float $y): int
    {
        $row = (int) floor(($y - $this->bounds()->y - $this->pad() + $this->offset) / $this->rowHeight());

        return $row >= 0 && $row < count($this->items) ? $row : -1;
    }

    /** Scrolls row $index into view (combo_box.c:96-102). */
    private function reveal(int $index): void
    {
        if ($index < 0 || ! $this->placed) {
            return;
        }
        $row = $this->rowHeight();
        $top = $row * $index;
        if ($top < $this->offset) {
            $this->offset = $top;
        } elseif ($top + $row > $this->offset + $this->viewport()) {
            $this->offset = $top + $row - $this->viewport();
        }
    }

    public function handle(SilkEvent $e): bool
    {
        if ($this->barEvent($e)) {
            return true;
        }
        switch ($e->type) {
            case EventType::Enter:
            case EventType::Leave:
                $this->hovered = $e->type === EventType::Enter;
                $this->barHot(false);

                return false;
            case EventType::MouseMove:
                if ($this->scrolls() && $this->track()->contains($e->x, $e->y) !== $this->onTrack) {
                    $this->barHot(! $this->onTrack);
                }
                $row = $this->rowAt($e->y);
                if ($row >= 0 && $row !== $this->highlight) {
                    $this->highlight = $row;
                    $this->invalidatePaint();
                }

                return true;
            case EventType::MouseDown:
                return true;
            case EventType::MouseUp:
                $b = $this->bounds();
                $row = $this->rowAt($e->y);
                if ($e->button === MouseButton::LEFT && $row >= 0 && $e->x >= $b->x && $e->x < $b->x + $b->w) {
                    $this->owner->choose($row);
                }

                return true;
            case EventType::Wheel:
                $this->scrollTo($this->offset - $e->wheelY * $this->rowHeight() * 2);

                return true;
            case EventType::KeyDown:
                $last = count($this->items) - 1;
                $to = match ($e->key) {
                    Key::UP => max($this->highlight - 1, 0),
                    Key::DOWN => min($this->highlight + 1, $last),
                    Key::HOME => 0,
                    Key::END => $last,
                    default => null,
                };
                if ($to !== null) {
                    $this->setHighlight($to);

                    return true;
                }
                if (($e->key === Key::ENTER || $e->key === Key::SPACE) && $this->highlight >= 0) {
                    $this->owner->choose($this->highlight);

                    return true;
                }

                // Escape is left to the router, which closes the popup
                return false;
            default:
                return false;
        }
    }

    protected function paintSelf(Painter $p): void
    {
        parent::paintSelf($p);
        $look = $this->look();
        $b = $this->bounds();
        $row = $this->rowHeight();
        $pad = $this->pad();
        $padding = $look->metric(Metric::PaddingX);
        $x = $this->list()->extra;
        $first = max(0, (int) floor($this->offset / $row));
        $last = min(count($this->items), $first + (int) ($this->viewport() / $row) + 3);

        $p->pushClip(new Rect($b->x + 1, $b->y + $pad, $b->w - 2, $b->h - 2 * $pad));
        if (($x['rows'] ?? 'slide') === 'menu') {
            // the platforms: rows inset; the chosen one checked before its text (macOS) or after it (GNOME) (combo_box.c:118-143)
            $inset = (float) ($x['rowInset'] ?? 4.0);
            $lead = (float) ($x['lead'] ?? 0.0);
            for ($i = $first; $i < $last; $i++) {
                $line = new Rect($b->x + $inset, $b->y + $pad + $row * $i - $this->offset, $b->w - 2 * $inset, $row);
                $state = $i === $this->highlight ? State::Hover : ($i === $this->selected ? State::Selected : State::Normal);
                $item = $look->part('DropdownList', 'Item', $state);
                if ($item->background !== null) {
                    PartBox::paint($p, new Rect($line->x, $line->y + 1, $line->w, $line->h - 2), $item->background, $item->radius, $item->border, $item->borderWidth);
                }
                $ink = $item->text ?? $look->color(Role::Text);
                $p->text($this->items[$i], $line->x + $padding + $lead, $this->baselineIn($line), $ink, $this->textStyle());
                if ($i === $this->selected && isset($x['check'])) {
                    $cx = $x['check'] === 'before' ? $line->x + 12 : $line->x + $line->w - 16;
                    Icons::paint($p, Icons::CHECK, $cx, $line->y + $row / 2, 9.0, $ink);
                }
            }
        } else {
            // Raven: the highlight slides under the pointer from row to row (combo_box.c:147-159)
            if ($this->highlight >= 0) {
                $this->highlightY->target($row * $this->highlight);
                $y = $this->highlightY->value();
                $p->rect(new Rect($b->x + 1, $b->y + $pad + $y - $this->offset, $b->w - 2, $row), $look->color(Role::Selection));
            }
            for ($i = $first; $i < $last; $i++) {
                $line = new Rect($b->x + $padding, $b->y + $pad + $row * $i - $this->offset, $b->w - 2 * $padding, $row);
                $ink = $i === $this->selected ? $look->color(Role::AccentHovered) : $look->color(Role::Text);
                $p->text($this->items[$i], $line->x, $this->baselineIn($line), $ink, $this->textStyle());
            }
        }
        $p->popClip();
        if ($this->scrolls()) {
            [$start, $length] = $this->thumb();
            ScrollBars::paint($p, $look, $this->track(), true, $start, $length, $this->onTrack || $this->grab !== null,
                min(1.0, max(0.0, $this->barFade->value())), min(1.0, max(0.0, $this->barExpand->value())));
        }
    }
}
