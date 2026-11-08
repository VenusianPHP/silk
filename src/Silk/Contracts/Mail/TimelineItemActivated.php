<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A Timeline item was double clicked (Raven Rtimeline_set_activated_callback). */
readonly class TimelineItemActivated extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $item)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.timeline-item-activated.{$this->window}.{$this->path}";
    }
}
