<?php

use Silk\Painter\Snap;

it('snaps an edge with floor(v * scale + 0.5)', function () {
    expect(Snap::edge(10.4, 2.0))->toBe(21)
        ->and(Snap::edge(10.2, 1.0))->toBe(10)
        ->and(Snap::edge(10.5, 1.0))->toBe(11)
        ->and(Snap::edge(-0.6, 1.0))->toBe(-1);
});

it('clamps a stroke to at least one pixel', function () {
    expect(Snap::stroke(0.3, 1.0))->toBe(1)
        ->and(Snap::stroke(1.0, 2.0))->toBe(2)
        ->and(Snap::stroke(1.4, 2.5))->toBe(4)
        ->and(Snap::stroke(0.0, 1.0))->toBe(0);
});
