<?php

namespace Silk\Widgets;

/** How a TileView lays its tiles out (Layout="Tiles" or "Rows"): a grid of tiles, or one item a line as wide as the view, icon on the left. */
enum TileMode
{
    case Icons;
    case Rows;
}
