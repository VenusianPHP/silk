<?php

namespace Silk\Core\Input;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Widget\Container;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Overlay\Popup;
use Silk\Core\Tree\Tree;

/** Keyboard focus over a tree: Tab order is tree order; the ring shows only after Tab. */
class FocusManager
{
    private ?Widget $focused = null;

    private bool $ring = false;

    /** @var list<array{Popup, ?Widget}> open popups and the focus each saved */
    private array $scopes = [];

    public function __construct(private readonly Tree $tree)
    {
        $tree->overlays()->onChange(fn (Popup $p, bool $open) => $open ? $this->enter($p) : $this->leave($p));
    }

    /** A popup opened: Tab stays inside it; focus moves to its first focusable widget. */
    private function enter(Popup $popup): void
    {
        $this->scopes = array_values(array_filter($this->scopes, fn ($s) => $s[0] !== $popup));
        $this->scopes[] = [$popup, $this->focused];
        $first = $this->step(null, false);
        $this->focus($first);
    }

    /** A popup closed: focus goes back to what it saved, when that can still take it. */
    private function leave(Popup $popup): void
    {
        foreach ($this->scopes as $i => [$p, $saved]) {
            if ($p === $popup) {
                array_splice($this->scopes, $i, 1);
                $this->focus(null);
                if ($saved !== null && $this->canFocus($saved)) {
                    $this->focus($saved);
                }

                return;
            }
        }
    }

    /** Tab walks this: the top popup, else the tree's root. */
    private function scopeRoot(): Widget
    {
        return $this->scopes === [] ? $this->tree->root : $this->scopes[array_key_last($this->scopes)][0];
    }

    public function focused(): ?Widget
    {
        return $this->focused;
    }

    public function showRing(): bool
    {
        return $this->ring;
    }

    public function hideRing(): void
    {
        if ($this->ring) {
            $this->ring = false;
            $this->focused?->invalidatePaint();
        }
    }

    /** Moves focus, sending FocusLost then FocusGained. Null clears it. */
    public function focus(?Widget $w): void
    {
        if ($w === $this->focused || ($w !== null && ! $this->canFocus($w))) {
            return;
        }
        $old = $this->focused;
        $this->focused = $w;
        $old?->handle(new SilkEvent(EventType::FocusLost));
        $old?->invalidatePaint();
        $w?->handle(new SilkEvent(EventType::FocusGained, focusVisible: $this->ring));
        $w?->invalidatePaint();
    }

    /** Tab / Shift-Tab: the next focusable widget in tree order, wrapping. Shows the ring. */
    public function next(bool $backwards = false): void
    {
        $this->ring = true;
        $this->focus($this->step($this->focused, $backwards));
        $this->focused?->invalidatePaint();
    }

    /** A focused widget that became disabled, hidden or detached hands focus on. */
    public function settle(): void
    {
        if ($this->focused !== null && ! $this->canFocus($this->focused)) {
            $lost = $this->focused;
            $this->focused = null;
            $this->focus($this->step($lost, false));
        }
    }

    /** The first focusable widget after (or before) $from in tree order; from the start when $from is null or gone. */
    private function step(?Widget $from, bool $backwards): ?Widget
    {
        $all = $this->order($this->scopeRoot());
        if ($backwards) {
            $all = array_reverse($all);
        }
        $at = $from === null ? false : array_search($from, $all, true);
        $n = count($all);
        for ($i = 1; $i <= $n; $i++) {
            $candidate = $all[(($at === false ? -1 : $at) + $i) % $n];
            if ($candidate->isTabStop() && $this->canFocus($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<Widget> every widget in tree order */
    private function order(Widget $w): array
    {
        $out = [$w];
        if ($w instanceof Container) {
            foreach ($w->children() as $child) {
                array_push($out, ...$this->order($child));
            }
        }

        return $out;
    }

    /** Focusable, and it and every ancestor is visible and enabled, up to this tree's root. */
    public function canFocus(Widget $w): bool
    {
        if (! $w->isFocusable()) {
            return false;
        }
        for ($at = $w; $at !== null; $at = $at->parent()) {
            if (! $at->isVisible() || ! $at->isEnabled()) {
                return false;
            }
            if ($at === $this->scopeRoot()) {
                return true;
            }
        }

        return false;
    }
}
