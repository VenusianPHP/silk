<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** A Timeline's selection changed; item 0: several or none (Raven Rtimeline_set_selection_callback). */
readonly class TimelineSelectionChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $item)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.timeline-selection.{$this->window}.{$this->path}";
    }
}
