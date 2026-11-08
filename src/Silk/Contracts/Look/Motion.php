<?php

namespace Silk\Contracts\Look;

/** A look's motion (Raven): springy looks bounce back and squash on press; smooth (platform) looks do neither. */
enum Motion: string
{
    case Springy = 'springy';
    case Smooth = 'smooth';
}
