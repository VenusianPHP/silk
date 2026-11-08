<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user cancelled a FileBrowser (Raven Rfile_browser_set_cancelled_callback). */
readonly class FileCancelled extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.file-cancelled.{$this->window}.{$this->path}";
    }
}
