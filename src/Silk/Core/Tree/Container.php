<?php

namespace Silk\Core\Tree;

use Silk\Contracts\Painter\Painter;
use Silk\Contracts\Widget\Container as ContainerContract;
use Silk\Contracts\Widget\Slot;
use Silk\Contracts\Widget\Widget as WidgetContract;

/** A widget with children, each in a slot. */
abstract class Container extends Widget implements ContainerContract
{
    /** @var list<WidgetContract> */
    private array $children = [];

    /** @var array<string, Slot> keyed by child uuid */
    private array $slots = [];

    public function add(WidgetContract $child, ?Slot $slot = null): static
    {
        $child->parent()?->remove($child);
        $child->setParent($this);
        $this->children[] = $child;
        $this->slots[$child->uuid()] = $slot ?? new Slot;

        return $this->invalidateLayout();
    }

    public function remove(WidgetContract $child): static
    {
        $at = array_search($child, $this->children, true);
        if ($at !== false) {
            // the tree clears where it was
            $child instanceof Widget && $child->lastPainted() !== null && $this->tree()?->addDamage($child->lastPainted());
            array_splice($this->children, $at, 1);
            unset($this->slots[$child->uuid()]);
            $child->setParent(null);
            $this->invalidateLayout();
        }

        return $this;
    }

    public function childShift(): array
    {
        return [0.0, 0.0];
    }

    public function childTransform(): array
    {
        return [1.0, ...$this->childShift()];
    }

    public function childTakesPointer(WidgetContract $child, float $x, float $y): bool
    {
        return true;
    }

    /**
     * Mail a descendant is about to post: true takes it (it is not posted). Raven's composites (the file
     * browser and its fields) hear their children this way; by default nothing is taken.
     */
    public function childMail(WidgetContract $from, object $mail): bool
    {
        return false;
    }

    public function children(): array
    {
        return $this->children;
    }

    /** @return list<WidgetContract> the children that take part in layout and paint */
    protected function visibleChildren(): array
    {
        return array_values(array_filter($this->children, fn (WidgetContract $c) => $c->isVisible()));
    }

    protected function slot(WidgetContract $child): Slot
    {
        return $this->slots[$child->uuid()];
    }

    /** Paints itself, then the visible children in tree order. */
    public function paint(Painter $p): void
    {
        $this->paintSelf($p);
        foreach ($this->visibleChildren() as $child) {
            $child->render($p);
        }
    }

    protected function paintSelf(Painter $p): void {}

    public function markPainted(): void
    {
        parent::markPainted();
        foreach ($this->children as $child) {
            $child->markPainted();
        }
    }
}
