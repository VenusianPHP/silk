<?php

namespace Silk\Widgets\Rows;

use Surface\Contracts\HumanInput\Key;

/** The row-moving keys of the virtualized views. */
final class RowKeys
{
    /** The row $key moves $current to, clamped to [0, $count); null for another key or no rows. From none (-1), Up is the last row and Down the first (list_view.c:263-266). */
    public static function step(Key $key, int $current, int $count, int $page): ?int
    {
        if ($count === 0) {
            return null;
        }
        $page = max(1, $page);
        $to = match ($key) {
            Key::DOWN => $current < 0 ? 0 : $current + 1,
            Key::UP => $current < 0 ? $count - 1 : $current - 1,
            Key::HOME => 0,
            Key::END => $count - 1,
            Key::PAGE_DOWN => $current + $page,
            Key::PAGE_UP => $current - $page,
            default => null,
        };

        return $to === null ? null : max(0, min($count - 1, $to));
    }
}
