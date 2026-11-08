<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;

/** The user changed a TimePicker; $second is null when it shows HH:MM. */
readonly class TimeChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public int $hour, public int $minute, public ?int $second)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.time-changed.{$this->window}.{$this->path}";
    }
}
