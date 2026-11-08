<?php

namespace Silk\Widgets\ColorPicking;

use Silk\Widgets\ColorPicker;
use Silk\Widgets\SpinBox;
use Surface\Contracts\Windows\Mail\View\ValueChanged;

/** One of a ColorPicker's four numbers: its changes go to the picker, not to the app (color_picker.c:296-318). */
final class NumberField extends SpinBox
{
    public function __construct(string $name, public readonly int $index, private readonly ColorPicker $picker)
    {
        parent::__construct($name, 0.0, 0.0, 255.0, 1.0, 0);
    }

    protected function post(object $mail): void
    {
        $mail instanceof ValueChanged ? $this->picker->fieldChanged($this, $mail->value) : parent::post($mail);
    }
}
