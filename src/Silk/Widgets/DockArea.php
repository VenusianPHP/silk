<?php

namespace Silk\Widgets;

use Silk\Looks\GlassStyle;
use Silk\Contracts\Painter\GlassLook;
use Silk\Painter\Surfaces;
use Silk\Contracts\Look\SurfaceKind;
use Closure;
use InvalidArgumentException;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\DockChanged;
use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Motion\Transition;
use Silk\Core\Tree\Container;
use Silk\Painter\Icons;
use Silk\Painter\RavenPaint;
use Silk\Widgets\Concerns\MeasuresText;
use Silk\Widgets\Docking\DockSide;
use Silk\Widgets\Docking\Layout;
use Silk\Widgets\Docking\Node;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/**
 * `DockArea`: panels the user arranges, side by side or behind tabs; Raven widgets/dock_area.c, raven_shell.h
 * Rdock_area_*. Every child is a panel, named by its widget name. Where the panels are is a binary tree of splits and
 * groups (Docking\Node); each group is drawn in a rectangle that runs after the tree's on four springs, so a dropped
 * panel's new group grows from the drop preview and the others glide to their new places. A tab dragged away docks
 * its panel beside a group, behind its tabs, or along an edge of the area; a border between two nodes resizes them.
 * The user's changes post DockChanged; the API posts nothing.
 */
class DockArea extends Container
{
    use MeasuresText;

    /** A panel is not squeezed below this by its neighbours (dock_area.c:19-20). */
    public const float MIN = 72.0;

    /** The pointer travels this far with a tab before the panel comes off (dock_area.c:21-22). */
    public const float DRAG_START = 6.0;

    /** Bands along the area's edges that dock against the whole area (dock_area.c:23-24). */
    public const float EDGE = 22.0;

    private const float ICON = 14.0;

    private const float ICON_GAP = 6.0;

    private ?Node $root = null;

    private bool $locked = false;

    private bool $snap = false;

    private ?Size $last = null;

    /** @var array<string, string> */
    private array $titles = [];

    /** @var array<string, array{Framebuffer, bool}> */
    private array $icons = [];

    /** @var array<string, true> panels without a close button (Raven slot dock_fixed) */
    private array $fixed = [];

    /** @var array<string, array{?DockSide, ?string, float}> hidden panels: where they go back, against what, how big */
    private array $hidden = [];

    private bool $closeHides = false;

    private bool $tearable = true;

    private ?Closure $closeCallback = null;

    /** The panel the next add docks against, and how (Rdock_area_add_panel's pending_relative). */
    private ?array $pending = null;

    // the tab under the pointer
    private ?Node $hotNode = null;

    private int $hotTab = -1;

    private bool $hotClose = false;

    // a press on a tab; it becomes a drag once the pointer moves away
    private ?string $pressPanel = null;

    private bool $pressClose = false;

    private float $pressX = 0.0;

    private float $pressY = 0.0;

    private ?string $middlePanel = null;

    private bool $dragging = false;

    private float $pointerX = 0.0;

    private float $pointerY = 0.0;

    // where the dragged panel would go
    private ?DockSide $drop = null;

    private ?Node $dropNode = null;

    private int $dropIndex = -1;

    private Rect $dropRect;

    private Spring $previewX;

    private Spring $previewY;

    private Spring $previewW;

    private Spring $previewH;

    private Spring $previewShown;

    // the tab that follows the pointer
    private ?string $chipPanel = null;

    private Spring $chipX;

    private Spring $chipY;

    private Spring $chipShown;

    // a border being dragged
    private ?Node $resizeNode = null;

    private float $resizeGrab = 0.0;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->dropRect = new Rect(0, 0, 0, 0);
        foreach (['previewX', 'previewY', 'previewW', 'previewH', 'chipX', 'chipY'] as $s) {
            $this->{$s} = new Spring(0.0, SpringPreset::Bouncy);
        }
        $this->previewShown = new Spring(0.0, SpringPreset::Bouncy);
        $this->chipShown = new Spring(0.0, SpringPreset::Bouncy);
    }

    // ---------------------------------------------------------------- API (raven_shell.h:188-285)

    /**
     * Adds a panel: on $side of the panel named $relative (null: the whole area; with Tab, the first group), getting
     * $size of the split room (0: a half, or a quarter along the whole area). A panel already here moves.
     */
    public function addPanel(WidgetContract $panel, string $title, DockSide $side = DockSide::Tab, ?string $relative = null, float $size = 0.0): static
    {
        $relative !== null && $this->panel($relative);
        $this->titles[$panel->name()] = $title;
        if ($panel->parent() === $this) {
            return $this->movePanel($panel->name(), $side, $relative, $size);
        }
        $this->pending = [$side, $relative, $size > 0 && $size < 1 ? $size : 0.0];
        $this->add($panel);

        return $this;
    }

    /** Moves a panel elsewhere in the area (Rdock_area_move_panel). */
    public function movePanel(string $panel, DockSide $side, ?string $relative = null, float $size = 0.0): static
    {
        $this->panel($panel);
        $relative !== null && $this->panel($relative);
        if ($relative === $panel) {
            return $this;
        }
        $this->openHidden($panel, activate: false);
        $target = $relative !== null ? $this->root?->find($relative)[0] : null;
        if ($target === null && $side === DockSide::Tab) {
            $target = $this->root?->firstGroup();
        }
        // alone in the area: nothing to be put against
        if ($target === null && $this->root !== null && ! $this->root->isSplit() && count($this->root->panels) === 1) {
            return $this;
        }
        $this->move($panel, $side, $target, $size, -1, null);

        return $this;
    }

    /** Removes a panel and lets it go (Rdock_area_close_panel); the close callback is not asked. */
    public function closePanel(string $panel): static
    {
        $this->close($panel, ask: false);

        return $this;
    }

    public function setPanelTitle(string $panel, string $title): static
    {
        $this->panel($panel);
        $this->titles[$panel] = $title;

        return $this->invalidateLayout();
    }

    public function panelTitle(string $panel): string
    {
        $this->panel($panel);

        return $this->titles[$panel] ?? '';
    }

    /**
     * The icon before a tab's title, 14 square ($tint: drawn in the title's colour). Raven's u0..v1 atlas rect is not
     * taken: blocked, Surface image() has no source rect (roadmap Blocked list #14); the tint colour waits on #2.
     */
    public function setPanelIcon(string $panel, ?Framebuffer $icon, bool $tint = false): static
    {
        $this->panel($panel);
        if ($icon === null) {
            unset($this->icons[$panel]);
        } else {
            $this->icons[$panel] = [$icon, $tint];
        }

        return $this->invalidateLayout();
    }

    public function setPanelClosable(string $panel, bool $closable): static
    {
        $this->panel($panel);
        if ($closable) {
            unset($this->fixed[$panel]);
        } else {
            $this->fixed[$panel] = true;
        }

        return $this->invalidateLayout();
    }

    /** Takes a panel out of the arrangement, keeping it and where it was (Rdock_area_hide_panel). */
    public function hidePanel(string $panel): static
    {
        $this->panel($panel);
        $this->hide($panel);

        return $this;
    }

    /** Puts a hidden panel back where it was and shows its tab; for any panel, shows its tab (Rdock_area_open_panel). */
    public function openPanel(string $panel): static
    {
        $this->panel($panel);
        $this->openHidden($panel, activate: true);

        return $this;
    }

    public function isPanelOpen(string $panel): bool
    {
        return $this->root?->find($this->panel($panel)->name())[0] !== null;
    }

    /** The close button hides its panel instead of letting it go (Rdock_area_set_close_hides). */
    public function setCloseHides(bool $hides): static
    {
        $this->closeHides = $hides;

        return $this;
    }

    /** Brings a panel's tab to the front (Rdock_area_show_panel). */
    public function showPanel(string $panel): static
    {
        [$group, $at] = $this->root?->find($this->panel($panel)->name()) ?? [null, -1];
        $group !== null && $this->activate($group, $at);

        return $this;
    }

    public function isPanelShown(string $panel): bool
    {
        [$group, $at] = $this->root?->find($this->panel($panel)->name()) ?? [null, -1];

        return $group !== null && $group->active === $at;
    }

    /** False stops the user moving panels; the borders still drag (Rdock_area_set_movable). */
    public function setMovable(bool $movable): static
    {
        $this->locked = ! $movable;

        return $this;
    }

    /** fn (string $panel): bool, asked before the user closes a panel; false keeps it (Rdock_area_set_close_callback). */
    public function setCloseCallback(?Closure $callback): static
    {
        $this->closeCallback = $callback;

        return $this;
    }

    /** The arrangement as Raven's layout text (Rdock_area_save_layout). */
    public function saveLayout(): string
    {
        return Layout::save($this->root);
    }

    /**
     * Arranges the panels as a saved text says; panels it does not name go behind the first tabs (a hidden one stays
     * hidden), names it has that match no panel are ignored. False, and nothing changed, when the text is not a layout.
     */
    public function loadLayout(string $layout): bool
    {
        $used = [];
        $root = Layout::load($layout, $this->panelNames(), $used);
        if ($root === false) {
            return false;
        }
        $this->root = $root;
        $this->endPress();
        foreach ($this->panelNames() as $name) {
            if (isset($used[$name])) {
                unset($this->hidden[$name]);
            } elseif (! isset($this->hidden[$name])) {
                $this->insert($name, DockSide::Tab, null, 0.0, -1);
            }
        }
        $this->showActive();

        return true;
    }

    /** Tearing (Rdock_area_set_tearable): kept, but no tab tears off while new windows are blocked (roadmap #10). */
    public function setTearable(bool $tearable): static
    {
        $this->tearable = $tearable;

        return $this;
    }

    public function isTearable(): bool
    {
        return $this->tearable;
    }

    /** @throws WindowException blocked: new windows from a widget (roadmap #10; dock_area.c:1152-1202) */
    public function floatPanel(string $panel, int $x, int $y): never
    {
        throw new WindowException('blocked: new windows from a widget');
    }

    /** @throws WindowException blocked: new windows from a widget (roadmap #10) */
    public function dockPanel(string $panel): never
    {
        throw new WindowException('blocked: new windows from a widget');
    }

    /** A docked panel is in no window of its own: there are none while new windows are blocked (roadmap #10). */
    public function panelWindow(string $panel): ?string
    {
        $this->panel($panel);

        return null;
    }

    /** @return list<string> the panels of the tree, group by group */
    public function panels(): array
    {
        return array_merge(...array_map(fn (Node $g) => $g->panels, $this->root?->groups() ?? []));
    }

    public function root(): ?Node
    {
        return $this->root;
    }

    // ---------------------------------------------------------------- children

    public function add(WidgetContract $child, ?\Silk\Contracts\Widget\Slot $slot = null): static
    {
        parent::add($child, $slot);
        $this->titles[$child->name()] ??= '';
        if (! isset($this->hidden[$child->name()]) && ($this->root?->find($child->name())[0] ?? null) === null) {
            [$side, $relative, $size] = $this->pending ?? [DockSide::Tab, null, 0.0];
            $this->pending = null;
            $target = $relative !== null ? $this->root?->find($relative)[0] : null;
            $this->insert($child->name(), $side, $target, $size, -1);
            $this->showActive();
        }

        return $this;
    }

    public function remove(WidgetContract $child): static
    {
        $name = $child->name();
        $this->take($name);
        unset($this->hidden[$name], $this->titles[$name], $this->icons[$name], $this->fixed[$name]);
        foreach ($this->hidden as $panel => [$side, $relative, $size]) {
            $relative === $name && $this->hidden[$panel] = [$side, null, $size];
        }
        $this->pressPanel === $name && $this->endPress();
        $this->chipPanel === $name && $this->chipPanel = null;
        $this->middlePanel === $name && $this->middlePanel = null;

        return parent::remove($child);
    }

    private function panel(string $name): WidgetContract
    {
        foreach ($this->children() as $c) {
            if ($c->name() === $name) {
                return $c;
            }
        }
        throw new InvalidArgumentException("DockArea '{$this->name}' has no panel '{$name}'.");
    }

    /** @return list<string> */
    private function panelNames(): array
    {
        return array_map(fn (WidgetContract $c) => $c->name(), $this->children());
    }

    /** Only the shown panel of each group takes part in layout and paint. */
    private function showActive(): void
    {
        $shown = [];
        foreach ($this->root?->groups() ?? [] as $g) {
            isset($g->panels[$g->active]) && $shown[$g->panels[$g->active]] = true;
        }
        foreach ($this->children() as $c) {
            $c->isVisible() !== isset($shown[$c->name()]) && $c->setVisible(isset($shown[$c->name()]));
        }
        $this->invalidateLayout();
    }

    // ---------------------------------------------------------------- tree (dock_area.c:236-334)

    private function replace(Node $node, Node $with): void
    {
        $parent = $node->parent;
        $with->parent = $parent;
        if ($parent === null) {
            $this->root = $with;
        } elseif ($parent->a === $node) {
            $parent->a = $with;
        } else {
            $parent->b = $with;
        }
    }

    private static function groupInsert(Node $group, string $panel, int $index): void
    {
        $index = $index < 0 || $index > count($group->panels) ? count($group->panels) : $index;
        array_splice($group->panels, $index, 0, [$panel]);
        $group->active = $index;
    }

    /** Takes a panel out of the tree; a group left empty goes and its neighbour takes its place. */
    private function take(string $panel): void
    {
        [$group, $at] = $this->root?->find($panel) ?? [null, -1];
        if ($group === null) {
            return;
        }
        array_splice($group->panels, $at, 1);
        if ($group->active > $at || $group->active >= count($group->panels)) {
            $group->active--;
        }
        $group->active < 0 && $group->panels !== [] && $group->active = 0;
        if ($group->panels !== []) {
            return;
        }
        if ($group->parent === null) {
            $this->root = null;
        } else {
            $split = $group->parent;
            $this->replace($split, $split->a === $group ? $split->b : $split->a);
        }
        $this->forget($group);
    }

    private function forget(Node $node): void
    {
        $this->hotNode === $node && $this->hotNode = null;
        if ($this->dropNode === $node) {
            [$this->dropNode, $this->drop] = [null, null];
        }
        $this->resizeNode === $node && $this->resizeNode = null;
    }

    /**
     * Puts a panel in the tree: behind the tabs of $target (a group; $index among them, -1 the end), or in a new
     * group on a side of $target (any node; null the whole area) getting $size of the room. Returns its group.
     */
    private function insert(string $panel, ?DockSide $where, ?Node $target, float $size, int $index): Node
    {
        if ($this->root === null) {
            $this->root = $group = new Node;
            self::groupInsert($group, $panel, 0);

            return $group;
        }
        if ($where === DockSide::Tab || $where === null) {
            $group = $target !== null && ! $target->isSplit() ? $target : ($target ?? $this->root)->firstGroup();
            self::groupInsert($group, $panel, $index);

            return $group;
        }
        $target ??= $this->root;
        if ($size <= 0 || $size >= 1) {
            $size = $target === $this->root && $target->isSplit() ? 0.25 : 0.5;
        }
        $group = new Node;
        self::groupInsert($group, $panel, 0);
        $split = new Node;
        $split->vertical = $where === DockSide::Top || $where === DockSide::Bottom;
        $this->replace($target, $split);
        if ($where === DockSide::Left || $where === DockSide::Top) {
            [$split->a, $split->b, $split->ratio] = [$group, $target, $size];
        } else {
            [$split->a, $split->b, $split->ratio] = [$target, $group, 1 - $size];
        }
        $group->parent = $target->parent = $split;

        return $group;
    }

    /** Shows a panel of a group; it slides in from the side of its tab (dock_area.c:578-588). */
    private function activate(Node $group, int $index): void
    {
        if ($index < 0 || $index >= count($group->panels) || $index === $group->active) {
            return;
        }
        $from = $group->active;
        $group->active = $index;
        $this->showActive();
        $this->panel($group->panels[$index])->playTransition($index > $from ? Transition::SlideLeft : Transition::SlideRight);
    }

    /** Moves a panel out of its group and into the tree where $where says (dock_area.c:830-868). */
    private function move(string $panel, ?DockSide $where, ?Node $target, float $size, int $index, ?Rect $from): void
    {
        [$source, $own] = $this->root?->find($panel) ?? [null, -1];
        if ($source === null || $where === null) {
            return;
        }
        if ($target === $source) {
            if ($where === DockSide::Tab) {
                $index < 0 && $index = count($source->panels);
                $index > $own && $index--;
            } elseif (count($source->panels) === 1) {
                return;
            }
        }
        // the panel's group may go and its neighbours move up: the target is found again by a panel of it
        $anchor = null;
        if ($target !== null && ! $target->isSplit() && $target !== $source) {
            $anchor = $target->panels[0];
        } elseif ($target === $source && count($source->panels) > 1) {
            $anchor = $source->panels[$own === 0 ? 1 : 0];
        }
        $this->take($panel);
        $target = $anchor !== null ? $this->root?->find($anchor)[0] : null;
        $group = $this->insert($panel, $where, $target, $size, $index);
        // a new group grows from where the preview of the drop was
        $from !== null && count($group->panels) === 1 && $group->placeAt($from);
        $this->showActive();
    }

    /** The user, or closePanel(), closes a panel (dock_area.c:872-894). */
    private function close(string $panel, bool $ask): void
    {
        $widget = $this->panel($panel);
        if ($ask && $this->closeCallback !== null && ! ($this->closeCallback)($panel)) {
            return;
        }
        if ($ask && $this->closeHides) {
            $this->hide($panel);

            return;
        }
        $this->remove($widget);
    }

    /** Takes a panel out of the tree, keeping it, and notes where it was (dock_area.c:899-913, 1060-1083). */
    private function hide(string $panel): void
    {
        [$group, $at] = $this->root?->find($panel) ?? [null, -1];
        if ($group === null || isset($this->hidden[$panel])) {
            return;
        }
        [$where, $relative, $size] = [DockSide::Tab, null, 0.0];
        if (count($group->panels) > 1) {
            // it shared its tabs: it comes back behind them
            $relative = $group->panels[$at === 0 ? 1 : 0];
        } elseif ($group->parent !== null) {
            $split = $group->parent;
            $other = $split->a === $group ? $split->b : $split->a;
            $firstSide = $split->a === $group;
            $relative = $other->firstGroup()->panels[0] ?? null;
            $where = $split->vertical ? ($firstSide ? DockSide::Top : DockSide::Bottom) : ($firstSide ? DockSide::Left : DockSide::Right);
            $size = $firstSide ? $split->ratio : 1 - $split->ratio;
            // the sibling was a whole subtree: against the whole area is close enough
            $other->isSplit() && $split->parent === null && $relative = null;
        }
        $this->pressPanel === $panel && $this->endPress();
        $this->chipPanel === $panel && $this->chipPanel = null;
        $this->hidden[$panel] = [$where, $relative, $size];
        $this->take($panel);
        $this->showActive();
    }

    /** A hidden panel goes back where it was, fading in; then, if asked, its tab comes to the front (dock_area.c:916-937). */
    private function openHidden(string $panel, bool $activate): void
    {
        if (isset($this->hidden[$panel])) {
            [$where, $relative, $size] = $this->hidden[$panel];
            unset($this->hidden[$panel]);
            $target = $relative !== null && ! isset($this->hidden[$relative]) ? $this->root?->find($relative)[0] : null;
            $this->insert($panel, $target === null && $where === DockSide::Tab ? DockSide::Tab : $where, $target, $size, -1);
            $this->showActive();
            $this->panel($panel)->playTransition(Transition::Fade);
        }
        if ($activate) {
            [$group, $at] = $this->root?->find($panel) ?? [null, -1];
            $group !== null && $this->activate($group, $at);
        }
    }

    // ---------------------------------------------------------------- geometry (dock_area.c:340-470)

    private function gap(): float
    {
        return max($this->look()->metric(Metric::SplitterSize), 4.0) + ($this->glass() ? 4.0 : 0.0);
    }

    private function bar(): float
    {
        return $this->look()->metric(Metric::ControlHeight) + ($this->glass() ? 10.0 : 2.0);
    }

    /** dock_area.c:313-317, 440-452, 658: a glass look gives the groups more room and rounder tabs. */
    private function glass(): bool
    {
        return $this->look()->material()->isGlass();
    }

    /** The first part of a split of $length, the border excluded. */
    private function firstLength(Node $split, float $length): float
    {
        $room = max(0.0, $length - $this->gap());
        $min = min(self::MIN, $room * 0.5);

        return floor(min($room - $min, max($min, $room * $split->ratio)));
    }

    private function layoutNode(Node $node, Rect $r): void
    {
        $node->rect = $r;
        if (! $node->isSplit()) {
            return;
        }
        $gap = $this->gap();
        if ($node->vertical) {
            $first = $this->firstLength($node, $r->h);
            $this->layoutNode($node->a, new Rect($r->x, $r->y, $r->w, $first));
            $this->layoutNode($node->b, new Rect($r->x, $r->y + $first + $gap, $r->w, max(0.0, $r->h - $first - $gap)));
        } else {
            $first = $this->firstLength($node, $r->w);
            $this->layoutNode($node->a, new Rect($r->x, $r->y, $first, $r->h));
            $this->layoutNode($node->b, new Rect($r->x + $first + $gap, $r->y, max(0.0, $r->w - $first - $gap), $r->h));
        }
    }

    private function groupAt(float $x, float $y): ?Node
    {
        foreach ($this->root?->groups() ?? [] as $g) {
            if ($g->shown()->contains($x, $y)) {
                return $g;
            }
        }

        return null;
    }

    /** The border of a split under a point, 2 of slack either side (dock_area.c:400-414). */
    private function splitAt(float $x, float $y): ?Node
    {
        foreach ($this->root?->nodes() ?? [] as $n) {
            if (! $n->isSplit()) {
                continue;
            }
            $border = $n->vertical
                ? new Rect($n->rect->x, $n->a->rect->y + $n->a->rect->h - 2, $n->rect->w, $this->gap() + 4)
                : new Rect($n->a->rect->x + $n->a->rect->w - 2, $n->rect->y, $this->gap() + 4, $n->rect->h);
            if ($border->contains($x, $y)) {
                return $n;
            }
        }

        return null;
    }

    private function tabNaturalWidth(string $panel): float
    {
        return $this->textSize($this->titles[$panel] ?: 'Panel')->w + 2 * $this->look()->metric(Metric::PaddingX)
            + (isset($this->fixed[$panel]) ? 4.0 : 22.0) + (isset($this->icons[$panel]) ? self::ICON + self::ICON_GAP : 0.0);
    }

    /** A tab of a group drawn in $shown; too many for the bar, they all give some width (dock_area.c:437-454). */
    private function tabRect(Node $group, Rect $shown, int $index): Rect
    {
        $inset = $this->glass() ? 8.0 : 0.0;
        $total = array_sum(array_map(fn ($p) => $this->tabNaturalWidth($p), $group->panels));
        $room = max(0.0, $shown->w - 2 * $inset);
        $scale = $total > $room && $total > 0 ? $room / $total : 1.0;
        [$x, $w] = [$shown->x + $inset, 0.0];
        foreach ($group->panels as $i => $p) {
            $w = $this->tabNaturalWidth($p) * $scale;
            if ($i === $index) {
                break;
            }
            $x += $w;
        }

        return $this->glass() ? new Rect($x, $shown->y + 6, $w, $this->bar() - 10) : new Rect($x, $shown->y, $w, $this->bar());
    }

    private static function closeRect(Rect $tab): Rect
    {
        return new Rect($tab->x + $tab->w - 20, $tab->y + floor(($tab->h - 16) / 2), 16, 16);
    }

    private function closable(string $panel, Rect $tab): bool
    {
        return ! isset($this->fixed[$panel]) && $tab->w >= 44;
    }

    /** @return array{int, bool} the tab under a point (or -1), and whether on its close button (dock_area.c:461-474) */
    private function tabAt(Node $group, float $x, float $y): array
    {
        $shown = $group->shown();
        if ($y < $shown->y || $y >= $shown->y + $this->bar()) {
            return [-1, false];
        }
        foreach ($group->panels as $i => $p) {
            $tab = $this->tabRect($group, $shown, $i);
            if ($x >= $tab->x && $x < $tab->x + $tab->w) {
                return [$i, $this->closable($p, $tab) && self::closeRect($tab)->contains($x, $y)];
            }
        }

        return [-1, false];
    }

    // ---------------------------------------------------------------- measure and arrange (dock_area.c:620-700)

    private function measureNode(Node $n, Size $available): Size
    {
        if (! $n->isSplit()) {
            // every panel is measured, but the area does not grow with them: a panel is cut to its cell
            foreach ($n->panels as $p) {
                $this->panel($p)->measure($available);
            }

            return new Size(self::MIN, self::MIN + $this->bar());
        }
        [$a, $b] = [$this->measureNode($n->a, $available), $this->measureNode($n->b, $available)];

        return $n->vertical ? new Size(max($a->w, $b->w), $a->h + $b->h + $this->gap()) : new Size($a->w + $b->w + $this->gap(), max($a->h, $b->h));
    }

    protected function measureContent(Size $available): Size
    {
        return $this->root === null ? new Size(self::MIN, self::MIN) : $this->measureNode($this->root, $available);
    }

    protected function arrangeContent(Rect $r): void
    {
        if ($this->root === null) {
            return;
        }
        // the area itself changed size: the groups follow at once
        $size = new Size($r->w, $r->h);
        $this->last != $size && $this->snap = true;
        $this->last = $size;
        $this->layoutNode($this->root, $r);
        foreach ($this->root->groups() as $g) {
            $g->chase($this->snap);
            $this->aimMark($g);
            $content = $this->content($g->shown());
            foreach ($g->panels as $p) {
                $w = $this->panel($p);
                $w->isVisible() && $w->arrange($content);
            }
        }
        $this->snap = false;
    }

    /** The mark of the shown tab slides to it on BOUNCY, relative to the group (dock_area.c:1281-1283). */
    private function aimMark(Node $g): void
    {
        $shown = $g->shown();
        $active = isset($g->panels[$g->active]) ? $this->tabRect($g, $shown, $g->active) : new Rect($shown->x, $shown->y, 0, $this->bar());
        $first = $g->markW->value() === 0.0 && $g->markW->atRest();
        $first ? $g->markX->snap($active->x - $shown->x) : $g->markX->target($active->x - $shown->x);
        $first ? $g->markW->snap($active->w) : $g->markW->target($active->w);
    }

    private function content(Rect $shown): Rect
    {
        $bar = $this->bar();
        $pad = $this->glass() ? 6.0 : 1.0;

        return new Rect($shown->x + $pad, $shown->y + $bar, max(0.0, $shown->w - 2 * $pad), max(0.0, $shown->h - $bar - $pad));
    }

    public function springs(): array
    {
        $groups = array_merge(...array_map(fn (Node $g) => $g->springs(), $this->root?->groups() ?? []));

        return [...$groups, $this->previewX, $this->previewY, $this->previewW, $this->previewH, $this->previewShown, $this->chipX, $this->chipY, $this->chipShown];
    }

    /** While a group glides, its panels follow it. */
    public function settleMotion(): void
    {
        parent::settleMotion();
        foreach ($this->root?->groups() ?? [] as $g) {
            if (! $g->x->atRest() || ! $g->y->atRest() || ! $g->w->atRest() || ! $g->h->atRest()) {
                $this->invalidateLayout();

                return;
            }
        }
    }

    // ---------------------------------------------------------------- dragging (dock_area.c:707-808)

    /** Where the dragged panel would go if dropped at (x, y). */
    private function computeDrop(string $panel, float $x, float $y): void
    {
        $b = $this->bounds();
        $bar = $this->bar();
        [$source, $own] = $this->root?->find($panel) ?? [null, -1];
        $previous = $this->drop;
        [$this->drop, $this->dropNode, $this->dropIndex] = [null, null, -1];
        if (! $b->contains($x, $y) || $this->root === null) {
            return;
        }
        [$lx, $ly] = [$x - $b->x, $y - $b->y];
        // along the edges of the area: against everything else
        if ($this->root->isSplit()) {
            if ($lx < self::EDGE) {
                [$this->drop, $this->dropRect] = [DockSide::Left, new Rect($b->x, $b->y, $b->w * 0.25, $b->h)];
            } elseif ($lx >= $b->w - self::EDGE) {
                [$this->drop, $this->dropRect] = [DockSide::Right, new Rect($b->x + $b->w * 0.75, $b->y, $b->w * 0.25, $b->h)];
            } elseif ($ly >= $b->h - self::EDGE) {
                [$this->drop, $this->dropRect] = [DockSide::Bottom, new Rect($b->x, $b->y + $b->h * 0.75, $b->w, $b->h * 0.25)];
            }
            if ($this->drop !== null) {
                $this->previewFrom($previous);

                return;
            }
        }
        $group = $this->groupAt($x, $y);
        if ($group === null) {
            return;
        }
        $shown = $group->shown();
        if ($y < $shown->y + $bar) {
            // on the bar: a place among the tabs
            $index = count($group->panels);
            foreach ($group->panels as $i => $p) {
                $tab = $this->tabRect($group, $shown, $i);
                if ($x < $tab->x + $tab->w * 0.5) {
                    $index = $i;
                    break;
                }
            }
            // next to itself: nothing would move
            if ($group === $source && ($index === $own || $index === $own + 1)) {
                return;
            }
            [$this->drop, $this->dropNode, $this->dropIndex] = [DockSide::Tab, $group, $index];
            $width = min($this->tabNaturalWidth($panel), $shown->w * 0.5);
            $n = count($group->panels);
            $at = $index < $n ? $this->tabRect($group, $shown, $index)->x
                : ($n > 0 ? $this->tabRect($group, $shown, $n - 1)->x + $this->tabRect($group, $shown, $n - 1)->w : $shown->x);
            $this->dropRect = new Rect(min(max($shown->x, $shown->x + $shown->w - $width), max($shown->x, $at - $width / 2)), $shown->y + 2, $width, $bar - 4);
        } else {
            $fx = $shown->w > 0 ? ($x - $shown->x) / $shown->w : 0.5;
            $fy = $shown->h > $bar ? ($y - $shown->y - $bar) / ($shown->h - $bar) : 0.5;
            $nearest = min($fx, 1 - $fx, $fy, 1 - $fy);
            if ($nearest > 0.27) {
                if ($group === $source) {
                    return;
                }
                [$this->drop, $this->dropRect] = [DockSide::Tab, $shown];
            } else {
                // a panel alone in its group cannot be put beside itself
                if ($group === $source && count($source->panels) === 1) {
                    return;
                }
                [$this->drop, $this->dropRect] = match (true) {
                    $nearest === $fx => [DockSide::Left, new Rect($shown->x, $shown->y, $shown->w * 0.5, $shown->h)],
                    $nearest === 1 - $fx => [DockSide::Right, new Rect($shown->x + $shown->w * 0.5, $shown->y, $shown->w * 0.5, $shown->h)],
                    $nearest === $fy => [DockSide::Top, new Rect($shown->x, $shown->y, $shown->w, $shown->h * 0.5)],
                    default => [DockSide::Bottom, new Rect($shown->x, $shown->y + $shown->h * 0.5, $shown->w, $shown->h * 0.5)],
                };
            }
            $this->dropNode = $group;
        }
        $this->previewFrom($previous);
    }

    /** The preview appears where it is needed, then glides from place to place (dock_area.c:799-806). */
    private function previewFrom(?DockSide $previous): void
    {
        if ($previous === null && $this->previewShown->value() < 0.05) {
            $r = $this->dropRect;
            $this->previewX->snap($r->x + $r->w / 2);
            $this->previewY->snap($r->y + $r->h / 2);
            $this->previewW->snap($r->w * 0.6);
            $this->previewH->snap($r->h * 0.6);
        }
    }

    private function setHot(?Node $group, int $tab, bool $close): void
    {
        if ($this->hotNode !== $group || $this->hotTab !== $tab || $this->hotClose !== $close) {
            [$this->hotNode, $this->hotTab, $this->hotClose] = [$group, $tab, $close];
            $this->invalidatePaint();
        }
    }

    private function endPress(): void
    {
        [$this->pressPanel, $this->pressClose, $this->dragging, $this->drop, $this->dropNode] = [null, false, false, null, null];
        $this->previewShown->preset = SpringPreset::Snappy;
        $this->previewShown->target(0.0);
        $this->chipShown->preset = SpringPreset::Snappy;
        $this->chipShown->target(0.0);
        $this->invalidatePaint();
    }

    private function changed(): void
    {
        $this->invalidateLayout();
        $this->post(new DockChanged($this->window(), $this->path(), $this->uuid()));
    }

    // ---------------------------------------------------------------- events (dock_area.c:1472-1640)

    public function handle(SilkEvent $e): bool
    {
        [$x, $y] = [$e->x, $e->y];
        switch ($e->type) {
            case EventType::MouseMove:
                [$this->pointerX, $this->pointerY] = [$x, $y];
                if ($this->resizeNode !== null) {
                    $split = $this->resizeNode;
                    $length = $split->vertical ? $split->rect->h : $split->rect->w;
                    $room = max(1.0, $length - $this->gap());
                    $min = min(self::MIN, $room * 0.5);
                    $position = ($split->vertical ? $y - $split->rect->y : $x - $split->rect->x) - $this->resizeGrab;
                    $split->ratio = min($room - $min, max($min, $position)) / $room;
                    // the border follows the hand, not a spring
                    $this->snap = true;
                    $this->invalidateLayout();

                    return true;
                }
                if ($this->pressPanel !== null) {
                    if (! $this->dragging && ! $this->locked && ! $this->pressClose
                        && (abs($x - $this->pressX) > self::DRAG_START || abs($y - $this->pressY) > self::DRAG_START)) {
                        $this->dragging = true;
                        $this->chipPanel = $this->pressPanel;
                        $this->chipX->snap($this->pressX);
                        $this->chipY->snap($this->pressY);
                        $this->chipShown->snap(0.0);
                        $this->drop = null;
                    }
                    if ($this->dragging) {
                        // (out of the area, a tab would tear off into a window: blocked, roadmap #10; nothing happens)
                        $this->computeDrop($this->pressPanel, $x, $y);
                        $this->aimOverlay();
                    }
                    $this->invalidatePaint();

                    return true;
                }
                $split = $this->splitAt($x, $y);
                $group = $split === null ? $this->groupAt($x, $y) : null;
                [$tab, $onClose] = $group !== null ? $this->tabAt($group, $x, $y) : [-1, false];
                $this->setHot($tab >= 0 ? $group : null, $tab, $onClose);

                return false;
            case EventType::Leave:
                $this->pressPanel === null && $this->resizeNode === null && $this->setHot(null, -1, false);

                return false;
            case EventType::MouseDown:
                if ($e->button === MouseButton::MIDDLE) {
                    // a middle click on a tab closes its panel, released on the same tab
                    if ($this->pressPanel !== null || $this->resizeNode !== null || $this->splitAt($x, $y) !== null) {
                        return true;
                    }
                    $group = $this->groupAt($x, $y);
                    [$tab] = $group !== null ? $this->tabAt($group, $x, $y) : [-1];
                    if ($tab < 0 || isset($this->fixed[$group->panels[$tab]])) {
                        return false;
                    }
                    $this->middlePanel = $group->panels[$tab];

                    return true;
                }
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if (($split = $this->splitAt($x, $y)) !== null) {
                    $this->resizeNode = $split;
                    $this->resizeGrab = ($split->vertical ? $y - $split->rect->y : $x - $split->rect->x) - ($split->vertical ? $split->a->rect->h : $split->a->rect->w);

                    return true;
                }
                $group = $this->groupAt($x, $y);
                [$tab, $onClose] = $group !== null ? $this->tabAt($group, $x, $y) : [-1, false];
                if ($tab < 0) {
                    return $group !== null && $y < $group->shown()->y + $this->bar();
                }
                [$this->pressPanel, $this->pressClose] = [$group->panels[$tab], $onClose];
                [$this->pressX, $this->pressY, $this->pointerX, $this->pointerY] = [$x, $y, $x, $y];
                if (! $onClose && $tab !== $group->active) {
                    $this->activate($group, $tab);
                    $this->changed();
                }

                return true;
            case EventType::MouseUp:
                if ($e->button === MouseButton::MIDDLE && $this->middlePanel !== null) {
                    $panel = $this->middlePanel;
                    $this->middlePanel = null;
                    $group = $this->groupAt($x, $y);
                    [$tab] = $group !== null ? $this->tabAt($group, $x, $y) : [-1];
                    $tab >= 0 && $group->panels[$tab] === $panel && $this->userClose($panel);

                    return true;
                }
                if ($e->button !== MouseButton::LEFT) {
                    return false;
                }
                if ($this->resizeNode !== null) {
                    $this->resizeNode = null;
                    $this->changed();

                    return true;
                }
                if ($this->pressPanel === null) {
                    return false;
                }
                $panel = $this->pressPanel;
                if ($this->dragging) {
                    [$drop, $target, $from, $index] = [$this->drop, $this->dropNode, $this->dropRect, $this->dropIndex];
                    $this->endPress();
                    if ($drop !== null) {
                        $this->move($panel, $drop, $target, 0.0, $index, $from);
                        $this->changed();
                    }
                } elseif ($this->pressClose) {
                    $group = $this->groupAt($x, $y);
                    [$tab, $onClose] = $group !== null ? $this->tabAt($group, $x, $y) : [-1, false];
                    $this->endPress();
                    $tab >= 0 && $onClose && $group->panels[$tab] === $panel && $this->userClose($panel);
                } else {
                    $this->endPress();
                }

                return true;
            default:
                return false;
        }
    }

    private function userClose(string $panel): void
    {
        $this->close($panel, ask: true);
        $this->changed();
    }

    /** The preview chases the drop, centre and size apart; the chip trails the pointer (dock_area.c:1366-1384, 1411-1412). */
    private function aimOverlay(): void
    {
        $previewing = $this->dragging && $this->drop !== null;
        $this->previewShown->preset = $previewing ? SpringPreset::Bouncy : SpringPreset::Snappy;
        $this->previewShown->target($previewing ? 1.0 : 0.0);
        if ($previewing) {
            $r = $this->dropRect;
            $this->previewX->target($r->x + $r->w / 2);
            $this->previewY->target($r->y + $r->h / 2);
            $this->previewW->target($r->w);
            $this->previewH->target($r->h);
        }
        $this->chipShown->preset = $this->dragging ? SpringPreset::Bouncy : SpringPreset::Snappy;
        $this->chipShown->target($this->dragging ? 1.0 : 0.0);
        if ($this->dragging) {
            $this->chipX->target($this->pointerX);
            $this->chipY->target($this->pointerY);
        }
    }

    // ---------------------------------------------------------------- paint (dock_area.c:1269-1446)

    public function paint(Painter $p): void
    {
        $this->paintSelf($p);
        // a panel cuts what it holds at the edges of its cell
        foreach ($this->root?->groups() ?? [] as $g) {
            $panel = isset($g->panels[$g->active]) ? $this->panel($g->panels[$g->active]) : null;
            if ($panel !== null && $panel->isVisible()) {
                $p->pushClip($this->content($g->shown()));
                $panel->render($p);
                $p->popClip();
            }
        }
        $this->paintOverlay($p);
    }

    protected function paintSelf(Painter $p): void
    {
        $look = $this->look();
        $b = $this->bounds();
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        if ($this->root === null) {
            $s = $this->textSize('No panel');
            $p->text('No panel', $b->x + ($b->w - $s->w) / 2, $this->baselineIn($b), self::fade($text, 0.4), $this->textStyle());

            return;
        }
        foreach ($this->root->groups() as $g) {
            $this->paintGroup($p, $g, $text);
        }
    }

    private function paintGroup(Painter $p, Node $g, Color $text): void
    {
        $look = $this->look();
        $shown = $g->shown();
        $bar = $this->bar();
        if ($shown->w < 2 || $shown->h < 2) {
            return;
        }
        // the mark is kept relative to the group, which may be moving; it stretches with its speed
        $mw = $g->markW->value();
        $stretch = min(abs($g->markX->velocity()) * 0.018, $mw * 0.35);
        [$mx, $mw] = [$shown->x + $g->markX->value() - $stretch / 2, $mw + $stretch];

        $radius = $look->metric(Metric::CornerRadius);
        if ($this->glass()) {
            // dock_area.c:1289-1295: the group is a panel of glass, the shown tab a pill of glass sliding under it
            Surfaces::paint($p, $look, $shown, 18.0, SurfaceKind::Panel);
            if ($mw > 6) {
                $active = isset($g->panels[$g->active]) ? $this->tabRect($g, $shown, $g->active) : new Rect($shown->x, $shown->y, 0, $bar);
                $pill = new Rect($mx + 2, $active->y, $mw - 4, $active->h);
                Surfaces::paint($p, $look, $pill, $pill->h / 2, SurfaceKind::Control, 1.0, 0.6);
            }
        } else {
            $p->rect($shown, $look->color(Role::Panel), $radius, $look->color(Role::Border), $look->metric(Metric::BorderWidth));
            $p->rect(new Rect($shown->x + 1, $shown->y + 1, $shown->w - 2, $bar - 1), $look->color(Role::Header), [$radius, $radius, 0.0, 0.0]);
            $p->rect(new Rect($shown->x + 1, $shown->y + $bar - 1, $shown->w - 2, 1), $look->color(Role::Separator));
            if ($mw > 0) {
                $p->rect(new Rect($mx, $shown->y + 1, $mw, $bar - 1), $look->color(Role::TabActive));
                $p->rect(new Rect($mx, $shown->y + 1, $mw, 2), $look->color(Role::Accent));
            }
        }

        $pad = $look->metric(Metric::PaddingX);
        $font = $this->textStyle();
        $p->pushClip(new Rect($shown->x + 1, $shown->y, $shown->w - 2, $bar));
        foreach ($g->panels as $i => $panel) {
            $tab = $this->tabRect($g, $shown, $i);
            $isActive = $i === $g->active;
            $isHot = $this->hotNode === $g && $this->hotTab === $i && ! $this->dragging;
            $closable = $this->closable($panel, $tab);
            // (the active tab's ink reads on TabActive, which is the accent in the high-contrast looks)
            $ink = $isActive ? ($this->glass() ? $text : RavenPaint::inkOn($text, $look->color(Role::TabActive), $look->color(Role::Header), $look->color(Role::AccentText))) : self::fade($text, 0.62);
            // the tab being dragged is in the hand of the user, not here
            $p->pushOpacity($this->dragging && $panel === $this->pressPanel ? 0.3 : 1.0);
            if (! $isActive && $isHot) {
                $this->glass()
                    ? $p->rect(new Rect($tab->x + 2, $tab->y, $tab->w - 4, $tab->h), $look->color(Role::HeaderHovered), $tab->h / 2)
                    : $p->rect(new Rect($tab->x, $tab->y + 1, $tab->w, $tab->h - 1), $look->color(Role::HeaderHovered));
            }
            $p->pushClip(new Rect($tab->x, $tab->y, max(0.0, $tab->w - ($closable ? 20 : 4)), $tab->h));
            $iconW = $this->paintIcon($p, $panel, $tab->x + $pad, $tab->y + $tab->h / 2, $ink);
            $p->text($this->titles[$panel] ?: 'Panel', $tab->x + $pad + $iconW, $this->baselineIn($tab, $font), $ink, $font);
            $p->popClip();
            if ($closable && ($isActive || $isHot)) {
                $c = self::closeRect($tab);
                $over = $isHot && $this->hotClose;
                $over && $p->rect($c, $look->color(Role::ControlPressed), 8.0);
                Icons::paint($p, Icons::CROSS, $c->x + 8, $c->y + 8, 7.0, $isActive ? $ink : ($over ? $text : self::fade($text, 0.6)));
            }
            $p->popOpacity();
        }
        $p->popClip();
    }

    /** A panel's icon at (x, centre y), in its title's colour when tinted; returns the width it takes (dock_area.c:428-435). */
    private function paintIcon(Painter $p, string $panel, float $x, float $cy, Color $ink): float
    {
        if (! isset($this->icons[$panel])) {
            return 0.0;
        }
        [$icon, $tint] = $this->icons[$panel];
        // the tint's colour reaches the engine once Surface image() takes one (Blocked list #2); its alpha fades now
        $p->image($icon, new Rect($x, floor($cy - self::ICON / 2), self::ICON, self::ICON), 0.0, $tint ? $ink : new Color(1, 1, 1, $ink->alpha));

        return self::ICON + self::ICON_GAP;
    }

    /** Above the panels: where the dragged panel would land, and its tab in the hand of the user (dock_area.c:1358-1446). */
    private function paintOverlay(Painter $p): void
    {
        $look = $this->look();
        $accent = $look->color(Role::Accent);
        $shown = $this->previewShown->value();
        if ($shown > 0.01) {
            // the frame wobbles into place; it grows from 90% as it appears
            $o = min(1.0, max(0.0, $shown));
            $w = max($this->previewW->value() * (0.9 + 0.1 * $shown) - 10, 4.0);
            $h = max($this->previewH->value() * (0.9 + 0.1 * $shown) - 10, 4.0);
            $r = new Rect($this->previewX->value() - $w / 2, $this->previewY->value() - $h / 2, $w, $h);
            if ($this->glass()) {
                // dock_area.c:1388-1399: the drop is a drop of the accent's glass
                $g = GlassStyle::of($look, SurfaceKind::Accent);
                $p->pushOpacity($o);
                $p->glass($r, min(18.0, min($w, $h) / 2), new GlassLook(self::fade($accent, 0.42), $g->rim, $g->frost, $g->refraction, $g->saturation, $g->glow, $g->bevel, 16.0, $g->shadowColor));
                $p->popOpacity();
            } else {
                $p->rect($r, self::fade($accent, 0.22 * $o), min(8.0, min($w, $h) / 2), self::fade($accent, 0.9 * $o), 2.0);
            }
        }
        $lifted = $this->chipShown->value();
        if ($lifted > 0.02 && $this->chipPanel !== null) {
            $title = $this->titles[$this->chipPanel] ?: 'Panel';
            $text = $this->color(Role::Text, $look);
            $this->glass() || $text = RavenPaint::inkOn($text, $look->color(Role::TabActive), $look->color(Role::Window), $look->color(Role::AccentText));
            $iconW = isset($this->icons[$this->chipPanel]) ? self::ICON + self::ICON_GAP : 0.0;
            $width = $this->textSize($title)->w + 28 + $iconW;
            $height = $this->bar() - 4;
            [$x, $y] = [$this->chipX->value(), $this->chipY->value()];
            $chip = new Rect($x - $width / 2, $y - $height / 2 + 4, $width, $height);
            $p->pushOpacity(min(1.0, max(0.0, $lifted * 1.5)));
            // it grows as it lifts, about the pointer, and stretches the way it goes by its speed (dock_area.c:1405-1442)
            $vx = min(0.3, max(0.0, abs($this->chipX->velocity()) * 0.00035));
            $vy = min(0.3, max(0.0, abs($this->chipY->velocity()) * 0.00035));
            $lift = 0.6 + 0.4 * $lifted;
            [$sx, $sy] = [$lift * (1 + $vx - $vy * 0.5), $lift * (1 + $vy - $vx * 0.5)];
            $p->pushTransform($sx, $x * (1 - $sx), $y * (1 - $sy), $sy);
            if ($this->glass()) {
                // dock_area.c:1422-1424
                Surfaces::paint($p, $look, $chip, $height / 2, SurfaceKind::Popup, 1.0);
            } else {
                // Raven's shadow 4 below the chip; the engine adds blur/4 of its own
                $p->shadow($chip, 6.0, 14.0, $look->color(Role::Shadow), 0.0, 4 - 14 / 4);
                $p->rect($chip, $look->color(Role::TabActive), 6.0, $accent, 1.0);
            }
            $font = $this->textStyle();
            $tw = $this->textSize($title)->w;
            if ($iconW > 0) {
                $this->paintIcon($p, $this->chipPanel, $chip->x + 14, $chip->y + $chip->h / 2, $text);
                $p->text($title, $chip->x + $iconW + ($chip->w - $iconW - $tw) / 2, $this->baselineIn($chip, $font), $text, $font);
            } else {
                $p->text($title, $chip->x + ($chip->w - $tw) / 2, $this->baselineIn($chip, $font), $text, $font);
            }
            $p->popTransform();
            $p->popOpacity();
        }
    }

    private static function fade(Color $c, float $by): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $by);
    }
}
