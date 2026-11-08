<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\GlassLook;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Rasterize\Edges;
use Surface\Drawing\Velvet\VelvetGE;
use Surface\Framebuffers\Native\NativeFramebufferDriver;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;

/*
 * Painter::glass(): Raven's glass primitive (draw.c:504, ui.frag:92-148) as a Surface glass pane.
 */

it('lays the slab as a glass pane in device pixels: the wide frost blur, the refraction over the bevel, the rim light', function () {
    $e = new SpyEngine(200, 120);
    $look = new GlassLook(Color::rgba(20, 20, 30, 0.3), Color::rgba(255, 255, 255, 0.55), 0.8, 10.0, 1.5, 0.1, 9.0);
    $e->frame(fn () => (new EnginePainter($e, 1.0))->glass(new Rect(40, 30, 100, 50), 12.0, $look));
    [$x, $y, $w, $h, $radii, $glass, $opacity] = $e->argsOf('glass')[0];

    expect([$x, $y, $w, $h, $opacity])->toBe([40.0, 30.0, 100.0, 50.0, 1.0])
        ->and($radii)->toBe([12.0, 12.0, 12.0, 12.0])
        ->and([$glass->blur, $glass->frost, $glass->refraction, $glass->bevel, $glass->saturation, $glass->light])->toBe([22.0, 0.8, 10.0, 9.0, 1.5, 0.1])
        ->and([$glass->tint, $glass->rim])->toBe([$look->tint, $look->rim])
        ->and($e->methods())->not->toContain('layer');
});

it('bends and frosts what is behind the slab on Velvet, and leaves outside it alone', function () {
    $fb = (new NativeFramebufferDriver)->full(FormatSpec::rgba8(), 120, 80);
    $g = new VelvetGE($fb, new Surface\Rasterize\Native\NativeRasterizeDriver, Edges::HARD);
    $g->frame(function (RenderingEngine $g) {
        $g->clear(Color::rgb(0, 0, 0));
        $g->fillRect(0, 0, 60, 80, Color::rgb(255, 0, 0));
        $g->fillRect(60, 0, 60, 80, Color::rgb(0, 0, 255));
        (new EnginePainter($g, 1.0))->glass(new Rect(30, 20, 60, 40), 8.0, new GlassLook(null, Color::rgba(255, 255, 255, 0.55), 1.0, 8.0, 1.0));
    });

    $mid = $fb->getPixel(60, 40);
    expect(($mid >> 24) & 0xFF)->toBeGreaterThan(40)->toBeLessThan(215)
        ->and(($mid >> 8) & 0xFF)->toBeGreaterThan(40)->toBeLessThan(215)
        ->and($fb->getPixel(20, 40))->toBe(0xFF0000FF)
        ->and($fb->getPixel(100, 40))->toBe(0x0000FFFF)
        ->and($fb->getPixel(60, 5))->toBe(0x0000FFFF);
});
