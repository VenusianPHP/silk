<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** An Island unfolded or folded by the user, the pointer or its time (Raven RIslandChangedCallback, raven_shell.h:333-335). */
readonly class IslandChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public bool $expanded)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.island-changed.{$this->window}.{$this->path}";
    }
}
