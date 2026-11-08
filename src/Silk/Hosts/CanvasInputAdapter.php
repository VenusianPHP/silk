<?php

namespace Silk\Hosts;

/** TKCanvas view mail (CanvasPressed, KeyPressed, TextCommitted, FocusGained, ...) to SilkEvent. */
class CanvasInputAdapter extends InputAdapter
{
    protected function takes(object $mail): ?string
    {
        return self::verb($mail, 'Surface\Contracts\Windows\Mail\View', 'Canvas');
    }
}
