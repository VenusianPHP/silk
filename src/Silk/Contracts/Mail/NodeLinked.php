<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user linked an output to an input (Raven Rnode_graph_set_linked_callback); $link is the new link. */
readonly class NodeLinked extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $link, public int $output, public int $input)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-linked.{$this->window}.{$this->path}";
    }
}
