<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user deleted a node with the Delete key (Raven Rnode_graph_set_removed_callback); the node is gone, its links went first (NodeUnlinked each). */
readonly class NodeRemoved extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $node, public string $title)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-removed.{$this->window}.{$this->path}";
    }
}
