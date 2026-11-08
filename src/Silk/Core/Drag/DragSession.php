<?php

namespace Silk\Core\Drag;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Tree;
use Silk\Core\Tree\Widget as TreeWidget;
use Surface\NutsAndBolts\Color;

/**
 * One in-app drag (Rdrag_begin, window.c:1256-1399, 2767-2790). The target is the nearest enabled widget at or
 * above the one under the pointer that has a drop callback; it gets Leave when the pointer leaves it, Enter when
 * it arrives, and Move as it goes, and its answer to Enter and Move says whether it would take the drop. On
 * release the drop is Dropped where the pointer is, if it is taken. Escape and the window losing focus cancel
 * (Leave). The chip of the label follows the pointer +14, +10, its edge Accent while a drop would be taken; the
 * target's frame lands BOUNCY from 14 units further out while it would take it, and leaves SNAPPY.
 */
final class DragSession
{
    public const float CHIP_X = 14.0;

    public const float CHIP_Y = 10.0;

    public const float LANDING = 14.0;

    private ?Widget $target = null;

    private bool $accepted = false;

    /** @var array{float, float} */
    private array $at;

    /** The frame: the target's rect when it was accepted; null until one is. */
    private ?Rect $frame = null;

    private float $frameRadius = 0.0;

    private Spring $shown;

    /** @var list<Rect> what the last paint covered */
    private array $painted = [];

    public function __construct(
        private readonly Tree $tree,
        public readonly Widget $source,
        public readonly DragPayload $payload,
        float $x,
        float $y,
    ) {
        $this->at = [$x, $y];
        $this->shown = new Spring(0.0, SpringPreset::Bouncy);
        $this->move($x, $y);
    }

    public function target(): ?Widget
    {
        return $this->target;
    }

    /** Whether a drop here would be taken. */
    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /** The pointer moved to host point $x, $y (rvn_window_drag). */
    public function move(float $x, float $y): void
    {
        $this->damage();
        $this->at = [$x, $y];
        $found = null;
        for ($w = $this->tree->hitTest($x, $y); $w !== null; $w = $w->parent()) {
            if ($w instanceof TreeWidget && $w->dropCallback() !== null && $w->isEnabled()) {
                $found = $w;
                break;
            }
        }
        if ($found !== $this->target) {
            $this->target && $this->send(DropPhase::Leave);
            [$this->target, $this->accepted] = [$found, false];
            $found && $this->accepted = $this->send(DropPhase::Enter);
        } elseif ($found !== null) {
            $this->accepted = $this->send(DropPhase::Move);
        } else {
            $this->accepted = false;
        }
        if ($this->accepted) {
            [$dx, $dy] = $this->tree->shiftOf($this->target);
            $rect = $this->target->bounds()->moved($dx, $dy);
            // another target: the highlight lands again
            if ($this->frame != $rect) {
                $this->shown = new Spring(0.0, SpringPreset::Bouncy);
            }
            $this->frame = $rect;
            $this->frameRadius = $this->tree->look->metric(Metric::CornerRadius) + 2;
        }
        $this->shown->preset = $this->accepted ? SpringPreset::Bouncy : SpringPreset::Snappy;
        $this->shown->target($this->accepted ? 1.0 : 0.0);
        $this->damage();
    }

    /** The button came up at $x, $y: Dropped there, if taken. The session ends. */
    public function release(float $x, float $y): void
    {
        $this->move($x, $y);
        $this->accepted && $this->target && $this->send(DropPhase::Dropped);
        $this->end();
    }

    /** Escape or focus loss: Leave, no Dropped. The session ends. */
    public function cancel(): void
    {
        $this->target && $this->send(DropPhase::Leave);
        [$this->target, $this->accepted] = [null, false];
        $this->end();
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->shown];
    }

    /** The chip: the label + 20 wide, the font + 12 high, at the pointer +14, +10 (window.c:1391-1398). */
    public function chipRect(): Rect
    {
        $font = $this->tree->look->font();

        return new Rect($this->at[0] + self::CHIP_X, $this->at[1] + self::CHIP_Y,
            $this->tree->measurer()->measure($this->payload->label, $font)->w + 20, $font->size + 12);
    }

    /** The target's frame as drawn: 14 units further out at 0, on the target at 1; null when none shows. */
    public function frameRect(): ?Rect
    {
        $shown = $this->shown->value();
        if ($this->frame === null || $shown <= 0.01) {
            return null;
        }

        return $this->frame->grow(self::LANDING * (1 - $shown));
    }

    public function paint(Painter $p): void
    {
        $look = $this->tree->look;
        $this->painted = [];
        if (($f = $this->frameRect()) !== null) {
            // window.c:861-878
            $o = min(1.0, max(0.0, $this->shown->value()));
            $accent = $look->color(Role::Accent);
            $p->shadow($f, $this->frameRadius, 18.0, self::fade($accent, 0.35 * $o));
            $p->rect($f, self::fade($accent, 0.16 * $o), $this->frameRadius, self::fade($accent, 0.95 * $o), 2.0);
            $this->painted[] = $f->grow(20);
        }
        if ($this->payload->label === '') {
            return;
        }
        $chip = $this->chipRect();
        $p->shadow($chip, 6.0, 12.0, self::fade($look->color(Role::Shadow), 0.6));
        $p->rect($chip, $look->color(Role::Popup), 6.0, $look->color($this->accepted ? Role::Accent : Role::PopupBorder), 1.0);
        $font = $look->font();
        $m = $this->tree->measurer()->metrics($font);
        $p->text($this->payload->label, $chip->x + 10, $chip->y + ($chip->h - $m['ascent'] - $m['descent']) / 2 + $m['ascent'], $look->color(Role::Text), $font);
        $this->painted[] = $chip->grow(14);
    }

    /** Repaint where the chip and the frame were and are. */
    public function damage(): void
    {
        foreach ($this->painted as $r) {
            $this->tree->addDamage($r);
        }
        $this->tree->addDamage($this->chipRect()->grow(14));
        ($f = $this->frame) && $this->tree->addDamage($f->grow(self::LANDING + 20));
    }

    private function end(): void
    {
        $this->damage();
        $this->tree->endDrag($this);
    }

    private function send(DropPhase $phase): bool
    {
        [$dx, $dy] = $this->tree->shiftOf($this->target);
        $cb = $this->target->dropCallback();

        return (bool) $cb(new DropEvent($phase, $this->at[0], $this->at[1], $this->at[0] - $dx, $this->at[1] - $dy,
            $this->payload->kind, $this->payload->data, $this->source));
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }
}
