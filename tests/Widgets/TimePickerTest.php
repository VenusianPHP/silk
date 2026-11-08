<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Mail\TimeChanged;
use Silk\Core\Input\FocusManager;
use Silk\Core\Input\Router;
use Silk\Core\Tree\Tree;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Widgets\Column;
use Silk\Widgets\TimePicker;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyLoop;

it('wraps each part with Up and Down, never carrying; PageUp and PageDown step 10', function () {
    $ui = timeUi();
    pressKey($ui->router, Key::UP);
    expect([$ui->t->hour(), $ui->t->minute()])->toBe([0, 59]);
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::UP);
    expect([$ui->t->hour(), $ui->t->minute()])->toBe([0, 0]);
    pressKey($ui->router, Key::PAGE_DOWN);
    expect($ui->t->minute())->toBe(50)->and(timeMail($ui))->toBe([[0, 59, null], [0, 0, null], [0, 50, null]]);
});

it('moves between parts with Left and Right, leaving the key unhandled at the ends', function () {
    $ui = timeUi(s: 30);
    expect($ui->t->handle(new SilkEvent(EventType::KeyDown, key: Key::LEFT)))->toBeFalse();
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::RIGHT);
    expect($ui->t->field())->toBe(2)->and($ui->t->handle(new SilkEvent(EventType::KeyDown, key: Key::RIGHT)))->toBeFalse();
});

it('fills a part from two digits, or at once from a digit that cannot start two', function () {
    $ui = timeUi(s: 0);
    typeText($ui, '07');
    expect([$ui->t->hour(), $ui->t->field()])->toBe([7, 1]);
    typeText($ui, '7');
    expect([$ui->t->minute(), $ui->t->field()])->toBe([7, 2]);
    $ui = timeUi();
    typeText($ui, '3');
    expect([$ui->t->hour(), $ui->t->field()])->toBe([3, 1]);
    $ui = timeUi();
    typeText($ui, '29');
    expect($ui->t->hour())->toBe(23);
});

it('commits a waiting digit when the part is left, and moves on at : . h', function () {
    $ui = timeUi(h: 10, m: 10, s: 10);
    typeText($ui, '1');
    pressKey($ui->router, Key::RIGHT);
    expect([$ui->t->hour(), $ui->t->field()])->toBe([1, 1]);
    typeText($ui, '4:');
    expect([$ui->t->minute(), $ui->t->field()])->toBe([4, 2]);
    typeText($ui, '5');
    $ui->t->handle(new SilkEvent(EventType::FocusLost));
    expect($ui->t->second())->toBe(5);
});

it('clears the waiting digit, or zeroes the part, with Backspace', function () {
    $ui = timeUi(h: 12);
    typeText($ui, '1');
    pressKey($ui->router, Key::BACKSPACE);
    expect($ui->t->hour())->toBe(12);
    pressKey($ui->router, Key::BACKSPACE);
    expect($ui->t->hour())->toBe(0);
});

it('steps the part under the pointer with the wheel when not focused, the selected one when focused', function () {
    $ui = timeUi(h: 10, m: 10);
    $ui->t->handle(new SilkEvent(EventType::FocusLost));
    $m = $ui->t->segmentRect(1);
    expect($ui->t->handle(new SilkEvent(EventType::Wheel, x: $m->x + 2, y: $m->y + 2, wheelY: 2)))->toBeTrue()
        ->and([$ui->t->hour(), $ui->t->minute()])->toBe([10, 12]);
    $ui->t->handle(new SilkEvent(EventType::FocusGained));
    $h = $ui->t->segmentRect(0);
    $ui->t->handle(new SilkEvent(EventType::Wheel, x: $h->x + 2, y: $h->y + 2, wheelY: 1));
    expect([$ui->t->hour(), $ui->t->minute()])->toBe([10, 13]);
});

it('steps with its own arrows at the end, the upper half up', function () {
    $ui = timeUi(h: 5);
    [$up, $down] = $ui->t->arrowRects();
    click($ui->router, ...centre_of($up));
    click($ui->router, ...centre_of($up));
    click($ui->router, ...centre_of($down));

    expect($ui->t->hour())->toBe(6);
});

it('lands minutes on the minute step first', function () {
    $ui = timeUi(h: 9, m: 7);
    $ui->t->setMinuteStep(15);
    pressKey($ui->router, Key::RIGHT);
    pressKey($ui->router, Key::UP);
    expect($ui->t->minute())->toBe(15);
    pressKey($ui->router, Key::UP);
    expect($ui->t->minute())->toBe(30);
    $ui->t->setTime(9, 7);
    pressKey($ui->router, Key::DOWN);
    expect($ui->t->minute())->toBe(0);
});

it('posts nothing for setTime', function () {
    $ui = timeUi(s: 1);
    $ui->t->setTime(25, 61, 70);

    expect([$ui->t->hour(), $ui->t->minute(), $ui->t->second()])->toBe([1, 1, 10])->and($ui->loop->posted)->toBe([]);
});
