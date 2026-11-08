<?php

namespace Silk\Contracts\Look;

/** The presets of glass (Raven rvn_surface_t, common.c:48): what a surface is for. */
enum SurfaceKind
{
    case Control;
    case Accent;
    case Input;
    case Bar;
    case Panel;
    case Popup;
    /** A drop of clear glass: a picked-up slider knob. */
    case Lens;
}
