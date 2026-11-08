<?php

namespace Silk\Widgets\Nodes;

use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Looks\Canvas\PartOverrides;

/** A pin of a node (Raven rvn_ng_pin_t, node_graph.c:73-94): an input on its left or an output on its right. */
final class Pin
{
    public ?string $name = null;

    /** Set by the geometry: the middle of its dot, from the corner of its node. */
    public float $x = 0.0;

    public float $y = 0.0;

    public float $labelWidth = 0.0;

    /** Set while painting: a link ends here. */
    public bool $linked = false;

    /** How much it stands out: under the pointer, or where the wire being pulled could go. */
    public Spring $hot;

    /** A ring that leaves the pin when a link is made. */
    public Spring $pulse;

    /** An input that takes several links (setPinMultiple). */
    public bool $multiple = false;

    /** Given to this pin (part Pin) and to the links that leave it (part Link). */
    public PartOverrides $style;

    /** $type: the graph's type name, or null for a pin that links to any type. */
    public function __construct(public readonly int $id, public readonly int $node, public readonly bool $output, public string $label, public readonly ?string $type)
    {
        $this->hot = new Spring(0.0, SpringPreset::Bouncy);
        $this->pulse = new Spring(0.0, SpringPreset::Smooth);
        $this->style = new PartOverrides;
    }

    /** May a wire go between these two pins (rvn_ng_can_link, node_graph.c:325-329)? */
    public function canLink(?self $b): bool
    {
        if ($b === null || $b === $this || $this->output === $b->output || $this->node === $b->node) {
            return false;
        }

        return $this->type === null || $b->type === null || $this->type === $b->type;
    }
}
