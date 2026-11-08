<?php

namespace Silk\Painter;

/** The marks of pins, keys and points (Raven widget.h:510-519, the ones rvn_paint_mark draws). */
enum MarkShape
{
    case Circle;
    case Ring;
    case Square;
    case Diamond;
    case Triangle;
    /** A tab with a point to the right: the pins that carry the flow of execution. */
    case Arrow;
    case None;
}
