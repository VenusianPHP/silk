<?php

namespace Silk\Core\Tree;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Look;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Motion\Appear;
use Silk\Core\Motion\Transition;
use Silk\Contracts\Widget\Container as ContainerContract;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Surface\NutsAndBolts\Color;
use Voyager\NutsAndBolts\Str;

/** The base every Silk widget extends: identity, flags, the measure cache and bounds. */
abstract class Widget implements WidgetContract
{
    private readonly string $uuid;

    private ?ContainerContract $parent = null;

    protected bool $visible = true;

    protected bool $enabled = true;

    protected bool $focusable = false;

    private ?Transition $appearAs = null;

    private ?Transition $disappearAs = null;

    private ?Appear $playing = null;

    private ?string $tooltip = null;

    private ?\Silk\Core\Overlay\Popup $contextMenu = null;

    /** @var (\Closure(\Silk\Core\Drag\DropEvent): bool)|null */
    private ?\Closure $dropCallback = null;

    private bool $layoutDirty = true;

    private bool $paintDirty = true;

    /** This widget itself needs paint (paintDirty also marks ancestors of a dirty widget). */
    private bool $selfDirty = true;

    /** Where it was last painted, in host units, or null. */
    private ?Rect $lastPainted = null;

    /** Paint may reach this far past the bounds: shadows, the press squash, a grown handle. */
    public const float OVERDRAW = 8.0;

    private ?Size $measuredFor = null;

    private ?Size $measured = null;

    private Rect $bounds;

    /** @var array<string, Color> own colours, keyed by role name */
    private array $colors = [];

    public function __construct(protected readonly string $name)
    {
        $this->uuid = Str::uuid()->toString();
        $this->bounds = new Rect(0, 0, 0, 0);
    }

    /** The size wanted within $available, without the cache. */
    abstract protected function measureContent(Size $available): Size;

    /** Places children inside $r; leaves have none. */
    protected function arrangeContent(Rect $r): void {}

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->parent === null ? $this->name : $this->parent->path().'.'.$this->name;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function parent(): ?ContainerContract
    {
        return $this->parent;
    }

    public function setParent(?ContainerContract $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function measure(Size $available): Size
    {
        if ($this->measured === null || $this->measuredFor != $available) {
            $this->measuredFor = $available;
            $this->measured = $this->measureContent($available);
        }

        return $this->measured;
    }

    public function arrange(Rect $r): void
    {
        $r = new Rect($r->x, $r->y, max(0.0, $r->w), max(0.0, $r->h));
        if ($r != $this->bounds) {
            $this->bounds = $r;
            $this->invalidatePaint();
        }
        $this->arrangeContent($r);
        $this->layoutDirty = false;
    }

    public function paint(Painter $p): void {}

    public function handle(SilkEvent $e): bool
    {
        return false;
    }

    public function setVisible(bool $visible): static
    {
        if ($this->playing?->leaving) {
            // shown again mid-disappear: back to visible, from where it is
            $this->playing = null;
            if ($visible) {
                return $this->invalidatePaint();
            }
        }
        if ($visible === $this->visible) {
            return $this;
        }
        if (! $visible && $this->disappearAs !== null) {
            // stays in layout, without input, until the transition lands (settleMotion)
            $this->playing = new Appear($this->disappearAs, leaving: true);

            return $this->invalidatePaint();
        }
        $this->visible = $visible;
        $this->playing = $visible && $this->appearAs !== null ? new Appear($this->appearAs, leaving: false) : null;

        return $this->invalidateLayout();
    }

    /** Opened at the pointer by a right press or a 0.5 s long press on this widget or a child without one. */
    public function setContextMenu(?\Silk\Core\Overlay\Popup $menu): static
    {
        $this->contextMenu = $menu;

        return $this;
    }

    /** Whether the point (in its bounds) is this widget's; false lets the pointer through to what is under it (Raven RVN_WF_NO_HIT). */
    public function hits(float $x, float $y): bool
    {
        return true;
    }

    /**
     * Takes drops (Rwidget_set_drop_callback): during an in-app drag the nearest enabled widget with one is the
     * target; its answer to Enter and Move says whether it would take the drop. Null: none.
     * @param (\Closure(\Silk\Core\Drag\DropEvent): bool)|null $callback
     */
    public function setDropCallback(?\Closure $callback): static
    {
        $this->dropCallback = $callback;

        return $this;
    }

    /** @return (\Closure(\Silk\Core\Drag\DropEvent): bool)|null */
    public function dropCallback(): ?\Closure
    {
        return $this->dropCallback;
    }

    public function contextMenu(): ?\Silk\Core\Overlay\Popup
    {
        return $this->contextMenu;
    }

    /** Shown 0.6 s after the pointer rests on this widget (or a child without one of its own). */
    public function setTooltip(?string $text): static
    {
        $this->tooltip = $text === '' ? null : $text;

        return $this;
    }

    public function tooltip(): ?string
    {
        return $this->tooltip;
    }

    /** Play $t the next time this widget becomes visible. */
    public function appear(?Transition $t): static
    {
        $this->appearAs = $t;

        return $this;
    }

    /** The transition set to play when it becomes visible, or null. */
    public function appearTransition(): ?Transition
    {
        return $this->appearAs;
    }

    /** Play $t backwards when this widget is hidden; it hides once the transition lands. */
    public function disappear(?Transition $t): static
    {
        $this->disappearAs = $t;

        return $this;
    }

    public function motionSprings(): array
    {
        return $this->playing === null ? $this->springs() : [...$this->springs(), $this->playing->spring];
    }

    public function settleMotion(): void
    {
        if ($this->playing === null || ! $this->playing->done()) {
            return;
        }
        $leaving = $this->playing->leaving;
        $this->playing = null;
        if ($leaving) {
            $this->visible = false;
            $this->invalidateLayout();
        }
        $this->invalidatePaint();
    }

    /** Where paint() may draw, in this widget's coordinates: the bounds plus overdraw, moved by a playing transition. */
    public function paintExtent(): Rect
    {
        $e = $this->bounds->grow(self::OVERDRAW);
        if ($this->playing === null) {
            return $e;
        }
        ['scale' => $s, 'scaleY' => $sy, 'dx' => $dx, 'dy' => $dy] = $this->playing->state();
        $cx = $this->bounds->x + $this->bounds->w / 2;
        $cy = $this->bounds->y + $this->bounds->h / 2;
        $moved = new Rect($cx + ($e->x - $cx) * $s + $dx, $cy + ($e->y - $cy) * $sy + $dy, $e->w * $s, $e->h * $sy);

        return $e->union($moved);
    }

    public function needsSelfPaint(): bool
    {
        return $this->selfDirty;
    }

    public function lastPainted(): ?Rect
    {
        return $this->lastPainted;
    }

    /** Set by the tree when it paints this widget, or forgets where it was (null). */
    public function recordPainted(?Rect $at): void
    {
        $this->lastPainted = $at;
    }

    public function render(Painter $p): void
    {
        $tree = $this->tree();
        if ($tree !== null && ! $tree->paints($this)) {
            return;
        }
        if ($this->playing === null) {
            $this->paint($p);

            return;
        }
        ['opacity' => $o, 'scale' => $s, 'scaleY' => $sy, 'dx' => $dx, 'dy' => $dy] = $this->playing->state();
        $b = $this->bounds();
        $fade = $o < 1.0;
        $move = $s != 1.0 || $sy != 1.0 || $dx != 0.0 || $dy != 0.0;
        $fade && $p->pushOpacity($o);
        // scale about the centre, then offset
        $move && $p->pushTransform($s, ($b->x + $b->w / 2) * (1 - $s) + $dx, ($b->y + $b->h / 2) * (1 - $sy) + $dy, $sy);
        $this->paint($p);
        $move && $p->popTransform();
        $fade && $p->popOpacity();
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setEnabled(bool $enabled): static
    {
        if ($enabled !== $this->enabled) {
            $this->enabled = $enabled;
            $this->invalidatePaint();
        }

        return $this;
    }

    /** False while a disappear transition plays: a leaving widget takes no input. */
    public function isEnabled(): bool
    {
        return $this->enabled && ! $this->playing?->leaving;
    }

    /** A colour of this widget's own, above the theme (Raven's Rwidget_set_color). */
    /** A Color, or another Role read from the look at each paint (it follows a theme switch); null for the look's own. */
    public function setColor(Role $role, Color|Role|null $color): static
    {
        if ($color === null) {
            unset($this->colors[$role->name]);
        } else {
            $this->colors[$role->name] = $color;
        }

        return $this->invalidatePaint();
    }

    /** Whether it has a colour of its own for $role (Raven rvn_widget_has_color). */
    public function hasColor(Role $role): bool
    {
        return isset($this->colors[$role->name]);
    }

    /** The widget's own colour, else (text only) an ancestor's, else the look's. */
    public function color(Role $role, Look $look): Color
    {
        if (isset($this->colors[$role->name])) {
            $c = $this->colors[$role->name];

            return $c instanceof Role ? $look->color($c) : $c;
        }
        $parent = $this->parent;
        if ($role === Role::Text && $parent instanceof self) {
            return $parent->color($role, $look);
        }

        return $look->color($role);
    }

    public function tree(): ?Tree
    {
        return Tree::of($this);
    }

    /** The tree's look; widgets outside a tree with a look cannot paint. */
    protected function look(): Look
    {
        $look = $this->tree()?->look ?? throw new \LogicException("'{$this->path()}' is not in a tree with a look.");
        // the nearest look scope above it (an Island: the dark twin) gives the look of its subtree
        for ($w = $this; $w !== null; $w = $w->parent()) {
            if ($w instanceof LookScope && ($scoped = $w->scopedLook($look)) !== null) {
                return $scoped;
            }
        }

        return $look;
    }

    /**
     * Posts mail through the tree's loop; only for user action, never from a setter. An ancestor may take it
     * first (Container::childMail), as Raven's composites take their children's callbacks.
     */
    protected function post(object $mail): void
    {
        for ($at = $this->parent(); $at !== null; $at = $at->parent()) {
            if ($at instanceof Container && $at->childMail($this, $mail)) {
                return;
            }
        }
        $this->tree()?->loop?->post($mail);
    }

    /** Plays a transition on a shown widget (Raven rvn_widget_play_transition): a field that refuses shakes its head with Pop. */
    public function playTransition(\Silk\Core\Motion\Transition $transition): static
    {
        if ($this->visible) {
            $this->playing = new \Silk\Core\Motion\Appear($transition, leaving: false);
            $this->invalidatePaint();
        }

        return $this;
    }

    /** The window name for mail. */
    protected function window(): string
    {
        return $this->tree()?->window ?? 'window';
    }

    public function setFocusable(bool $focusable): static
    {
        $this->focusable = $focusable;

        return $this;
    }

    public function isFocusable(): bool
    {
        return $this->focusable;
    }

    private bool $tabStop = true;

    /** False: focused by a click but skipped by Tab (Raven RVN_WF_NO_TAB). */
    public function setTabStop(bool $tabStop): static
    {
        $this->tabStop = $tabStop;

        return $this;
    }

    public function isTabStop(): bool
    {
        return $this->tabStop;
    }

    public function invalidateLayout(): static
    {
        for ($w = $this; $w !== null; $w = $w->parent()) {
            if ($w instanceof self) {
                $w->measured = null;
                $w->layoutDirty = true;
            }
        }

        return $this->invalidatePaint();
    }

    public function invalidatePaint(): static
    {
        $this->selfDirty = true;
        for ($w = $this; $w instanceof self && ! $w->paintDirty; $w = $w->parent()) {
            $w->paintDirty = true;
        }

        return $this;
    }

    public function isDirty(): bool
    {
        return $this->layoutDirty || $this->paintDirty;
    }

    public function needsLayout(): bool
    {
        return $this->layoutDirty;
    }

    public function needsPaint(): bool
    {
        return $this->paintDirty;
    }

    public function markPainted(): void
    {
        $this->paintDirty = false;
        $this->selfDirty = false;
    }

    public function springs(): array
    {
        return [];
    }

    public function bounds(): Rect
    {
        return $this->bounds;
    }
}
