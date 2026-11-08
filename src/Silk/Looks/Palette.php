<?php

namespace Silk\Looks;

use InvalidArgumentException;
use Silk\Contracts\Look\Role;
use Surface\NutsAndBolts\Color;

/** Role => Color. Accepts Color, `#rrggbb`, `#rrggbbaa`, or Raven's `#rrggbb/alpha` (0..255). */
class Palette
{
    /** @var array<string, Color> keyed by role name */
    private array $colors = [];

    /** @param array<string, Color|string> $colors keyed by role name */
    public function __construct(array $colors = [])
    {
        $this->merge($colors);
    }

    public function merge(array $colors): static
    {
        foreach ($colors as $role => $value) {
            $role = $role instanceof Role ? $role->name : $role;
            if (! defined(Role::class.'::'.$role)) {
                throw new InvalidArgumentException("'{$role}' is not a colour role.");
            }
            $this->colors[$role] = self::parse($value);
        }

        return $this;
    }

    public function get(Role $r): Color
    {
        return $this->colors[$r->name] ?? throw new InvalidArgumentException("The palette has no '{$r->name}'.");
    }

    public static function parse(Color|string $v): Color
    {
        if ($v instanceof Color) {
            return $v;
        }
        [$hex, $alpha] = array_pad(explode('/', $v, 2), 2, null);
        $c = Color::hex($hex);

        return $alpha === null ? $c : new Color($c->red, $c->green, $c->blue, (int) $alpha / 255);
    }
}
