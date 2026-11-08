<?php

namespace Silk\Core\Layout;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Widget as WidgetContract;
use Silk\Core\Motion\Transition;
use Silk\Core\Tree\Container;

/**
 * Raven's Switcher: shows one of its pages. The others are not laid out, painted, hit or focused: to the
 * tree its only child is the active page. A transition, when set, plays as a page comes in.
 */
class Switcher extends Container
{
    private ?Transition $transition = null;

    public function __construct(string $name, private int $index = 0)
    {
        parent::__construct($name);
    }

    /** @return list<WidgetContract> every page, shown or not */
    public function pages(): array
    {
        return parent::children();
    }

    /** The tree sees the active page only. */
    public function children(): array
    {
        $active = $this->active();

        return $active === null ? [] : [$active];
    }

    public function index(): int
    {
        return $this->index;
    }

    public function setIndex(int $index): static
    {
        if ($index === $this->index) {
            return $this;
        }
        $this->index = $index;
        $page = $this->active();
        if ($page !== null && $this->transition !== null && $page->isVisible()) {
            $page->appear($this->transition);
            $page->setVisible(false)->setVisible(true);
        }

        return $this->invalidateLayout();
    }

    public function setTransition(?Transition $transition): static
    {
        $this->transition = $transition;

        return $this;
    }

    public function active(): ?WidgetContract
    {
        return parent::children()[$this->index] ?? null;
    }

    protected function visibleChildren(): array
    {
        $active = $this->active();

        return $active !== null && $active->isVisible() ? [$active] : [];
    }

    protected function measureContent(Size $available): Size
    {
        return ($this->visibleChildren()[0] ?? null)?->measure($available) ?? new Size(0, 0);
    }

    protected function arrangeContent(Rect $r): void
    {
        ($this->visibleChildren()[0] ?? null)?->arrange($r);
    }

    public function markPainted(): void
    {
        parent::markPainted();
        foreach (parent::children() as $page) {
            $page->markPainted();
        }
    }
}
