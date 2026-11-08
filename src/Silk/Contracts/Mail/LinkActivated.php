<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A link of a Markdown label was clicked (Raven RLabelLinkCallback, label.c:394-409). */
readonly class LinkActivated extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public string $url)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.link-activated.{$this->window}.{$this->path}";
    }
}
