<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user changed the NodeGraph selection (Raven Rnode_graph_set_selection_callback); $node is the node just clicked, or 0. */
readonly class NodeSelectionChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $node)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-selection-changed.{$this->window}.{$this->path}";
    }
}
