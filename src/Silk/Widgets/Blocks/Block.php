<?php

namespace Silk\Widgets\Blocks;

/** One block of a script: its place, what hangs from it, the values of its slots. */
final class Block
{
    /** The block it hangs from, 0: the top of a script of its own (at $x, $y). */
    public int $parent = 0;

    public BlockPlace $place = BlockPlace::Next;

    public int $index = 0;

    /** The step under it. */
    public int $next = 0;

    /** @var array<int, int> the first block in each mouth */
    public array $body = [];

    /** @var array<int, int> the value block in each slot */
    public array $input = [];

    /** @var array<int, string> the typed value of each slot */
    public array $values = [];

    public float $x = 0.0;

    public float $y = 0.0;

    /** The block is running: a halo breathes around it. */
    public bool $glow = false;

    /** The user cannot delete it (the entry of a function). Not saved. */
    public bool $locked = false;

    public bool $selected = false;

    /** Where and how big the layout put it (canvas units; palette units for a palette block). */
    public float $lx = 0.0;

    public float $ly = 0.0;

    public float $w = 0.0;

    public float $h = 0.0;

    /** @var array<int, float> the layout of its rows, mouths and slots */
    public array $rowY = [];

    public array $rowH = [];

    public array $mouthY = [];

    public array $mouthH = [];

    public array $slotX = [];

    public array $slotY = [];

    public array $slotW = [];

    public array $slotH = [];

    public function __construct(public readonly int $id, public readonly string $kind) {}
}
