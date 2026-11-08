<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user deleted a Timeline item (Raven Rtimeline_set_removed_callback). */
readonly class TimelineItemRemoved extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $item)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.timeline-item-removed.{$this->window}.{$this->path}";
    }
}
