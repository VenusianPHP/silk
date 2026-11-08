<?php

namespace Silk\Widgets\Nodes;

use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/** A link from an output to an input (Raven rvn_ng_link_t, node_graph.c:131-143): a wire whose middle swings. */
final class Link
{
    public bool $selected = false;

    public Spring $hover;

    /** 0 -> 1 while it is drawn in, from the output to the input. */
    public Spring $grow;

    /** The swing of its middle. */
    public Spring $swayX;

    public Spring $swayY;

    public ?array $last = null;

    public function __construct(public readonly int $id, public readonly int $from, public readonly int $to)
    {
        $this->hover = new Spring(0.0, SpringPreset::Snappy);
        $this->grow = new Spring(0.0, SpringPreset::Smooth);
        $this->swayX = new Spring(0.0, SpringPreset::Wobbly);
        $this->swayY = new Spring(0.0, SpringPreset::Wobbly);
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->hover, $this->grow, $this->swayX, $this->swayY];
    }
}
