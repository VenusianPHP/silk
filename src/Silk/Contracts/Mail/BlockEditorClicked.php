<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user clicked a script (to run it): $block is its top block. */
readonly class BlockEditorClicked extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $block)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.block-clicked.{$this->window}.{$this->path}";
    }
}
