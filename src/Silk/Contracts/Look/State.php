<?php

namespace Silk\Contracts\Look;

/** The interaction state a part look is resolved for. */
enum State
{
    case Normal;
    case Hover;
    case Pressed;
    case Focused;
    case Checked;
    case Disabled;
    /** A chosen row (Raven RVN_STATE_SELECTED). */
    case Selected;
}
