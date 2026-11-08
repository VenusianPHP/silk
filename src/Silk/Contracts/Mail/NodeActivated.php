<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user double-clicked a node, to open what it stands for (Raven Rnode_graph_set_activated_callback). */
readonly class NodeActivated extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $node)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-activated.{$this->window}.{$this->path}";
    }
}
