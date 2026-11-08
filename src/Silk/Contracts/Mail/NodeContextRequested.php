<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A right click on a node (selected first) or, $node 0, on the empty canvas; $x, $y in the widget, from its corner (Raven Rnode_graph_set_context_callback). */
readonly class NodeContextRequested extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $node, public float $x, public float $y)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-context-requested.{$this->window}.{$this->path}";
    }
}
