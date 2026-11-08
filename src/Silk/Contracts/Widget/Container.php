<?php

namespace Silk\Contracts\Widget;


interface Container extends Widget
{
    public function add(Widget $child, ?Slot $slot = null): static;

    public function remove(Widget $child): static;

    /** How far children are drawn from where they are laid out (a ScrollBox's offset): [dx, dy]. @return array{float, float} */
    public function childShift(): array;

    /**
     * How children are drawn from where they are laid out, in full: a point L of a child shows at s * L + (tx, ty)
     * (Raven's child_scale with the shift: a NodeGraph's widgets show at its zoom). @return array{float, float, float} s, tx, ty
     */
    public function childTransform(): array;

    /** Whether $child may take the pointer at ($x, $y), in this container's space (Raven's child_at hook). */
    public function childTakesPointer(Widget $child, float $x, float $y): bool;

    /** @return list<Widget> in tree order (paint order; the last is top-most) */
    public function children(): array;
}
