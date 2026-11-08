<?php

namespace Silk\Widgets\Blocks;

/** What a block looks like and where it may go (the six shapes of a Scratch-style editor). */
enum BlockShape: string
{
    /** A step: snaps under another one, others snap under it. */
    case Statement = 'statement';
    /** Starts a script ("when the game starts"): nothing goes above it. */
    case Hat = 'hat';
    /** Ends a script ("stop"): nothing goes under it. */
    case Cap = 'cap';
    /** Wraps stacks in its mouths ("repeat", "if ... else"). */
    case C = 'c';
    /** A rounded value that goes into a slot. */
    case Reporter = 'reporter';
    /** A hexagonal condition that goes into a %b slot (or a %r one). */
    case Boolean = 'boolean';

    public function isValue(): bool
    {
        return $this === self::Reporter || $this === self::Boolean;
    }

    /** Whether another step may hang under it. */
    public function hasNext(): bool
    {
        return $this === self::Statement || $this === self::Hat || $this === self::C;
    }

    /** Whether it has a notch on top, for a step above it. */
    public function hasTop(): bool
    {
        return $this === self::Statement || $this === self::Cap || $this === self::C;
    }
}
