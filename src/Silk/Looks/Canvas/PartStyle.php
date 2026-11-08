<?php

namespace Silk\Looks\Canvas;

use Silk\Contracts\Look\PartLook;
use Silk\Painter\RavenPaint;
use Surface\NutsAndBolts\Color;

/**
 * How a part of a canvas widget is drawn in one state (Raven rvn_part_look_t, core/context.h:30-54): what the
 * widget's look gives it, then the theme's styles, then what was given to the widget and to its items. A null
 * colour is Raven's 0: nothing drawn. Lengths are logical units.
 */
final class PartStyle
{
    /** The bits of the values set (RVN_LOOK_*, context.h:57-71), in the order of the properties. */
    public const int BACKGROUND = 1 << 0;

    public const int FOREGROUND = 1 << 1;

    public const int BORDER = 1 << 2;

    public const int BORDER_TOP = 1 << 3;

    public const int BORDER_BOTTOM = 1 << 4;

    public const int SHADOW_COLOR = 1 << 5;

    public const int BORDER_WIDTH = 1 << 6;

    public const int BOTTOM_WIDTH = 1 << 7;

    public const int RADIUS = 1 << 8;

    public const int SIZE = 1 << 9;

    public const int OPACITY = 1 << 10;

    public const int SHADOW = 1 << 11;

    public const int SHAPE = 1 << 12;

    public const int GLOW = 1 << 13;

    public const int GRADIENT = 1 << 14;

    /** In the order of the bits (look.c:160-164). */
    private const array NAMES = [
        'background', 'foreground', 'border', 'borderTop', 'borderBottom', 'shadowColor',
        'borderWidth', 'borderBottomWidth', 'radius', 'size', 'opacity', 'shadow', 'shape', 'glow', 'gradient',
    ];

    private const array PROPERTY_NAMES = [
        'Background', 'Foreground', 'BorderColor', 'BorderTopColor', 'BorderBottomColor', 'ShadowColor',
        'BorderWidth', 'BorderBottomWidth', 'CornerRadius', 'Size', 'Opacity', 'ShadowSize', 'Shape', 'Glow', 'Gradient',
    ];

    /** rvn_parse_color's names (xml/xml_attributes.c:131-136). */
    private const array NAMED = [
        'transparent' => [0x000000, 0], 'white' => [0xFFFFFF, 255], 'black' => [0x000000, 255], 'red' => [0xE5484D, 255],
        'green' => [0x46A758, 255], 'blue' => [0x3B82F6, 255], 'yellow' => [0xF5D90A, 255], 'orange' => [0xF76B15, 255],
        'cyan' => [0x05A2C2, 255], 'magenta' => [0xD6409F, 255], 'gray' => [0x808080, 255], 'grey' => [0x808080, 255],
    ];

    public ?Color $background = null;

    /** Text, marks and glyphs drawn on the part. */
    public ?Color $foreground = null;

    public ?Color $border = null;

    public ?Color $borderTop = null;

    public ?Color $borderBottom = null;

    public ?Color $shadowColor = null;

    public float $borderWidth = 0.0;

    public float $borderBottomWidth = 0.0;

    public float $radius = 0.0;

    /** Size of a part that has one of its own; 0: the widget's. */
    public float $size = 0.0;

    public float $opacity = 0.0;

    /** Blur of the shadow; 0: none. */
    public float $shadow = 0.0;

    public CanvasShape $shape = CanvasShape::Default;

    /** How much light it gives around it, 0..1. */
    public float $glow = 0.0;

    /** The colour it fades to; null: no gradient. */
    public ?Color $gradient = null;

    /** A colour that draws something (Raven rvn_color_a(c) != 0). */
    public static function shows(?Color $c): bool
    {
        return $c !== null && $c->alpha > 0.0;
    }

    /**
     * Sets a property by Raven's name ("Background", "Fill", "BorderColor", "CornerRadius", "Shape"...);
     * returns its bit, 0 for an unknown name; throws for a value it cannot take (rvn_part_property, look.c:175-228).
     * "" or "Default" gives back what the look and the styles give: the caller clears the bit.
     */
    public function set(string $name, string $value): int
    {
        $name = match (strtolower($name)) {
            'color', 'fill' => 'Background',
            'border' => 'BorderColor',
            'radius' => 'CornerRadius',
            'shadow' => 'ShadowSize',
            'width', 'thickness' => 'Size',
            'style', 'form' => 'Shape',
            'text' => 'Foreground',
            default => $name,
        };
        $index = null;
        foreach (self::PROPERTY_NAMES as $i => $known) {
            strcasecmp($known, $name) === 0 && $index = $i;
        }
        if ($index === null) {
            return 0;
        }
        $bit = 1 << $index;
        if ($value === '' || strcasecmp($value, 'Default') === 0 || strcasecmp($value, 'Inherit') === 0) {
            return $bit;
        }
        $property = self::NAMES[$index];
        if ($bit === self::SHAPE) {
            $this->shape = CanvasShape::byName($value) ?? throw new \InvalidArgumentException("'{$value}' is no shape.");
        } elseif ($bit === self::GLOW) {
            $this->glow = self::number($value);
        } elseif ($bit === self::GRADIENT) {
            $this->gradient = strcasecmp($value, 'None') === 0 ? null : self::color($value);
        } elseif ($index < 6) {
            // "None" and "Transparent": nothing drawn
            $this->{$property} = strcasecmp($value, 'None') === 0 || strcasecmp($value, 'Transparent') === 0 ? null : self::color($value);
        } else {
            $this->{$property} = self::number($value);
        }

        return $bit;
    }

    private static function number(string $value): float
    {
        if (! is_numeric(trim($value)) || (float) $value < 0.0) {
            throw new \InvalidArgumentException("'{$value}' is no length or amount.");
        }

        return (float) $value;
    }

    /** rvn_parse_color (xml_attributes.c:130-166): a name, #RGB, #RGBA, #RRGGBB or #RRGGBBAA. */
    public static function color(string $text): Color
    {
        $text = trim($text);
        if (! str_starts_with($text, '#')) {
            [$rgb, $alpha] = self::NAMED[strtolower($text)] ?? throw new \InvalidArgumentException("'{$text}' is no colour.");

            return Color::rgba($rgb >> 16, ($rgb >> 8) & 0xFF, $rgb & 0xFF, $alpha / 255);
        }
        $digits = substr($text, 1);
        if (! ctype_xdigit($digits) || ! in_array(strlen($digits), [3, 4, 6, 8], true)) {
            throw new \InvalidArgumentException("'{$text}' is no colour.");
        }
        if (strlen($digits) <= 4) {
            // #RGB and #RGBA: each digit is doubled
            $c = array_map(fn ($d) => hexdec($d) * 17, str_split($digits));

            return Color::rgba($c[0], $c[1], $c[2], ($c[3] ?? 255) / 255);
        }
        $c = array_map('hexdec', str_split($digits, 2));

        return Color::rgba($c[0], $c[1], $c[2], ($c[3] ?? 255) / 255);
    }

    /** Takes the values of $from that $mask names (rvn_part_look_apply, look.c:271-292). */
    public function apply(self $from, int $mask): void
    {
        foreach (self::NAMES as $i => $property) {
            ($mask & (1 << $i)) && $this->{$property} = $from->{$property};
        }
        // a border colour given alone is the whole border: the edges of the look do not stay
        ($mask & self::BORDER) && ! ($mask & self::BORDER_TOP) && $this->borderTop = null;
        ($mask & self::BORDER) && ! ($mask & self::BORDER_BOTTOM) && $this->borderBottom = null;
        if (($mask & (self::BORDER | self::BORDER_TOP | self::BORDER_BOTTOM)) && ! ($mask & self::BORDER_WIDTH) && $this->borderWidth <= 0.0) {
            $this->borderWidth = 1.0;
        }
    }

    /**
     * What a theme's style row gives the part: the colours it has, its lengths, and the canvas values in its
     * extra (size, opacity, shape, glow, gradient, borderBottomWidth); its bits.
     */
    public static function fromPartLook(PartLook $look): array
    {
        $style = new self;
        $mask = 0;
        foreach (['background' => self::BACKGROUND, 'text' => self::FOREGROUND, 'border' => self::BORDER, 'borderTop' => self::BORDER_TOP,
            'borderBottom' => self::BORDER_BOTTOM, 'shadowColor' => self::SHADOW_COLOR] as $field => $bit) {
            if ($look->{$field} !== null) {
                $style->{self::NAMES[(int) log($bit, 2)]} = $look->{$field};
                $mask |= $bit;
            }
        }
        foreach (['borderWidth' => self::BORDER_WIDTH, 'radius' => self::RADIUS, 'shadow' => self::SHADOW] as $field => $bit) {
            if ($look->{$field} > 0.0) {
                $style->{$field} = $look->{$field};
                $mask |= $bit;
            }
        }
        foreach (['size' => self::SIZE, 'opacity' => self::OPACITY, 'glow' => self::GLOW, 'borderBottomWidth' => self::BOTTOM_WIDTH] as $key => $bit) {
            if (isset($look->extra[$key])) {
                $style->{$key} = (float) $look->extra[$key];
                $mask |= $bit;
            }
        }
        if (isset($look->extra['shape'])) {
            $shape = $look->extra['shape'];
            $style->shape = $shape instanceof CanvasShape ? $shape : (CanvasShape::byName((string) $shape) ?? CanvasShape::Default);
            $mask |= self::SHAPE;
        }
        if (array_key_exists('gradient', $look->extra)) {
            $style->gradient = $look->extra['gradient'];
            $mask |= self::GRADIENT;
        }
        // "none" => "borderWidth,glow": what a row sets to nothing (Raven's 0 for a colour or a length)
        foreach (preg_split('/\s*,\s*/', (string) ($look->extra['none'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $name) {
            $index = array_search($name, self::NAMES, true);
            if ($index === false) {
                throw new \InvalidArgumentException("A style row cannot set '{$name}' to nothing.");
            }
            $style->{$name} = $index < 6 || $name === 'gradient' ? null : ($name === 'shape' ? CanvasShape::Default : 0.0);
            $mask |= 1 << $index;
        }

        return [$style, $mask];
    }

    /** A colour between two, alpha included; a transparent end takes the other's colour (look.c:301-306). */
    private static function mixColor(?Color $a, ?Color $b, float $t): ?Color
    {
        if (! self::shows($a) && ! self::shows($b)) {
            return null;
        }
        $a = self::shows($a) ? $a : new Color($b->red, $b->green, $b->blue, 0.0);
        $b = self::shows($b) ? $b : new Color($a->red, $a->green, $a->blue, 0.0);

        return RavenPaint::mix($a, $b, $t);
    }

    /** Blends toward $b by $t; a form changes half way (rvn_part_look_mix, look.c:308-331). */
    public function mix(self $b, float $t): void
    {
        $t = max(0.0, min(1.0, $t));
        if ($t <= 0.0) {
            return;
        }
        foreach (self::NAMES as $i => $property) {
            if ($property === 'shape') {
                $t >= 0.5 && $this->shape = $b->shape;
            } elseif ($i < 6 || $property === 'gradient') {
                $this->{$property} = $t >= 1.0 ? $b->{$property} : self::mixColor($this->{$property}, $b->{$property}, $t);
            } else {
                $this->{$property} += ($b->{$property} - $this->{$property}) * $t;
            }
        }
    }

    public static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }

    /** Raven rvn_hex(rgb, alpha). */
    public static function hex(int $rgb, int $alpha = 255): Color
    {
        return Color::rgba($rgb >> 16, ($rgb >> 8) & 0xFF, $rgb & 0xFF, $alpha / 255);
    }
}
