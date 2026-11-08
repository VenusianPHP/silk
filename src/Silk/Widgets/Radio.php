<?php

namespace Silk\Widgets;

/**
 * `radio(name, label, checked)`: the checkbox's radio style (Raven R_CHECKBOX_RADIO, checkbox.c). Round, with a dot;
 * the radios of one parent form a group where one at most is on. Posts Toggled for the one turned on and the one turned off.
 */
class Radio extends Checkbox
{
    public function isRadio(): bool
    {
        return true;
    }
}
