<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The entry under a FileBrowser's cursor changed; null: none (Raven Rfile_browser_set_selection_changed_callback). */
readonly class FileSelectionChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public ?string $file)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.file-selection-changed.{$this->window}.{$this->path}";
    }
}
