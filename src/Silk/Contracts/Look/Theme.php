<?php

namespace Silk\Contracts\Look;

/** A theme as a class: returns the same array a theme closure would. */
interface Theme
{
    /** @return array{look?: ?string, accent?: \Surface\NutsAndBolts\Color, colors?: array, metrics?: array, font?: array, motion?: string, material?: string, syntax?: array, styles?: list<array>} */
    public function toArray(): array;
}
