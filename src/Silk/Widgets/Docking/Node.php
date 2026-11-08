<?php

namespace Silk\Widgets\Docking;

use Silk\Contracts\Geometry\Rect;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/**
 * A node of a DockArea's tree (Raven rvn_dock_node_t, dock_area.c:41-64): a split (two nodes side by side or one above
 * the other, and the share of the first) or a group (panels sharing a bar of tabs, one shown). A group is drawn in a
 * rectangle of its own that runs after the tree's on four SNAPPY springs.
 */
final class Node
{
    public ?Node $parent = null;

    public ?Node $a = null;

    public ?Node $b = null;

    /** True: a is above b; false: a is left of b. */
    public bool $vertical = false;

    /** The share of a, 0..1. */
    public float $ratio = 0.5;

    /** @var list<string> a group's panels, by name */
    public array $panels = [];

    public int $active = 0;

    /** Where the tree puts the node. */
    public Rect $rect;

    public Spring $x;

    public Spring $y;

    public Spring $w;

    public Spring $h;

    public bool $placed = false;

    /** The mark of the shown tab, relative to the group: it slides from tab to tab. */
    public Spring $markX;

    public Spring $markW;

    public function __construct()
    {
        $this->rect = new Rect(0, 0, 0, 0);
        [$this->x, $this->y, $this->w, $this->h] = [new Spring(0.0, SpringPreset::Snappy), new Spring(0.0, SpringPreset::Snappy),
            new Spring(0.0, SpringPreset::Snappy), new Spring(0.0, SpringPreset::Snappy)];
        [$this->markX, $this->markW] = [new Spring(0.0, SpringPreset::Bouncy), new Spring(0.0, SpringPreset::Bouncy)];
    }

    public function isSplit(): bool
    {
        return $this->a !== null;
    }

    /** The group holding a panel, and its index; or null. @return array{?Node, int} */
    public function find(string $panel): array
    {
        if ($this->isSplit()) {
            $found = $this->a->find($panel);

            return $found[0] !== null ? $found : $this->b->find($panel);
        }
        $at = array_search($panel, $this->panels, true);

        return $at === false ? [null, -1] : [$this, $at];
    }

    public function firstGroup(): Node
    {
        $n = $this;
        while ($n->isSplit()) {
            $n = $n->a;
        }

        return $n;
    }

    /** @return list<Node> the groups, a before b */
    public function groups(): array
    {
        return $this->isSplit() ? [...$this->a->groups(), ...$this->b->groups()] : [$this];
    }

    /** @return list<Node> every node, a before b, a split before its parts */
    public function nodes(): array
    {
        return $this->isSplit() ? [$this, ...$this->a->nodes(), ...$this->b->nodes()] : [$this];
    }

    /** Where the group is drawn now (dock_area.c:347-350). */
    public function shown(): Rect
    {
        return $this->placed ? new Rect($this->x->value(), $this->y->value(), max(0.0, $this->w->value()), max(0.0, $this->h->value())) : $this->rect;
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->x, $this->y, $this->w, $this->h, $this->markX, $this->markW];
    }

    /** Moves the drawn rectangle toward the tree's; at once when $snap or not placed yet (dock_area.c:353-385). */
    public function chase(bool $snap): void
    {
        $targets = [$this->rect->x, $this->rect->y, $this->rect->w, $this->rect->h];
        foreach ([$this->x, $this->y, $this->w, $this->h] as $i => $s) {
            $snap || ! $this->placed ? $s->snap($targets[$i]) : $s->target($targets[$i]);
        }
        $this->placed = true;
    }

    /** Starts the drawn rectangle at $r (a new group grows from where the preview of the drop was). */
    public function placeAt(Rect $r): void
    {
        $this->x->snap($r->x);
        $this->y->snap($r->y);
        $this->w->snap($r->w);
        $this->h->snap($r->h);
        $this->placed = true;
    }
}
