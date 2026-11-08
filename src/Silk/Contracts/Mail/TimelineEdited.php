<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A Timeline gesture, a mute or a lock ended with a change: one for the whole gesture (Raven fires the widget's action). */
readonly class TimelineEdited extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.timeline-edited.{$this->window}.{$this->path}";
    }
}
