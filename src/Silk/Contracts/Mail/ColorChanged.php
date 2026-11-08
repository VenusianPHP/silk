<?php

namespace Silk\Contracts\Mail;

use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\PrimitiveMail;
use Surface\NutsAndBolts\Color;

/** The user changed a ColorPicker's colour (every step of a drag, as Raven). */
readonly class ColorChanged extends PrimitiveEventOccurred implements PrimitiveMail
{
    public function __construct(string $window, string $path, string $uuid, public Color $color)
    {
        parent::__construct($window, $path, $uuid);
    }

    public function name(): string
    {
        return "view.color-changed.{$this->window}.{$this->path}";
    }
}
