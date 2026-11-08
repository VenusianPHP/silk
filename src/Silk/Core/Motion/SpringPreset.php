<?php

namespace Silk\Core\Motion;

/** Raven's four motions (anim.c): period without friction, in seconds, and damping ratio. */
enum SpringPreset
{
    case Snappy;
    case Smooth;
    case Bouncy;
    case Wobbly;

    public function period(): float
    {
        return match ($this) {
            self::Snappy => 0.16,
            self::Smooth => 0.30,
            self::Bouncy => 0.38,
            self::Wobbly => 0.50,
        };
    }

    public function damping(): float
    {
        return match ($this) {
            self::Snappy => 0.90,
            self::Smooth => 1.00,
            self::Bouncy => 0.52,
            self::Wobbly => 0.32,
        };
    }
}
