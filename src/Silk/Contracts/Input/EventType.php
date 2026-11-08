<?php

namespace Silk\Contracts\Input;

enum EventType
{
    case MouseMove;
    case MouseDown;
    case MouseUp;
    case Wheel;
    case Enter;
    case Leave;
    case KeyDown;
    case KeyUp;
    case Text;
    /** A platform editing binding (EditCommanded): the event's command says which. */
    case Edit;
    case FocusGained;
    case FocusLost;

    public function isPointer(): bool
    {
        return in_array($this, [self::MouseMove, self::MouseDown, self::MouseUp, self::Wheel], true);
    }

    public function isKeyboard(): bool
    {
        return in_array($this, [self::KeyDown, self::KeyUp, self::Text, self::Edit], true);
    }
}
