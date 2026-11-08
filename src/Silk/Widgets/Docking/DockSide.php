<?php

namespace Silk\Widgets\Docking;

/** Raven's RDockSide (raven_shell.h): beside a node, or behind its tabs. */
enum DockSide
{
    case Left;
    case Right;
    case Top;
    case Bottom;
    case Tab;
}
