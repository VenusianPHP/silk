<?php

namespace Silk\Contracts\Look;

/** What a Raven look's surfaces are made of (RMaterial): solid paint, or glass in one of three strengths (common.c:105-126). */
enum Material: string
{
    case Solid = 'solid';

    /** Clear: barely tinted, barely blurred, a rim that bends like a lens. */
    case Glass = 'glass';

    case FrostedGlass = 'frosted';

    /** Frosted, and coloured by the accent, stronger on what floats higher. */
    case TintedGlass = 'tinted';

    public function isGlass(): bool
    {
        return $this !== self::Solid;
    }
}
