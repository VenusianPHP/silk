<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A row was activated by the user: Enter, or a double click. $row is the row's index in the widget's own data. */
readonly class RowActivated extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $row)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.row-activated.{$this->window}.{$this->path}";
    }
}
