<?php

namespace Silk\Widgets;

/** What a Timeline item is (Raven RTimelineItem, raven_tools.h:171-176). */
enum TimelineItem
{
    case Clip;
    case Key;
    case Marker;
}
