<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A documents tab was closed by its close button or a middle click, and its page removed (Raven tab_view.c:162-173). */
readonly class TabClosed extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public string $page)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.tab-closed.{$this->window}.{$this->path}";
    }
}
