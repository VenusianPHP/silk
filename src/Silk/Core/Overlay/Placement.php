<?php

namespace Silk\Core\Overlay;

/** Where a popup sits against its anchor. */
enum Placement
{
    case Below;
    case Above;
    case Right;
    case Left;
    case AtPoint;
}
