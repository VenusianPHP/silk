<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user moved or stretched a Timeline item (Raven Rtimeline_set_changed_callback). */
readonly class TimelineItemChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $item)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.timeline-item-changed.{$this->window}.{$this->path}";
    }
}
