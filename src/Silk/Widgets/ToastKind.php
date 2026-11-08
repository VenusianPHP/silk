<?php

namespace Silk\Widgets;

use Silk\Contracts\Look\Role;

/** A message's kind (RToastKind), shown as the colour of its dot. */
enum ToastKind
{
    case Info;
    case Success;
    case Warning;
    case Danger;

    public function role(): Role
    {
        return match ($this) {
            self::Info => Role::Accent,
            self::Success => Role::Success,
            self::Warning => Role::Warning,
            self::Danger => Role::Danger,
        };
    }
}
