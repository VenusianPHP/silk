<?php

namespace Silk\Looks\Canvas;

/** The forms a part can take (Raven widget.h:509-520, look.c:44-73): pins and keys, links, grids, headers and clips. */
enum CanvasShape: int
{
    case Default = 0;
    case Circle = 1;
    case Ring = 2;
    case Square = 3;
    case Diamond = 4;
    case Triangle = 5;
    case Arrow = 6;
    case Bezier = 7;
    case Straight = 8;
    case Step = 9;
    case Angled = 10;
    case Dots = 11;
    case Lines = 12;
    case Cross = 13;
    case Solid = 14;
    case Gradient = 15;
    case Stripe = 16;
    case Tint = 17;
    case Outline = 18;
    case None = 19;

    /** By name, without case, with Raven's other words (rvn_shape_by_name, look.c:52-73). */
    public static function byName(string $name): ?self
    {
        foreach (self::cases() as $shape) {
            if (strcasecmp($shape->name, $name) === 0) {
                return $shape;
            }
        }

        return match (strtolower($name)) {
            'curve', 'curved', 'spline' => self::Bezier,
            'line', 'linear' => self::Straight,
            'orthogonal', 'steps' => self::Step,
            'diagonal' => self::Angled,
            'dot' => self::Dots,
            'hollow' => self::Ring,
            'flat' => self::Solid,
            'hidden', 'off' => self::None,
            default => null,
        };
    }
}
