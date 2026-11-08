<?php

namespace Silk\Contracts\Look;

/** Raven's 36 colour roles (raven_style.h:165-218). */
enum Role
{
    case Window;
    case Panel;
    case Border;
    case Text;
    case TextDisabled;
    case TextPlaceholder;
    case Accent;
    case AccentHovered;
    case AccentPressed;
    case AccentText;
    case Control;
    case ControlHovered;
    case ControlPressed;
    case ControlBorder;
    case Input;
    case Focus;
    case Selection;
    case RowHovered;
    case Track;
    case Handle;
    case HandleHovered;
    case Separator;
    case Header;
    case HeaderHovered;
    case TabActive;
    case Popup;
    case PopupBorder;
    case Shadow;
    case Tooltip;
    case TooltipText;
    case Switch;
    case Success;
    case Warning;
    case Danger;
    case Link;
    case Code;
}
