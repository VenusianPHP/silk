<?php

use Silk\Core\Motion\FrameClock;

it('returns 0.001 on the first tick', function () {
    expect((new FrameClock)->tick(5_000_000_000))->toBe(0.001);
});

it('returns the gap between ticks in seconds', function () {
    $c = new FrameClock;
    $c->tick(1_000_000_000);

    expect($c->tick(1_020_000_000))->toEqualWithDelta(0.02, 1e-9);
});

it('clamps a long gap to a quarter second and a tiny one to 0.001', function () {
    $c = new FrameClock;
    $c->tick(0);

    expect($c->tick(1_000_000_000))->toBe(0.25)
        ->and($c->tick(1_000_000_010))->toBe(0.001);
});

it('starts again from now after forget(): the first tick is 0.001', function () {
    $c = new FrameClock;
    $c->tick(0);
    $c->forget();

    expect($c->tick(9_000_000_000))->toBe(0.001);
});

it('cuts a frame into equal steps of at most 1/30', function () {
    expect(FrameClock::steps(0.001))->toBe([0.001])
        ->and(FrameClock::steps(1 / 30))->toBe([1 / 30])
        ->and(count(FrameClock::steps(0.25)))->toBe(8)
        ->and(array_sum(FrameClock::steps(0.25)))->toEqualWithDelta(0.25, 1e-12)
        ->and(max(FrameClock::steps(0.2)))->toBeLessThanOrEqual(1 / 30 + 1e-12);
});
