<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user right-clicked: $block is the block under the pointer (0: the canvas) at $x, $y in the widget. */
readonly class BlockEditorContext extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $block, public float $x, public float $y)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.block-context.{$this->window}.{$this->path}";
    }
}
