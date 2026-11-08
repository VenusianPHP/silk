<?php

namespace Silk\Widgets;

/** How a Toast takes a new message (RToastQueue): it replaces the one shown, waits its turn, or stacks up. */
enum ToastQueue
{
    case Replace;
    case Sequence;
    case Stack;
}
