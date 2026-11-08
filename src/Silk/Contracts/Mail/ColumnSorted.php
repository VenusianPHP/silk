<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user sorted a table by $column (a column id). */
readonly class ColumnSorted extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public string $column, public bool $ascending)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.column-sorted.{$this->window}.{$this->path}";
    }
}
