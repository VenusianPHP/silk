<?php

namespace Silk\Widgets\ColorPicking;

use Silk\Widgets\Button;
use Silk\Widgets\ColorPicker;

/** Switches a ColorPicker's numbers between RGB and HSV (color_picker.c:320-327). */
final class ModeButton extends Button
{
    public function __construct(string $name, private readonly ColorPicker $picker)
    {
        parent::__construct($name, 'RGB');
        $this->setTooltip('Numbers as red, green, blue or as hue, saturation, value');
    }

    protected function activate(): void
    {
        $this->picker->toggleNumbers();
    }
}
