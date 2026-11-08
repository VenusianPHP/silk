<?php

namespace Silk\Contracts\Host;

/** What a HeaderBar's caption buttons ask of the window. */
enum WindowAction: string
{
    case Minimize = 'minimize';
    case Maximize = 'maximize';
    case Close = 'close';
}
