<?php

namespace Silk\Contracts\Look;

/** Which family a look's text uses: the UI face or the monospace one. */
enum FontRole
{
    case Ui;
    case Mono;
}
