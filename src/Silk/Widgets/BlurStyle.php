<?php

namespace Silk\Widgets;

/** Raven's RBlurStyle: how a BackgroundBlur looks (background_blur.c:158-170). */
enum BlurStyle: string
{
    /** The backdrop out of focus, nothing else unless a tint is set. */
    case Plain = 'plain';

    /** Out of focus, strengthened, grained, under a veil of the panel colour and a hairline. */
    case Frosted = 'frosted';

    /** A pane of glass: frosted by the radius, its rim bending what is behind. */
    case Glass = 'glass';

    /** The blur of the OS. It exists only behind a window that is seen through, which a Silk window never is: it paints as Frosted (Raven does the same). */
    case Native = 'native';
}
