<?php

namespace Silk\Core\Drag;

/** Raven's RDropEvent phases (raven_window.h:251-283). */
enum DropPhase
{
    case Enter;
    case Move;
    case Leave;
    case Dropped;
}
