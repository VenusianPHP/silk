<?php

namespace Silk\Looks;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Surface\Contracts\Windows\Services\SystemAccent;
use Surface\NutsAndBolts\Color;

/** What `silk.accent` says the accent is: the system's, the theme's own, or a custom colour. */
final class AccentSource
{
    public const array MODES = ['auto', 'system', 'theme', 'custom'];

    /**
     * @param Closure(): ?SystemAccent $system the toolkit's accent service, null when no toolkit is bound; called only when the mode reads the system
     * @return ?Color null = the theme keeps its own accent
     * @throws InvalidArgumentException When the mode is unknown, or 'custom' has no valid colour.
     * @throws RuntimeException When the mode is 'system' and the system gives no accent.
     */
    public static function resolve(mixed $mode, mixed $custom, Closure $system): ?Color
    {
        return match ($mode) {
            'auto' => $system()?->color(),
            'system' => self::system($system()),
            'theme' => null,
            'custom' => self::custom($custom),
            default => throw new InvalidArgumentException(sprintf("silk.accent is %s; it is one of %s.", var_export($mode, true), implode(', ', self::MODES))),
        };
    }

    private static function system(?SystemAccent $accent): Color
    {
        if ($accent === null) {
            throw new RuntimeException("silk.accent is 'system', but no toolkit-windows is bound to ask.");
        }

        return $accent->color() ?? throw new RuntimeException("silk.accent is 'system', but ".$accent::class.' reports no system accent.');
    }

    private static function custom(mixed $value): Color
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("silk.accent is 'custom', but silk.accent_color is ".var_export($value, true).'; it is a #RRGGBB string.');
        }

        try {
            return Color::hex($value);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("silk.accent is 'custom', but silk.accent_color: ".$e->getMessage(), 0, $e);
        }
    }
}
