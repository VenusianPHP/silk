<?php

namespace Silk\Widgets;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Painter\Painter;
use Silk\Core\Drag\DragPayload;
use Silk\Widgets\Rows\ScrollingRows;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/**
 * `treeView(name, columns, nodes)` as TKTree (the TK name `tree` is Widget::tree() in Silk), drawn and driven as
 * Raven's tree (tree_view.c). TKTree owns the API and the mail (NodeSelected, NodeActivated, NodeExpanded); Raven
 * the look, the gestures and the keys. With one column it is exactly Raven's tree: a chevron icon at each branch,
 * the text after it, an optional icon (setIcon) and the node's own text colour; more columns get a header and
 * cells, Table-like. Several selected nodes are opt-in (setMultiSelect): Ctrl/Cmd toggles, Shift takes the
 * visible range, a press on one of several keeps them until release. Collapsing a branch moves the selection out
 * of it silently. A drag of the selection starts after the press moves more than 5 units (setDragType); the app
 * takes the drop and may light a drop node (setDropHighlight). Only the visible rows are drawn.
 */
class TreeView extends ScrollingRows
{
    public const float DRAG_START = 5.0;

    public const float DEFAULT_COLUMN = 120.0;

    /** @var list<TableColumn> */
    private array $columns;

    /** @var list<TreeNode> */
    private array $nodes = [];

    /** @var array<string, TreeNode> */
    private array $byId = [];

    /** @var array<string, ?string> */
    private array $parentOf = [];

    /** @var array<string, true> */
    private array $expanded = [];

    /** @var list<string> the visible rows' ids, top to bottom */
    private array $shown = [];

    /** @var list<int> the visible rows' depths */
    private array $depths = [];

    /** @var array<string, int> id => visible row */
    private array $rowOf = [];

    private ?string $selected = null;

    /** @var array<string, true> several selected (multi only) */
    private array $marked = [];

    private ?string $anchor = null;

    private bool $multi = false;

    /** @var array<string, Framebuffer> */
    private array $icons = [];

    private ?string $dragType = null;

    /** A press that may become a drag: x, y. @var array{float, float}|null */
    private ?array $press = null;

    /** A plain press on one of several selected: on release without a drag, only it stays. */
    private ?string $pressReduce = null;

    private ?string $dropNode = null;

    private bool $dropRoot = false;

    /** @param list<TableColumn> $columns @param list<TreeNode> $nodes */
    public function __construct(string $name, array $columns, array $nodes = [])
    {
        parent::__construct($name);
        $this->columns = array_values($columns);
        $this->setNodes($nodes);
    }

    /** @return list<TableColumn> the first holds the chevron */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Replaces every node. Expansion is kept for ids still present with children, the selection when its id is
     * still present. Posts nothing. @throws WindowException When an id appears twice; nothing changes then.
     */
    public function setNodes(array $nodes): static
    {
        $byId = $parentOf = [];
        $walk = function (array $list, ?string $parent) use (&$walk, &$byId, &$parentOf): void {
            foreach ($list as $n) {
                if (isset($byId[$n->id])) {
                    throw new WindowException("TreeView '{$this->name}' has the id '{$n->id}' twice.");
                }
                $byId[$n->id] = $n;
                $parentOf[$n->id] = $parent;
                $walk($n->children, $n->id);
            }
        };
        $walk($nodes, null);
        [$this->nodes, $this->byId, $this->parentOf] = [array_values($nodes), $byId, $parentOf];
        $this->expanded = array_filter($this->expanded, fn ($_, $id) => isset($byId[$id]) && $byId[$id]->children !== [], ARRAY_FILTER_USE_BOTH);
        isset($byId[$this->selected ?? '']) || $this->selected = null;
        $this->marked = array_intersect_key($this->marked, $byId);
        isset($byId[$this->anchor ?? '']) || $this->anchor = null;
        $this->icons = array_intersect_key($this->icons, $byId);
        isset($byId[$this->dropNode ?? '']) || $this->dropNode = null;

        return $this->flatten();
    }

    /** @return list<TreeNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** A leaf does nothing; a node under a collapsed ancestor is recorded and opens with it. Posts nothing. @throws WindowException */
    public function expand(string $id, bool $expanded = true): static
    {
        $this->setExpanded($this->node($id), $expanded);

        return $this;
    }

    /** @throws WindowException When there is no such node. */
    public function isExpanded(string $id): bool
    {
        $this->node($id);

        return isset($this->expanded[$id]);
    }

    public function selectedNode(): ?string
    {
        return $this->selected;
    }

    /** Expands the ancestors and scrolls the node into view. Posts nothing. @throws WindowException */
    public function selectNode(?string $id): static
    {
        $id === null ? null : $this->node($id);
        $this->select($id, false);

        return $this;
    }

    /** Several selected nodes (Rtree_view_set_multi_select); off: one. */
    public function setMultiSelect(bool $multi): static
    {
        $this->multi = $multi;
        $multi || $this->marked = [];

        return $this->invalidatePaint();
    }

    /** @return list<string> every selected node, in visible order (the primary one alone when not multi) */
    public function selectedNodes(): array
    {
        if (! $this->multi) {
            return $this->selected === null ? [] : [$this->selected];
        }
        $ids = array_keys($this->marked);
        usort($ids, fn ($a, $b) => ($this->rowOf[$a] ?? PHP_INT_MAX) <=> ($this->rowOf[$b] ?? PHP_INT_MAX));

        return $ids;
    }

    /** An icon before the node's text (Rtree_item_set_icon), null for none. */
    public function setIcon(string $id, ?Framebuffer $icon): static
    {
        $this->node($id);
        if ($icon === null) {
            unset($this->icons[$id]);
        } else {
            $this->icons[$id] = $icon;
        }

        return $this->invalidatePaint();
    }

    /** The drag type a drag of the selection carries (Rtree_view_set_drag_type); null: no drag. */
    public function setDragType(?string $type): static
    {
        $this->dragType = $type === '' ? null : $type;

        return $this;
    }

    /** Lights the node a drop would go to, or the whole tree (Rtree_view_set_drop_highlight); null, false: none. */
    public function setDropHighlight(?string $id, bool $root = false): static
    {
        [$this->dropNode, $this->dropRoot] = [$id, $root];

        return $this->invalidatePaint();
    }

    /** @return list<string> the visible nodes' ids, top to bottom */
    public function visibleIds(): array
    {
        return $this->shown;
    }

    public function depthAt(int $row): int
    {
        return $this->depths[$row];
    }

    public function rowCount(): int
    {
        return count($this->shown);
    }

    protected function selectedRow(): int
    {
        return $this->selected === null ? -1 : ($this->rowOf[$this->selected] ?? -1);
    }

    protected function partName(): string
    {
        return 'TreeView';
    }

    /** 160 × 3..10 rows + 2 (tree_view.c:224-228), and the header. */
    protected function measureContent(Size $available): Size
    {
        $s = $this->measureRows(3, 10);

        return new Size($s->w, $s->h + $this->headerHeight());
    }

    protected function headerHeight(): float
    {
        return count($this->columns) > 1 ? $this->rowHeight() + 4 : 0.0;
    }

    /** The first column takes what the others (120 each) leave. */
    public function columnWidth(string $id): float
    {
        if ($id !== ($this->columns[0]->id ?? null)) {
            return self::DEFAULT_COLUMN;
        }
        $w = $this->bounds()->w - 2 - ($this->bar->needed() ? $this->look()->metric(Metric::ScrollbarSize) : 0.0);

        return max(self::DEFAULT_COLUMN / 2, $w - self::DEFAULT_COLUMN * (count($this->columns) - 1));
    }

    public function handle(SilkEvent $e): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }
        if (($done = $this->common($e)) !== null) {
            if ($e->type === EventType::MouseMove && $done === false) {
                return $this->maybeDrag($e);
            }

            return $done;
        }
        switch ($e->type) {
            case EventType::MouseMove:
                return $this->maybeDrag($e);
            case EventType::MouseUp:
                if ($e->button === MouseButton::LEFT && $this->press !== null && $this->pressReduce !== null) {
                    $keep = $this->pressReduce;
                    [$this->press, $this->pressReduce] = [null, null];
                    $this->select($keep, true);

                    return true;
                }
                [$this->press, $this->pressReduce] = [null, null];

                return false;
            case EventType::MouseDown:
                return $e->button === MouseButton::LEFT && $this->press($e);
            case EventType::KeyDown:
                return $this->key($e->key);
            default:
                return false;
        }
    }

    protected function paintHeader(Painter $p, Rect $r): void
    {
        if ($r->h <= 0) {
            return;
        }
        $look = $this->look();
        $pad = $look->metric(Metric::PaddingX) * 0.8;
        $separator = $this->color(Role::Separator, $look);
        $text = $this->color($this->isEnabled() ? Role::Text : Role::TextDisabled, $look);
        $p->pushClip($r);
        $p->rect($r, $this->color(Role::Header, $look));
        $x = $r->x;
        foreach ($this->columns as $c) {
            $w = $this->columnWidth($c->id);
            $this->rowText($p, $c->label, new Rect($x, $r->y, $w - $pad, $r->h), $x + $pad, $text);
            $p->rect(new Rect($x + $w - 1, $r->y + 5, 1, max(0.0, $r->h - 10)), $separator);
            $x += $w;
        }
        $p->rect(new Rect($r->x, $r->y + $r->h - 1, $r->w, 1), $separator);
        $p->popClip();
    }

    protected function paintRowBackground(Painter $p, int $i, Rect $line, int $selected): void
    {
        $look = $this->look();
        $id = $this->shown[$i];
        if ($i !== $selected && $this->multi && isset($this->marked[$id])) {
            $sel = $this->color(Role::Selection, $look);
            $this->paintRow($p, $line, $this->hasFocus ? new Color($sel->red, $sel->green, $sel->blue, $sel->alpha * 0.75)
                : self::mixColor($sel, $this->color(Role::Input, $look), 0.55));
        } else {
            parent::paintRowBackground($p, $i, $line, $selected);
        }
        if ($id === $this->dropNode) {
            $accent = $this->color(Role::Accent, $look);
            $p->rect(new Rect($line->x + 1, $line->y + 1, $line->w - 2, $line->h - 2), new Color($accent->red, $accent->green, $accent->blue, $accent->alpha * 0.18), 4.0, $accent, 1.5);
        }
    }

    protected function paintRowContent(Painter $p, int $row, Rect $line, Color $text): void
    {
        $look = $this->look();
        $node = $this->byId[$this->shown[$row]];
        $depth = $this->depths[$row];
        $indent = $look->metric(Metric::Indent);
        $x = $line->x + 3 + $indent * $depth;
        $textX = $x + $indent;
        $first = $this->columns[0]->id ?? '';
        $firstW = count($this->columns) > 1 ? $this->columnWidth($first) : $line->w;
        if ($node->children !== []) {
            $this->chevron($p, $x + $indent / 2, $line->y + $line->h / 2, isset($this->expanded[$node->id]),
                new Color($text->red, $text->green, $text->blue, $text->alpha * 0.75));
        }
        if (isset($this->icons[$node->id])) {
            $size = max(4.0, $line->h - 6);
            $p->image($this->icons[$node->id], new Rect($textX, $line->y + ($line->h - $size) / 2, $size, $size));
            $textX += $size + 5;
        }
        $this->rowText($p, $node->cells[$first] ?? '', new Rect($line->x, $line->y, $firstW, $line->h), $textX, $node->colors[$first] ?? $text);
        $x = $line->x + $firstW;
        $pad = $look->metric(Metric::PaddingX) * 0.8;
        foreach (array_slice($this->columns, 1) as $c) {
            $this->rowText($p, $node->cells[$c->id] ?? '', new Rect($x, $line->y, self::DEFAULT_COLUMN - $pad, $line->h), $x + $pad, $node->colors[$c->id] ?? $text);
            $x += self::DEFAULT_COLUMN;
        }
    }

    public function paint(Painter $p): void
    {
        parent::paint($p);
        if ($this->dropRoot) {
            $b = $this->bounds();
            $p->rect(new Rect($b->x + 2, $b->y + 2, $b->w - 4, $b->h - 4), new Color(0, 0, 0, 0), 5.0, $this->color(Role::Accent, $this->look()), 1.5);
        }
    }

    /** RVN_ICON_CHEVRON_RIGHT, or _DOWN when open: 7 units (tree_view.c:418-419). */
    private function chevron(Painter $p, float $cx, float $cy, bool $open, Color $c): void
    {
        $s = 3.5;
        $w = max(1.0, $this->look()->metric(Metric::BorderWidth) * 1.5);
        if ($open) {
            $p->line($cx - $s, $cy - $s / 2, $cx, $cy + $s / 2, $c, $w)->line($cx, $cy + $s / 2, $cx + $s, $cy - $s / 2, $c, $w);
        } else {
            $p->line($cx - $s / 2, $cy - $s, $cx + $s / 2, $cy, $c, $w)->line($cx + $s / 2, $cy, $cx - $s / 2, $cy + $s, $c, $w);
        }
    }

    /** tree_view.c:509-570 */
    private function press(SilkEvent $e): bool
    {
        $row = $this->rowAt($e->y);
        $m = $e->modifiers;
        if ($row < 0) {
            // a click in the empty space clears the selection
            if (! ($m->ctrl || $m->meta || $m->shift)) {
                [$this->marked, $this->anchor] = [[], null];
                $this->selected !== null ? $this->select(null, true) : $this->invalidatePaint();
            }

            return true;
        }
        $id = $this->shown[$row];
        $node = $this->byId[$id];
        $indent = $this->look()->metric(Metric::Indent);
        $arrow = $this->bounds()->x + 5 + $indent * $this->depths[$row];
        if ($node->children !== [] && $e->x >= $arrow && $e->x < $arrow + $indent) {
            // the arrow only opens and closes the branch
            $this->userExpand($node, ! isset($this->expanded[$id]));

            return true;
        }
        [$this->press, $this->pressReduce] = [[$e->x, $e->y], null];
        if ($this->multi && $m->shift && $this->anchor !== null && isset($this->rowOf[$this->anchor])) {
            $m->ctrl || $m->meta || $this->marked = [];
            [$a, $b] = [$this->rowOf[$this->anchor], $row];
            for ($i = min($a, $b); $i <= max($a, $b); $i++) {
                $this->marked[$this->shown[$i]] = true;
            }
            $this->primary($id, true);

            return true;
        }
        if ($this->multi && ($m->ctrl || $m->meta)) {
            if (isset($this->marked[$id])) {
                unset($this->marked[$id]);
                $other = null;
                foreach ($this->shown as $s) {
                    isset($this->marked[$s]) && $other = $s;
                }
                $this->anchor = $id;
                $this->primary($other, true);
            } else {
                $this->marked[$id] = true;
                $this->anchor = $id;
                $this->primary($id, true);
            }

            return true;
        }
        // a press on one of several keeps the others (a drag takes them all)
        if ($this->multi && isset($this->marked[$id]) && count($this->marked) > 1 && $e->clicks === 1) {
            $this->pressReduce = $id;
            $this->selected !== $id && $this->primary($id, true);

            return true;
        }
        $this->select($id, true);
        if ($e->clicks === 2 && $this->selected === $id) {
            $node->children !== [] && $this->userExpand($node, ! isset($this->expanded[$id]));
            $this->post(new NodeActivated($this->window(), $this->path(), $this->uuid(), $id));
        }

        return true;
    }

    /** A press that moves more than 5 units drags the selection (tree_view.c:460-482). */
    private function maybeDrag(SilkEvent $e): bool
    {
        if ($this->press === null || $this->dragType === null || $this->selected === null || $this->tree() === null) {
            return false;
        }
        if (abs($e->x - $this->press[0]) <= self::DRAG_START && abs($e->y - $this->press[1]) <= self::DRAG_START) {
            return true;
        }
        [$this->press, $this->pressReduce] = [null, null];
        $ids = $this->selectedNodes() ?: [$this->selected];
        $text = $this->byId[$this->selected]->cells[$this->columns[0]->id ?? ''] ?? $this->selected;
        $label = count($ids) > 1 ? sprintf('%s (+%d)', $text, count($ids) - 1) : $text;
        [$dx, $dy] = $this->tree()->shiftOf($this);
        $this->tree()->beginDrag($this, new DragPayload($label, $this->dragType, implode(' ', $ids)), $e->x + $dx, $e->y + $dy);

        return true;
    }

    /** tree_view.c:584-628 */
    private function key(?Key $key): bool
    {
        if ($this->shown === [] || $key === null) {
            return false;
        }
        $row = $this->selectedRow();
        $node = $this->selected === null ? null : $this->byId[$this->selected];
        switch ($key) {
            case Key::LEFT:
                // close the branch, or go up to the parent
                if ($node === null) {
                    return false;
                }
                if (isset($this->expanded[$node->id]) && $node->children !== []) {
                    $this->userExpand($node, false);
                } elseif (($parent = $this->parentOf[$node->id]) !== null) {
                    $this->select($parent, true);
                }

                return true;
            case Key::RIGHT:
                // open the branch, or go down to its first node
                if ($node === null || $node->children === []) {
                    return false;
                }
                isset($this->expanded[$node->id]) ? $this->select($node->children[0]->id, true) : $this->userExpand($node, true);

                return true;
            case Key::ENTER:
            case Key::NUMPAD_ENTER:
                $node && $this->post(new NodeActivated($this->window(), $this->path(), $this->uuid(), $node->id));

                return true;
        }
        $to = self::stepKey($key, $row, count($this->shown), $this->page());
        if ($to === null) {
            return false;
        }
        $this->select($this->shown[$to], true);

        return true;
    }

    /** TKTree posts NodeExpanded for the user's opening and closing; Raven moves a selection out of a closed branch silently. */
    private function userExpand(TreeNode $node, bool $open): void
    {
        $this->setExpanded($node, $open);
        $this->post(new NodeExpanded($this->window(), $this->path(), $this->uuid(), $node->id, $open));
    }

    /** tree_view.c:322-333 */
    private function setExpanded(TreeNode $node, bool $open): void
    {
        if ($node->children === [] || $open === isset($this->expanded[$node->id])) {
            return;
        }
        if ($open) {
            $this->expanded[$node->id] = true;
        } else {
            unset($this->expanded[$node->id]);
            // the selection never stays inside a branch that is being closed
            if ($this->selected !== null && $this->inside($this->selected, $node->id)) {
                $this->selected = $node->id;
            }
        }
        $this->flatten();
    }

    /** The one selection: the marks become it alone, the anchor moves, ancestors open, it is revealed. */
    private function select(?string $id, bool $notify): void
    {
        $this->marked = $id === null ? [] : [$id => true];
        $this->anchor = $id;
        $this->primary($id, $notify);
    }

    /** The primary (TK's selected) node: ancestors open, it is revealed; the user's change posts NodeSelected. */
    private function primary(?string $id, bool $notify): void
    {
        $changed = $id !== $this->selected;
        $this->selected = $id;
        if ($id !== null) {
            $opened = false;
            for ($p = $this->parentOf[$id]; $p !== null; $p = $this->parentOf[$p]) {
                if (! isset($this->expanded[$p])) {
                    $this->expanded[$p] = true;
                    $opened = true;
                }
            }
            $opened && $this->flatten();
            $this->reveal($this->rowOf[$id]);
        }
        $this->invalidatePaint();
        $changed && $notify && $this->post(new NodeSelected($this->window(), $this->path(), $this->uuid(), $id));
    }

    private function inside(string $id, string $ancestor): bool
    {
        for ($p = $this->parentOf[$id]; $p !== null; $p = $this->parentOf[$p]) {
            if ($p === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** The expanded nodes, depth first (tree_view.c:175-191). */
    private function flatten(): static
    {
        [$this->shown, $this->depths, $this->rowOf] = [[], [], []];
        $walk = function (array $list, int $depth) use (&$walk): void {
            foreach ($list as $n) {
                $this->rowOf[$n->id] = count($this->shown);
                $this->shown[] = $n->id;
                $this->depths[] = $depth;
                isset($this->expanded[$n->id]) && $walk($n->children, $depth + 1);
            }
        };
        $walk($this->nodes, 0);
        $this->hot = -1;
        $this->invalidateLayout();

        return $this->invalidatePaint();
    }

    private function node(string $id): TreeNode
    {
        return $this->byId[$id] ?? throw new WindowException("TreeView '{$this->name}' has no node '{$id}'.");
    }
}
