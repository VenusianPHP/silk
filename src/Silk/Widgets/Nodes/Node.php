<?php

namespace Silk\Widgets\Nodes;

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Widget\Widget;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Looks\Canvas\PartOverrides;
use Surface\NutsAndBolts\Color;

/** A node of a NodeGraph (Raven rvn_ng_node_t, node_graph.c:96-129): a title, pins, perhaps a widget inside. */
final class Node
{
    public ?string $name = null;

    /** Set by the geometry: its size on the canvas, and where its content goes (from its corner). */
    public float $w = 0.0;

    public float $h = 0.0;

    public Rect $contentRect;

    /** The header's colour; null: the theme's accent. */
    public ?Color $color = null;

    public ?Widget $content = null;

    public mixed $user = null;

    public bool $selected = false;

    /** Where it was, and whether it was selected, when the gesture started. */
    public float $fromX = 0.0;

    public float $fromY = 0.0;

    public bool $fromSelected = false;

    public Spring $lift;

    public Spring $appear;

    public Spring $glow;

    /** Running (setNodeActive): a ring breathes around it. */
    public bool $active = false;

    public ?Color $activeColor = null;

    public Spring $activeShown;

    /** A short text in a pill on its top right corner, or null. */
    public ?string $badge = null;

    public Spring $badgeShown;

    /** The Delete key leaves it (setNodeDeletable(false)). */
    public bool $undeletable = false;

    /** Given to this node: its parts Node and Header. */
    public PartOverrides $style;

    /** An image before its title (the Icon style), or null. */
    public ?\Surface\Contracts\Framebuffers\Framebuffer $icon = null;

    public function __construct(public readonly int $id, public string $title, public float $x, public float $y)
    {
        $this->contentRect = new Rect(0, 0, 0, 0);
        $this->lift = new Spring(0.0, SpringPreset::Snappy);
        $this->appear = new Spring(0.0, SpringPreset::Bouncy);
        $this->glow = new Spring(0.0, SpringPreset::Snappy);
        $this->activeShown = new Spring(0.0, SpringPreset::Smooth);
        $this->badgeShown = new Spring(0.0, SpringPreset::Bouncy);
        $this->style = new PartOverrides;
    }

    /** @return list<Spring> */
    public function springs(): array
    {
        return [$this->lift, $this->appear, $this->glow, $this->activeShown, $this->badgeShown];
    }
}
