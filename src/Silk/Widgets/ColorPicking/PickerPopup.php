<?php

namespace Silk\Widgets\ColorPicking;

use Silk\Contracts\Look\PartLook;
use Silk\Core\Overlay\Popup;

/** The popup a popup-mode ColorPicker opens: a surface of the theme, as a menu is, around the second picker (color_picker.c:415-450). */
final class PickerPopup extends Popup
{
    protected function surface(): PartLook
    {
        return $this->look()->part('Popup', 'Body', \Silk\Contracts\Look\State::Normal);
    }
}
