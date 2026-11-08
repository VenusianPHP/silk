<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user changed the scripts: moved, attached, took out, deleted or typed into a block. $block is the block (0: several, or one was deleted). */
readonly class BlockEditorChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $block)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.block-changed.{$this->window}.{$this->path}";
    }
}
