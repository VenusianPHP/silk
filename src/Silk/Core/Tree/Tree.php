<?php

namespace Silk\Core\Tree;

use Silk\Core\Drag\DragPayload;
use Silk\Core\Drag\DragSession;
use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Look;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Container as ContainerContract;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Overlay\OverlayLayer;
use Silk\Core\Overlay\Tooltip;
use Silk\Text\TextMeasurer;
use Voyager\Contracts\IOPools\Loop;
use WeakMap;

/** A root container laid out to a viewport, painted, and hit tested. */
class Tree
{
    public const int MAX_PASSES = 4;

    private ?Size $viewport = null;

    /** @var WeakMap<WidgetContract, Tree>|null roots to their trees */
    private static ?WeakMap $trees = null;

    /**
     * @param  ?Look  $look  what widgets paint with
     * @param  ?Loop  $loop  where widgets post mail
     * @param  string  $window  the window name in mail names
     */
    public function __construct(
        public readonly ContainerContract $root,
        public ?Look $look = null,
        public ?Loop $loop = null,
        public readonly string $window = 'window',
    ) {
        self::$trees ??= new WeakMap;
        self::$trees[$root] = $this;
    }

    /** Every spring lands at once: set from silk.reduced_motion by the host, never read per widget. */
    public bool $reducedMotion = false;

    private ?TextMeasurer $measurer = null;

    private bool $full = true;

    /** @var list<Rect> */
    private array $damage = [];

    /** The region being painted, while paintAll() runs. */
    private ?Rect $region = null;

    /** @var array{float, float} the shift of the scrolled parents being painted */
    private array $shift = [0.0, 0.0];

    private ?OverlayLayer $overlays = null;

    private ?DragSession $drag = null;

    /** @var list<Closure(float): bool> run each frame with dt until they return false */
    private array $tickers = [];

    /** @var list<Closure(?WidgetContract, bool): bool> */
    private array $pressWatchers = [];

    private ?\Silk\Contracts\Host\WindowActions $windowActions = null;

    /** Tooltip delay, re-arm distance (spec 7.2). */
    public const float TOOLTIP_DELAY = 0.6;

    public const float TOOLTIP_REARM = 6.0;

    private ?WidgetContract $tipOwner = null;

    /** @var array{float, float} */
    private array $tipAt = [0.0, 0.0];

    private float $tipWaited = 0.0;

    private ?Tooltip $tip = null;

    /** Long press and touch slop (spec 7.4). */
    public const float LONG_PRESS = 0.5;

    public const float TOUCH_SLOP = 8.0;

    private ?WidgetContract $pressOwner = null;

    /** @var array{float, float} */
    private array $pressAt = [0.0, 0.0];

    private float $pressWaited = 0.0;

    /**
     * The sum of the shifts of $w's scrolled ancestors: [dx, dy] from layout to screen. Under a scaling ancestor (a
     * NodeGraph) it is the translation of transformOf() alone: use that, or toLocal(), there. @return array{float, float}
     */
    public function shiftOf(WidgetContract $w): array
    {
        [, $dx, $dy] = $this->transformOf($w);

        return [$dx, $dy];
    }

    /** How $w's layout shows on screen, through its ancestors: screen = s * layout + (tx, ty). @return array{float, float, float} */
    public function transformOf(WidgetContract $w): array
    {
        [$s, $tx, $ty] = [1.0, 0.0, 0.0];
        for ($at = $w->parent(); $at !== null; $at = $at->parent()) {
            [$cs, $cx, $cy] = $at->childTransform();
            [$s, $tx, $ty] = [$s * $cs, $cs * $tx + $cx, $cs * $ty + $cy];
        }

        return [$s, $tx, $ty];
    }

    /** A screen point in $w's layout coordinates. @return array{float, float} */
    public function toLocal(WidgetContract $w, float $x, float $y): array
    {
        [$s, $tx, $ty] = $this->transformOf($w);

        return [($x - $tx) / $s, ($y - $ty) / $s];
    }

    /** The nearest widget from $w up with a context menu. */
    public function contextOwner(?WidgetContract $w): ?WidgetContract
    {
        while ($w !== null && $w->contextMenu() === null) {
            $w = $w->parent();
        }

        return $w;
    }

    /** Opens $owner's context menu at the point. */
    public function openContextMenu(WidgetContract $owner, float $x, float $y): void
    {
        $this->hideTooltip();
        $this->cancelLongPress();
        $owner->contextMenu()->in($this)->openAt($x, $y);
    }

    /** A left press on $hit starts the long-press timer when something above it has a context menu. */
    public function armLongPress(?WidgetContract $hit, float $x, float $y): void
    {
        $this->pressOwner = $this->contextOwner($hit);
        $this->pressAt = [$x, $y];
        $this->pressWaited = 0.0;
    }

    /** A release, or a move beyond the touch slop, ends the long press. */
    public function cancelLongPress(?float $x = null, ?float $y = null): void
    {
        if ($x === null || hypot($x - $this->pressAt[0], $y - $this->pressAt[1]) > self::TOUCH_SLOP) {
            $this->pressOwner = null;
        }
    }

    private function tickLongPress(float $dt): bool
    {
        if ($this->pressOwner === null) {
            return false;
        }
        $this->pressWaited += $dt;
        if ($this->pressWaited >= self::LONG_PRESS - 1e-9) {
            $this->openContextMenu($this->pressOwner, ...$this->pressAt);
        }

        return true;
    }

    /** The tooltip on show (opening or open), or null. */
    public function tooltip(): ?Tooltip
    {
        return $this->tip?->isOpen() ? $this->tip : null;
    }

    /** The pointer is over $hit: arm the nearest tooltip, or re-arm after a move of more than 6 units. */
    public function pointerAt(?WidgetContract $hit, float $x, float $y): void
    {
        $owner = $hit;
        while ($owner !== null && $owner->tooltip() === null) {
            $owner = $owner->parent();
        }
        if ($owner === null) {
            $this->hideTooltip();

            return;
        }
        if ($owner !== $this->tipOwner || hypot($x - $this->tipAt[0], $y - $this->tipAt[1]) > self::TOOLTIP_REARM) {
            $this->hideTooltip();
            $this->tipOwner = $owner;
            $this->tipAt = [$x, $y];
        }
    }

    /** @return false, so callers can chain it in an expression */
    public function hideTooltip(): bool
    {
        $this->tip?->close();
        $this->tip = null;
        $this->tipOwner = null;
        $this->tipWaited = 0.0;

        return false;
    }

    /** @return bool whether a tooltip is still waiting to show */
    private function tickTooltip(float $dt): bool
    {
        if ($this->tipOwner === null || $this->tip !== null) {
            return false;
        }
        $this->tipWaited += $dt;
        if ($this->tipWaited < self::TOOLTIP_DELAY - 1e-9) {
            return true;
        }
        $this->tip = (new Tooltip('tooltip', $this->tipOwner->tooltip()))->in($this)->openAt(...$this->tipAt);

        return true;
    }

    /** The popups above the root. */
    /** Starts an in-app drag from $source at host point $x, $y; an earlier one is cancelled. */
    public function beginDrag(WidgetContract $source, DragPayload $payload, float $x, float $y): DragSession
    {
        $this->drag?->cancel();
        $this->hideTooltip();
        $this->cancelLongPress();

        return $this->drag = new DragSession($this, $source, $payload, $x, $y);
    }

    private ?\Silk\Contracts\Text\Translator $translator = null;

    /** The words widgets show; English when none is set. */
    public function translator(): \Silk\Contracts\Text\Translator
    {
        return $this->translator ??= new \Silk\Text\EnglishTranslator;
    }

    public function setTranslator(?\Silk\Contracts\Text\Translator $translator): static
    {
        $this->translator = $translator;
        $this->root->invalidateLayout();

        return $this;
    }

    /** The host's window actions (a staged host's), for a HeaderBar; null when the host offers none. */
    public function windowActions(): ?\Silk\Contracts\Host\WindowActions
    {
        return $this->windowActions;
    }

    public function setWindowActions(?\Silk\Contracts\Host\WindowActions $actions): static
    {
        $this->windowActions = $actions;
        // what a HeaderBar shows depends on it: every widget measures anew
        $walk = function (WidgetContract $w) use (&$walk): void {
            $w->invalidateLayout();
            $w instanceof ContainerContract && array_map($walk, $w->children());
        };
        $walk($this->root);

        return $this;
    }

    /** Runs $tick(dt) on every advance() until it returns false; frames keep coming while one runs (a toast's time). */
    public function tick(Closure $tick): void
    {
        $this->tickers[] = $tick;
    }

    /**
     * Runs $watch(?hit, onPopup) on every press until it returns false (Raven rvn_islands_pressed: a press anywhere else
     * folds an unfolded island, as it closes a menu).
     */
    public function watchPresses(Closure $watch): void
    {
        $this->pressWatchers[] = $watch;
    }

    /** A press at (x, y) on $hit, told to the watchers. */
    public function pressed(?WidgetContract $hit, float $x, float $y): void
    {
        $onPopup = $this->overlays?->hitTest($x, $y) !== null;
        $this->pressWatchers = array_values(array_filter($this->pressWatchers, fn (Closure $w) => $w($hit, $onPopup)));
    }

    /** The drag under way, or null. */
    public function drag(): ?DragSession
    {
        return $this->drag;
    }

    /** @internal the session ended itself */
    public function endDrag(DragSession $session): void
    {
        $this->drag === $session && $this->drag = null;
    }

    public function overlays(): OverlayLayer
    {
        return $this->overlays ??= new OverlayLayer($this);
    }

    /** Makes Tree::of() answer this tree for $root's subtree (a popup's). */
    public function adopt(WidgetContract $root): void
    {
        self::$trees[$root] = $this;
    }

    /** What widgets measure text with; TextMeasurer::default() unless set. */
    public function measurer(): TextMeasurer
    {
        return $this->measurer ??= TextMeasurer::default();
    }

    public function setMeasurer(TextMeasurer $measurer): static
    {
        $this->measurer = $measurer;
        $this->root->invalidateLayout();

        return $this;
    }

    /** The tree a widget belongs to, through its root. */
    public static function of(WidgetContract $w): ?self
    {
        while ($w->parent() !== null) {
            $w = $w->parent();
        }

        return self::$trees[$w] ?? null;
    }

    /** Measure bottom-up, arrange top-down, while something re-dirties; returns the passes run. */
    public function layout(Size $viewport): int
    {
        $viewport = new Size(max(0.0, $viewport->w), max(0.0, $viewport->h));
        if ($this->viewport != $viewport) {
            $this->viewport = $viewport;
            $this->root->invalidateLayout();
            $this->full = true;
        }

        $passes = 0;
        while ($this->root->needsLayout() && $passes < self::MAX_PASSES) {
            $this->root->measure($viewport);
            $this->root->arrange(new Rect(0, 0, $viewport->w, $viewport->h));
            $passes++;
        }
        $this->overlays?->layout($viewport);

        return $passes;
    }

    /** Paint everything next frame: first frame, resize, scale or engine change. */
    public function repaintAll(): void
    {
        $this->full = true;
    }

    /** A full repaint or damage is waiting, with no widget marked. */
    public function wantsPaint(): bool
    {
        return $this->full || $this->damage !== [];
    }

    /** Somewhere to repaint next frame (where a removed widget was). */
    public function addDamage(Rect $r): void
    {
        $this->damage[] = $r;
    }

    /** Whether $w, painting now, reaches the region being painted (with the shift of a scrolled parent). */
    public function paints(WidgetContract $w): bool
    {
        if ($this->region === null) {
            return true;
        }
        $at = $w instanceof Widget ? $w->paintExtent() : $w->bounds();
        $at = $at->moved(...$this->shift);
        if ($at->intersect($this->region) === null) {
            return false;
        }
        $w instanceof Widget && $w->recordPainted($at);

        return true;
    }

    /** Runs $paint with children drawn shifted by [$dx, $dy] (ScrollBox). */
    public function shifted(float $dx, float $dy, Closure $paint): void
    {
        $was = $this->shift;
        $this->shift = [$was[0] + $dx, $was[1] + $dy];
        try {
            $paint();
        } finally {
            $this->shift = $was;
        }
    }

    /**
     * Paints only what changed: the union of where each dirty widget was and is now (overdraw, shadows
     * and transitions included), clipped to that one region, background first, then the widgets and
     * popups that reach it in tree order. The whole viewport only when repaintAll() asked.
     */
    public function paintAll(Painter $p): void
    {
        $view = $this->viewport === null ? $this->root->bounds() : new Rect(0, 0, $this->viewport->w, $this->viewport->h);
        $region = $this->full ? $view : $this->dirtyRegion();
        $region = $region?->intersect($view);
        if ($region !== null) {
            $p->pushClip($region);
            if ($this->look !== null) {
                $p->rect($region, $this->look->color(\Silk\Contracts\Look\Role::Window));
            }
            $this->region = $region;
            try {
                $this->root->isVisible() && $this->root->render($p);
                $this->overlays?->paint($p);
                $this->drag?->paint($p);
            } finally {
                $this->region = null;
            }
            $p->popClip();
        }
        $this->full = false;
        $this->damage = [];
        $this->root->markPainted();
        foreach ($this->overlays?->all() ?? [] as $popup) {
            $popup->markPainted();
        }
    }

    /** The union of the damage and every self-dirty widget's last and current extent, in host units. */
    private function dirtyRegion(): ?Rect
    {
        $u = null;
        $add = function (?Rect $r) use (&$u): void {
            if ($r !== null && $r->w > 0 && $r->h > 0) {
                $u = $u === null ? $r : $u->union($r);
            }
        };
        foreach ($this->damage as $r) {
            $add($r);
        }
        $walk = function (WidgetContract $w, float $dx, float $dy, float $s = 1.0) use (&$walk, $add): void {
            if (! $w->needsPaint()) {
                return;
            }
            if ($w instanceof Widget && $w->needsSelfPaint()) {
                $add($w->lastPainted());
                if ($w->isVisible() && $this->onScreen($w)) {
                    $e = $w->paintExtent();
                    $add($s === 1.0 ? $e->moved($dx, $dy) : new Rect($e->x * $s + $dx, $e->y * $s + $dy, $e->w * $s, $e->h * $s));
                } else {
                    $w->recordPainted(null);
                }
            }
            if ($w instanceof ContainerContract && $w->isVisible()) {
                [$cs, $cx, $cy] = $w->childTransform();
                foreach ($w->children() as $child) {
                    $walk($child, $dx + $s * $cx, $dy + $s * $cy, $s * $cs);
                }
            }
        };
        $walk($this->root, 0.0, 0.0);
        foreach ($this->overlays?->all() ?? [] as $popup) {
            $walk($popup, 0.0, 0.0);
        }

        return $u;
    }

    /** Visible up to its root: hidden ancestors hide it too. */
    private function onScreen(WidgetContract $w): bool
    {
        for ($at = $w; $at !== null; $at = $at->parent()) {
            if (! $at->isVisible()) {
                return false;
            }
        }

        return true;
    }

    /** The deepest visible, enabled widget under the point, top-most sibling first. */
    public function hitTest(float $x, float $y): ?WidgetContract
    {
        return $this->overlays?->hitTest($x, $y) ?? $this->hit($this->root, $x, $y);
    }

    /** The deepest visible, enabled widget under the point inside $root's subtree. */
    public function hitWithin(WidgetContract $root, float $x, float $y): ?WidgetContract
    {
        return $this->hit($root, $x, $y);
    }

    private function hit(WidgetContract $w, float $x, float $y): ?WidgetContract
    {
        if (! $w->isVisible() || ! $w->isEnabled() || ! $w->bounds()->contains($x, $y)) {
            return null;
        }
        if ($w instanceof ContainerContract) {
            // a scrolled (or zoomed) container draws its children moved: map the point back
            [$s, $sx, $sy] = $w->childTransform();
            foreach (array_reverse($w->children()) as $child) {
                if ($w->childTakesPointer($child, $x, $y) && ($found = $this->hit($child, ($x - $sx) / $s, ($y - $sy) / $s))) {
                    return $found;
                }
            }
        }

        // a widget may let the pointer through where it shows nothing (Raven RVN_WF_NO_HIT)
        return ! $w instanceof Widget || $w->hits($x, $y) ? $w : null;
    }

    /**
     * Steps every moving spring by $dt and marks its widget for paint; with reduced motion every
     * spring lands on its target at once.
     * @return bool whether any spring is still moving
     */
    /**
     * A new look (spec 7.5): colours spring from what is on screen to $look (SMOOTH); metrics and fonts change
     * at once, so the tree lays out again. Reduced motion, or no look yet, assigns it outright.
     */
    public function setLook(Look $look): void
    {
        if ($this->look === null || $this->reducedMotion) {
            $this->look = $look;
        } else {
            $from = $this->look instanceof LookBlend ? $this->look->frozen() : $this->look;
            $this->look = new LookBlend($from, $look, (new Spring(0.0, SpringPreset::Smooth))->target(1.0));
        }
        $this->root->invalidateLayout();
        foreach ($this->overlays?->all() ?? [] as $popup) {
            $popup->invalidateLayout();
        }
    }

    public function advance(float $dt): bool
    {
        $reducedMotion = $this->reducedMotion;
        $moving = $this->advanceLook($dt);
        $walk = function (WidgetContract $w) use (&$walk, &$moving, $dt, $reducedMotion): void {
            foreach ($w->motionSprings() as $spring) {
                if (! $spring->atRest()) {
                    $reducedMotion ? $spring->finish() : $spring->step($dt);
                    $w->invalidatePaint();
                    $moving = $moving || ! $spring->atRest();
                }
            }
            $w->settleMotion();
            if ($w instanceof ContainerContract) {
                foreach ($w->children() as $child) {
                    $walk($child);
                }
            }
        };
        $walk($this->root);
        foreach ($this->overlays?->all() ?? [] as $popup) {
            $walk($popup);
        }

        foreach ($this->drag?->springs() ?? [] as $spring) {
            if (! $spring->atRest()) {
                $reducedMotion ? $spring->finish() : $spring->step($dt);
                $this->drag->damage();
                $moving = $moving || ! $spring->atRest();
            }
        }

        $this->tickers = array_values(array_filter($this->tickers, fn (Closure $t) => $t($dt)));

        return $this->tickLongPress($dt) | $this->tickTooltip($dt) || $moving || $this->tickers !== [];
    }

    /** Steps a running look blend; every widget repaints while it moves, and the blend gives way to its target on landing. */
    private function advanceLook(float $dt): bool
    {
        if (! $this->look instanceof LookBlend) {
            return false;
        }
        $spring = $this->look->t;
        $this->reducedMotion ? $spring->finish() : $spring->step($dt);
        $this->root->invalidatePaint();
        foreach ($this->overlays?->all() ?? [] as $popup) {
            $popup->invalidatePaint();
        }
        if ($spring->atRest()) {
            $this->look = $this->look->to;

            return false;
        }

        return true;
    }

    public function needsLayout(): bool
    {
        return $this->viewport === null || $this->root->needsLayout() || $this->any(fn ($p) => $p->needsLayout());
    }

    public function needsPaint(): bool
    {
        return $this->root->needsPaint() || $this->any(fn ($p) => $p->needsPaint());
    }

    private function any(\Closure $test): bool
    {
        foreach ($this->overlays?->all() ?? [] as $popup) {
            if ($test($popup)) {
                return true;
            }
        }

        return false;
    }
}
