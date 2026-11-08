<?php

use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;

/** Steps the spring at 1/60 s for $seconds and returns the largest value seen. */
function runSpring(Spring $s, float $seconds): float
{
    $max = $s->value();
    for ($i = 0; $i < (int) round($seconds * 60); $i++) {
        $max = max($max, $s->step(1 / 60)->value());
    }

    return $max;
}

it('holds the presets', function () {
    expect(SpringPreset::Snappy->period())->toBe(0.16)->and(SpringPreset::Snappy->damping())->toBe(0.90)
        ->and(SpringPreset::Smooth->period())->toBe(0.30)->and(SpringPreset::Smooth->damping())->toBe(1.00)
        ->and(SpringPreset::Bouncy->period())->toBe(0.38)->and(SpringPreset::Bouncy->damping())->toBe(0.52)
        ->and(SpringPreset::Wobbly->period())->toBe(0.50)->and(SpringPreset::Wobbly->damping())->toBe(0.32);
});

it('puts a new spring at rest on its first value', function () {
    $s = new Spring(0.5, SpringPreset::Bouncy);

    expect($s->value())->toBe(0.5)->and($s->velocity())->toBe(0.0)->and($s->atRest())->toBeTrue();
});

it('settles a snappy spring within 0.6 s', function () {
    $s = (new Spring(0.0, SpringPreset::Snappy))->target(1.0);
    expect($s->atRest())->toBeFalse();

    runSpring($s, 0.6);

    expect(abs($s->value() - 1.0))->toBeLessThanOrEqual(0.0015)->and($s->atRest())->toBeTrue();
});

it('overshoots when bouncy and not when smooth', function () {
    expect(runSpring((new Spring(0.0, SpringPreset::Bouncy))->target(1.0), 2.0))->toBeGreaterThan(1.05)
        ->and(runSpring((new Spring(0.0, SpringPreset::Smooth))->target(1.0), 2.0))->toBeLessThanOrEqual(1.0001);
});

it('snaps onto the target with no velocity', function () {
    $s = (new Spring(0.0, SpringPreset::Wobbly))->target(1.0)->step(1 / 60)->snap(3.0);

    expect($s->value())->toBe(3.0)->and($s->velocity())->toBe(0.0)->and($s->atRest())->toBeTrue();
    $s->step(1 / 60);
    expect($s->value())->toBe(3.0);
});

it('moves after a kick and comes back', function () {
    $s = (new Spring(1.0, SpringPreset::Bouncy))->kick(-11.0);
    expect($s->atRest())->toBeFalse()->and($s->velocity())->toBe(-11.0);

    $s->step(1 / 60);
    expect($s->value())->toBeLessThan(1.0);

    runSpring($s, 3.0);
    expect($s->atRest())->toBeTrue()->and($s->value())->toBe(1.0);
});

it('snaps on target() under reduced motion', function () {
    $s = (new Spring(0.0, SpringPreset::Bouncy, reduced: true))->target(1.0);

    expect($s->value())->toBe(1.0)->and($s->atRest())->toBeTrue();
});
