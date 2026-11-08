<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\Rows\RowScroll;
use Surface\Contracts\HumanInput\MouseButton;

it('keeps the offset on whole units in range, carrying a wheel\'s fractions', function () {
    $s = new RowScroll;
    [$s->content, $s->viewport] = [1000.0, 200.0];
    expect($s->set(-5))->toBeFalse()->and($s->offset)->toBe(0.0)
        ->and($s->set(10.6))->toBeTrue()->and($s->offset)->toBe(11.0)
        ->and($s->set(5000))->toBeTrue()->and($s->offset)->toBe(800.0);
    $s->set(100);
    expect($s->wheel(0.4))->toBeTrue()->and($s->offset)->toBe(100.0);
    $s->wheel(0.7);
    expect($s->offset)->toBe(101.0);
    $s->wheel(-0.2);
    expect($s->offset)->toBe(101.0);
});

it('bubbles at an end and kicks the drawn offset by delta x 9, at most 1400', function () {
    $s = new RowScroll;
    [$s->content, $s->viewport] = [1000.0, 200.0];
    expect($s->wheel(-10))->toBeFalse()->and($s->shown->velocity())->toBe(-90.0);
    $s->wheel(-1000);
    expect($s->shown->velocity())->toBe(-90.0 - 1400.0);
    $none = new RowScroll;
    [$none->content, $none->viewport] = [100.0, 200.0];
    expect($none->wheel(5))->toBeFalse()->and($none->shown->velocity())->toBe(0.0);
});

it('brings the thumb middle under a track click, then drags', function () {
    $s = new RowScroll;
    [$s->content, $s->viewport] = [1000.0, 200.0];
    $track = new Rect(190, 0, 10, 200);
    // thumb length 200 x 200/1000 = 40; travel 160; a click at 100 puts its middle there: start 80 -> offset 400
    expect($s->event(new SilkEvent(EventType::MouseDown, x: 195, y: 100, button: MouseButton::LEFT), $track, true))->toBeTrue()
        ->and($s->offset)->toBe(400.0)->and($s->dragging)->toBeTrue();
    $s->event(new SilkEvent(EventType::MouseMove, x: 195, y: 140), $track, true);
    expect($s->offset)->toBe(600.0)->and($s->drawn())->toBe(600.0);
    $s->event(new SilkEvent(EventType::MouseUp, x: 195, y: 140, button: MouseButton::LEFT), $track, true);
    expect($s->dragging)->toBeFalse();
});
