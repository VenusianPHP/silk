<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\FontSpec;
use Silk\Painter\EnginePainter;
use Silk\Text\TextMeasurer;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Surface\Tests\Support\Fakes\FakeFontDriver;

it('culls text wholly outside the clip before the engine (Review Focus 5)', function () {
    $e = new SpyEngine;
    $style = new FontSpec('sans-serif', 13.0);

    $e->frame(function () use ($e, $style) {
        $p = (new EnginePainter($e, 1.0, null, new TextMeasurer(new FakeFontDriver)))->pushClip(new Rect(0, 0, 100, 50));
        $p->text('inside', 10, 30, Color::hex('#fff'), $style);
        $p->text('below', 10, 120, Color::hex('#fff'), $style);
        $p->text('right', 150, 30, Color::hex('#fff'), $style);
        $p->text('straddles', 90, 30, Color::hex('#fff'), $style);
        $p->popClip();
    });

    expect(array_column($e->argsOf('text'), 0))->toBe(['inside', 'straddles']);
});
