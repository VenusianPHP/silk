<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user chose a file or a folder in a FileBrowser (Raven Rfile_browser_set_accepted_callback). */
readonly class FileAccepted extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public string $file)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.file-accepted.{$this->window}.{$this->path}";
    }
}
