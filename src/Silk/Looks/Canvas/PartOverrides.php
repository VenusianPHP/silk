<?php

namespace Silk\Looks\Canvas;

/**
 * What was given to the parts of a widget ("Clip.Shape" = "Tint"), or to an item's slots (Raven
 * rvn_look_overrides_t and rvn_item_look_t, canvas_look.c:87-178): per part or slot, the values and their bits.
 */
final class PartOverrides
{
    /** @var array<string, array{PartStyle, int}> */
    private array $parts = [];

    /**
     * Gives a property to a part (rvn_look_override); "" or "Default" takes it back. False for an unknown
     * property; throws for a value it cannot take.
     */
    public function set(string $part, string $property, string $value): bool
    {
        [$style, $mask] = $this->parts[$part] ?? [new PartStyle, 0];
        $style = clone $style;
        $bit = $style->set($property, $value);
        if ($bit === 0) {
            return false;
        }
        $default = $value === '' || strcasecmp($value, 'Default') === 0 || strcasecmp($value, 'Inherit') === 0;
        $this->parts[$part] = [$style, $default ? $mask & ~$bit : $mask | $bit];

        return true;
    }

    /** "Clip.Shape": a part and a property in one name; "Canvas." and "Body." are the widget itself, the part 'Canvas' (rvn_look_override_dotted). */
    public function setDotted(string $name, string $value): bool
    {
        $dot = strpos($name, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }
        $part = substr($name, 0, $dot);
        // (the widget's own surface is the part 'Canvas': Silk's 'Body' rows are the controls' look)
        $part = strcasecmp($part, 'Canvas') === 0 || strcasecmp($part, 'Body') === 0 ? 'Canvas' : ucfirst($part);

        return $this->set($part, substr($name, $dot + 1), $value);
    }

    public function applyTo(string $part, PartStyle $style): void
    {
        if (isset($this->parts[$part]) && $this->parts[$part][1] !== 0) {
            $style->apply(...$this->parts[$part]);
        }
    }

    public function has(string $part, int $bit): bool
    {
        return (($this->parts[$part][1] ?? 0) & $bit) !== 0;
    }
}
