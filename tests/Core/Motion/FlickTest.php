<?php

use Silk\Core\Motion\Flick;

/** Moves at $speed u/s for 0.2 s in 1/60 steps from t=0, and returns the time of the last move. */
function drag(Flick $f, float $speed): float
{
    $t = 0.0;
    for ($i = 0; $i < 12; $i++) {
        $t += 1 / 60;
        $f->move($speed / 60, $t);
    }

    return $t;
}

it('coasts after a fast lift and stops', function () {
    $f = new Flick;
    $t = drag($f, 1000);

    $f->lift($t);
    expect($f->coasting())->toBeTrue()->and($f->velocity())->toEqualWithDelta(1000, 1);

    $travel = 0.0;
    for ($i = 0; $i < 600 && $f->coasting(); $i++) {
        $travel += $f->step(1 / 60);
    }

    expect($f->coasting())->toBeFalse()
        // v^2 / (2a) = 1000^2 / 4800, less the tail below 15 u/s
        ->and($travel)->toEqualWithDelta(1000 ** 2 / (2 * 2400), 10);
});

it('does not start at 50 u/s', function () {
    $f = new Flick;
    $f->lift(drag($f, 50));

    expect($f->coasting())->toBeFalse()->and($f->step(1 / 60))->toBe(0.0);
});

it('is cancelled by a 0.08 s rest before lift', function () {
    $f = new Flick;
    $t = drag($f, 1000);

    $f->lift($t + 0.08);

    expect($f->coasting())->toBeFalse();
});

it('smooths velocity, keeping 0.35 of the old', function () {
    $f = new Flick;
    $f->move(10, 1 / 60);      // 600 u/s, the first sample
    $f->move(0, 2 / 60);       // 0 u/s

    expect($f->velocity())->toEqualWithDelta(0.35 * 600, 1e-6);
});
