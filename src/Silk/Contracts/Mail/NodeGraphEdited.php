<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A NodeGraph gesture ended with a change to the graph: a link made or taken away, nodes moved or removed (Raven fires the widget's action). */
readonly class NodeGraphEdited extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.node-graph-edited.{$this->window}.{$this->path}";
    }
}
