<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user moved, resized, showed or closed a panel of a DockArea (Raven RDockChangedCallback, raven_shell.h:284-285). */
readonly class DockChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function name(): string
    {
        return "view.dock-changed.{$this->window}.{$this->path}";
    }
}
