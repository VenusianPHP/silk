<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\BlurLook;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Velvet\VelvetGE;
use Surface\Framebuffers\Native\NativeFramebufferDriver;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;

/*
 * Painter::blur(): Raven's blur primitive (draw.c:545, ui.frag:158-216) as a Surface glass pane.
 */

it('lays the pane as a glass pane in device pixels, rounded, its blur, grain, tint and light Raven\'s', function () {
    $e = new SpyEngine;
    $look = new BlurLook(9.0, Color::rgba(10, 20, 30, 0.4), 1.3, 0.5, 0.06);
    $e->frame(fn () => (new EnginePainter($e, 2.0))->blur(new Rect(10, 20, 60, 30), [6.0, 6.0, 6.0, 6.0], $look));
    [$x, $y, $w, $h, $radii, $glass, $opacity] = $e->argsOf('glass')[0];

    expect([$x, $y, $w, $h, $opacity])->toBe([20.0, 40.0, 120.0, 60.0, 1.0])
        ->and($radii)->toBe([12.0, 12.0, 12.0, 12.0])
        ->and([$glass->blur, $glass->saturation, $glass->grain, $glass->light, $glass->refraction, $glass->rim])->toBe([18.0, 1.3, 0.5, 0.06, 0.0, null])
        ->and($glass->tint)->toBe($look->tint);
});

it('draws a hairline over the pane, and gives the engine the shadow to paint between the backdrop and the pane', function () {
    $e = new SpyEngine;
    $e->frame(fn () => (new EnginePainter($e, 1.0))->blur(new Rect(40, 40, 100, 60), 8.0, new BlurLook(12.0, null, 1.0, 0.0, 0.0, Color::rgba(255, 255, 255, 0.14), 1.0, 20.0, Color::rgb(0, 0, 0))));

    // the shadow rides in the glass look (the engine reads the backdrop, paints it, then the pane over its rounded box); the outline last
    [, , , , , $glass] = $e->argsOf('glass')[0];
    expect($e->methods())->toContain('glass')->not->toContain('shadow')
        ->and([$glass->shadow, $glass->shadowColor])->toEqual([20.0, Color::rgb(0, 0, 0)])
        ->and(end($e->calls)[0])->toBe('roundRect');
});

it('draws a faded pane\'s shadow as four strips around its box, as the pane would show it through', function () {
    $e = new SpyEngine;
    $e->frame(function () use ($e) {
        $p = new EnginePainter($e, 1.0);
        $p->pushOpacity(0.5);
        $p->blur(new Rect(40, 40, 100, 60), 8.0, new BlurLook(12.0, null, 1.0, 0.0, 0.0, null, 0.0, 20.0, Color::rgb(0, 0, 0)));
        $p->popOpacity();
    });

    [, , , , , $glass] = $e->argsOf('glass')[0];
    expect($glass->shadow)->toBe(0.0)
        ->and(count($e->argsOf('shadow')))->toBe(4);
});

it('paints nothing for a rect with no area or one off the viewport', function () {
    $e = new SpyEngine;
    $e->frame(function () use ($e) {
        $p = new EnginePainter($e, 1.0);
        $p->blur(new Rect(0, 0, 0, 10), 0.0, new BlurLook(12.0));
        $p->blur(new Rect(5000, 5000, 10, 10), 0.0, new BlurLook(12.0));
    });

    expect($e->argsOf('glass'))->toBe([]);
});

/**
 * @return array{\Surface\Contracts\Framebuffers\Framebuffer, VelvetGE}
 */
function blurVelvet(): array
{
    $fb = (new NativeFramebufferDriver)->full(FormatSpec::rgba8(), 48, 32);

    return [$fb, new VelvetGE($fb, new Surface\Rasterize\Native\NativeRasterizeDriver, Edges::HARD)];
}

it('blurs what is under the pane and nothing else, on Velvet', function () {
    [$fb, $g] = blurVelvet();
    $g->frame(function (RenderingEngine $g) {
        $g->clear(Color::rgb(0, 0, 0));
        $g->fillRect(0, 0, 24, 32, Color::rgb(255, 0, 0));
        $g->fillRect(24, 0, 24, 32, Color::rgb(0, 0, 255));
        (new EnginePainter($g, 1.0))->blur(new Rect(10, 8, 28, 16), 0.0, new BlurLook(5.0));
    });

    $edge = $fb->getPixel(24, 16);
    expect(($edge >> 24) & 0xFF)->toBeGreaterThan(60)->toBeLessThan(200)
        ->and(($edge >> 8) & 0xFF)->toBeGreaterThan(60)->toBeLessThan(200)
        ->and($fb->getPixel(9, 16))->toBe(0xFF0000FF)
        ->and($fb->getPixel(38, 16))->toBe(0x0000FFFF)
        ->and($fb->getPixel(12, 2))->toBe(0xFF0000FF);
});

it('tints the pane with the veil\'s share of its colour', function () {
    [$fb, $g] = blurVelvet();
    $g->frame(function (RenderingEngine $g) {
        $g->clear(Color::rgb(0, 0, 0));
        (new EnginePainter($g, 1.0))->blur(new Rect(8, 8, 32, 16), 0.0, new BlurLook(0.0, Color::rgba(200, 100, 50, 0.5)));
    });

    // black under a 50 % veil of (200, 100, 50): (100, 50, 25)
    expect($fb->getPixel(20, 16))->toBe(0x643219FF);
});
