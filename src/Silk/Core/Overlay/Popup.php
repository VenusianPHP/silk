<?php

namespace Silk\Core\Overlay;

use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use LogicException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\PartLook;
use Silk\Contracts\Look\State;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Layout\Column;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Tree\Tree;

/**
 * A column drawn in the host's overlay layer, above the root (spec 5.6). It grows out of its anchor
 * point on a BOUNCY spring (scale 0.72 + 0.28 shown, opacity min(1, shown x 1.8)) and, once closed,
 * keeps painting without input until shown reaches 0.
 */
class Popup extends Column
{
    /** Raven's popup surface shadow blur (widgets/common.c:384). */
    public const float SHADOW = 16.0;

    private ?Tree $host = null;

    private ?Widget $anchor = null;

    private Placement $placement = Placement::Below;

    /** @var array{float, float} */
    private array $point = [0.0, 0.0];

    /** @var array{float, float} the point it grows from, set when placed */
    private array $origin = [0.0, 0.0];

    private bool $open = false;

    private bool $modal = false;

    private Spring $shown;

    public function __construct(string $name, float $spacing = 0, float $padding = 4)
    {
        parent::__construct($name, $spacing, $padding);
        $this->shown = new Spring(0.0, SpringPreset::Bouncy);
    }

    /** The tree whose overlay layer it opens in; open() takes it from the anchor. */
    public function in(Tree $tree): static
    {
        $this->host = $tree;

        return $this;
    }

    public function open(Widget $anchor, Placement $p = Placement::Below): static
    {
        $this->host = Tree::of($anchor) ?? throw new LogicException("Popup '{$this->name}': the anchor is in no tree.");
        $this->anchor = $anchor;
        $this->placement = $p;

        return $this->show();
    }

    public function openAt(float $x, float $y): static
    {
        $this->anchor = null;
        $this->placement = Placement::AtPoint;
        $this->point = [$x, $y];

        return $this->show();
    }

    public function close(): static
    {
        if ($this->open) {
            $this->open = false;
            $this->shown->target(0.0);
            $this->host?->overlays()->closed($this);
            $this->invalidatePaint();
        }

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function shown(): float
    {
        return $this->shown->value();
    }

    public function setModal(bool $modal): static
    {
        $this->modal = $modal;

        return $this;
    }

    public function isModal(): bool
    {
        return $this->modal;
    }

    /** Whether a press outside it and Escape close it; a toast stays until its time is up or its action is taken. */
    public function isDismissable(): bool
    {
        return true;
    }

    /** A passive popup (a tooltip) is never hit, never focused and never top(). */
    public function isPassive(): bool
    {
        return false;
    }

    /** Whether opening it moves keyboard focus into it (a scope); a ComboBox list leaves focus in the field. */
    public function takesFocus(): bool
    {
        return ! $this->isPassive();
    }

    /** The point it was opened at (AtPoint), in host units. @return array{float, float} */
    protected function point(): array
    {
        return $this->point;
    }

    public function springs(): array
    {
        return [$this->shown];
    }

    /** Leaves the layer once a close has shrunk it away. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        if (! $this->open && $this->shown->atRest()) {
            $this->host?->overlays()->remove($this);
        }
    }

    /** Measures, places against the anchor or point, clamps inside $host, and arranges. */
    public function place(Rect $host): void
    {
        $want = $this->measure(new Size($host->w, $host->h));
        $w = min($want->w, $host->w);
        $h = min($want->h, $host->h);
        $a = $this->anchor?->bounds();
        [$x, $y] = match (true) {
            $a === null => $this->point,
            $this->placement === Placement::Above => [$a->x, $a->y - $h],
            $this->placement === Placement::Right => [$a->x + $a->w, $a->y],
            $this->placement === Placement::Left => [$a->x - $w, $a->y],
            $this->placement === Placement::AtPoint => $this->point,
            default => [$a->x, $a->y + $a->h],
        };
        // no room on the asked side: the other side of the anchor (a submenu at the right edge opens left)
        if ($a !== null) {
            $x = match (true) {
                $this->placement === Placement::Right && $x + $w > $host->x + $host->w => $a->x - $w,
                $this->placement === Placement::Left && $x < $host->x => $a->x + $a->w,
                default => $x,
            };
            $y = match (true) {
                $this->placement === Placement::Below && $y + $h > $host->y + $host->h => $a->y - $h,
                $this->placement === Placement::Above && $y < $host->y => $a->y + $a->h,
                default => $y,
            };
        }
        $this->origin = [$x, $y];
        $x = min(max($x, $host->x), $host->x + $host->w - $w);
        $y = min(max($y, $host->y), $host->y + $host->h - $h);
        $this->arrange(new Rect($x, $y, $w, $h));
    }

    /** The surface and its shadow (blur 16) at any shown value: the scale never passes ~1.03. */
    public function paintExtent(): Rect
    {
        return $this->bounds()->grow(max(self::SHADOW, $this->surface()->extra['popupShadow'] ?? 0.0) + self::OVERDRAW);
    }

    public function render(Painter $p): void
    {
        $shown = $this->shown->value();
        $o = min(1.0, max(0.0, $shown * 1.8));
        $s = 0.72 + 0.28 * $shown;
        [$ox, $oy] = $this->origin;
        $o < 1.0 && $p->pushOpacity($o);
        $s != 1.0 && $p->pushTransform($s, $ox * (1 - $s), $oy * (1 - $s));
        $this->paint($p);
        $s != 1.0 && $p->popTransform();
        $o < 1.0 && $p->popOpacity();
    }

    protected function paintSelf(Painter $p): void
    {
        // the platforms' popups bring their own shadow (platform_looks.c:248-257); Raven's take the caller's (common.c:433)
        $part = $this->surface();
        $look = $this->look();
        if ($look->material()->isGlass() || $look->liquid()) {
            // common.c:435-446: the popups of a glass look, and macOS 26's, are glass
            Surfaces::paint($p, $look, $this->bounds(), $part->radius, SurfaceKind::Popup);

            return;
        }
        $blur = $part->extra['popupShadow'] ?? static::SHADOW;
        if ($blur > 0 && $part->shadowColor !== null) {
            $p->shadow($this->bounds(), $part->radius, $blur, $part->shadowColor);
        }
        if ($part->background !== null) {
            $p->rect($this->bounds(), $part->background, $part->radius, $part->border, $part->borderWidth);
        }
    }

    /** The spring a popup opens on. */
    protected function appearPreset(): SpringPreset
    {
        return SpringPreset::Bouncy;
    }

    /** The popup surface's look: the Popup part. */
    protected function surface(): PartLook
    {
        return $this->look()->part('Popup', 'Body', State::Normal);
    }

    private function show(): static
    {
        $layer = ($this->host ?? throw new LogicException("Popup '{$this->name}' has no tree: open() it on an anchor or call in()."))->overlays();
        $this->open = true;
        $layer->push($this);
        // (in the tree now, so the look is there to ask)
        $this->shown->preset = $this->appearPreset();
        $this->shown->target(1.0);

        return $this->invalidateLayout();
    }
}
