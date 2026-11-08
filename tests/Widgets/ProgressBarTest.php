<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;

it('fills its fraction of the track: the full shape clipped to floor(w x f + .5) (progress_bar.c:30, 75-81)', function (float $fraction) {
    [$bar, $calls, $look] = barIn('raven-dark', $fraction);
    $b = $bar->bounds();

    expect(array_slice($calls[0][1], 0, 3))->toEqual([$b, $look->color(Role::Track)->toHex(), min($b->h / 2, $look->metric(Metric::CornerRadius))]);
    if ($fraction > 0) {
        expect($calls[1][0])->toBe('pushClip')
            ->and($calls[1][1][0]->w)->toBe(floor($b->w * $fraction + 0.5))
            ->and($calls[2][1][1])->toBe($look->color(Role::Accent)->toHex());
    } else {
        expect($calls)->toHaveCount(1);
    }
})->with([0.0, 0.2, 0.5, 1.0]);

it('clamps the fraction to 0..1', function () {
    $ui = silkUi(fn ($root) => $root->progressBar('load', 1.5));

    expect($ui->root->children()[0]->fraction())->toBe(1.0)
        ->and($ui->root->children()[0]->setFraction(-1)->fraction())->toBe(0.0);
});

it('measures 120 by 8, and glides to a new fraction on SMOOTH (progress_bar.c:20-29)', function () {
    $ui = silkUi(fn ($root) => $root->progressBar('load', 0.5));
    $bar = $ui->root->children()[0];

    expect($bar->measure(new Silk\Contracts\Geometry\Size(500, 500))->w)->toBe(120.0)
        ->and($bar->measure(new Silk\Contracts\Geometry\Size(500, 500))->h)->toBe(8.0)
        ->and($bar->springs()[0]->preset)->toBe(Silk\Core\Motion\SpringPreset::Smooth)
        ->and($bar->springs()[0]->atRest())->toBeFalse();
});

it('fades the fill to .4 when disabled (progress_bar.c:56)', function () {
    [, $calls, $look] = barIn('raven-dark', 0.5, fn ($b) => $b->setEnabled(false));

    expect(substr($calls[2][1][1], 7))->toBe(sprintf('%02x', round($look->color(Role::Accent)->alpha * 0.4 * 255)));
});

it('paints the platforms\' groove in the middle: macOS 6, GNOME 8 (progress_bar.c:33-54)', function () {
    foreach (['macos-dark' => 6.0, 'gnome-light' => 8.0] as $name => $t) {
        [$bar, $calls, $look] = barIn($name, 0.5, null, 20.0);
        $b = $bar->bounds();

        expect([$calls[0][1][0]->w, $calls[0][1][0]->h, $calls[0][1][2]])->toBe([$b->w, $t, $t / 2])
            ->and($calls[0][1][0]->y)->toBe($b->y + floor($b->h / 2) - floor($t / 2))
            ->and($calls[1][1][0]->w)->toBe(floor($b->w * 0.5 + 0.5))
            ->and($calls[1][1][1])->toBe($look->color(Role::Accent)->toHex());
    }
});
