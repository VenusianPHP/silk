<?php

use Silk\Widgets\Rows\RowKeys;
use Surface\Contracts\HumanInput\Key;

it('maps the row keys, clamped to the rows', function () {
    expect(RowKeys::step(Key::DOWN, 0, 50, 10))->toBe(1)
        ->and(RowKeys::step(Key::DOWN, 49, 50, 10))->toBe(49)
        ->and(RowKeys::step(Key::UP, 0, 50, 10))->toBe(0)
        ->and(RowKeys::step(Key::UP, 49, 50, 10))->toBe(48)
        ->and(RowKeys::step(Key::HOME, 49, 50, 10))->toBe(0)
        ->and(RowKeys::step(Key::END, 0, 50, 10))->toBe(49)
        ->and(RowKeys::step(Key::PAGE_DOWN, 0, 50, 10))->toBe(10)
        ->and(RowKeys::step(Key::PAGE_DOWN, 45, 50, 10))->toBe(49)
        ->and(RowKeys::step(Key::PAGE_UP, 49, 50, 10))->toBe(39)
        ->and(RowKeys::step(Key::PAGE_UP, 3, 50, 10))->toBe(0)
        ->and(RowKeys::step(Key::DOWN, -1, 50, 10))->toBe(0)
        ->and(RowKeys::step(Key::UP, -1, 50, 10))->toBe(49)
        ->and(RowKeys::step(Key::LEFT, 3, 50, 10))->toBeNull()
        ->and(RowKeys::step(Key::DOWN, 0, 0, 10))->toBeNull();
});
