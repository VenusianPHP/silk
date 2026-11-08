<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user changed a TileView's selection: the selected tiles, ascending, and the current one (-1: none). */
readonly class TilesSelected extends PrimitiveEventOccurred implements PrimitiveMail
{
    /** @param list<int> $tiles */
    public function __construct(string $window, string $path, string $uuid, public array $tiles, public int $current = -1)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.tiles-selected.{$this->window}.{$this->path}";
    }
}
