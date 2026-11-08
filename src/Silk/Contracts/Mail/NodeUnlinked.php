<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user took a link away (Raven Rnode_graph_set_unlinked_callback); the link is gone, so its pins come with the mail. */
readonly class NodeUnlinked extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $link, public int $output, public int $input)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-unlinked.{$this->window}.{$this->path}";
    }
}
