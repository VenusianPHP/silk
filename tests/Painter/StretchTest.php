<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Drawing\Velvet\VelvetGE;
use Surface\Fonts\ClassicFont;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;

it('keeps a uniform push on the painter: the engine transform is not touched', function () {
    $e = new SpyEngine;
    $seen = null;
    $e->frame(function () use ($e, &$seen) {
        (new EnginePainter($e, 2.0))->pushTransform(1.5, 3, 4)->pushTransform(2.0, 0, 0, 2.0);
        $seen = $e->matrix();
    });

    expect($seen->toArray())->toEqual(Affine::identity()->toArray());
});

it('sets the engine\'s affine for a stretch, in device pixels, inside the current transform, and pops it back', function () {
    $e = new SpyEngine;
    [$inside, $after] = [null, null];
    $e->frame(function () use ($e, &$inside, &$after) {
        $p = new EnginePainter($e, 2.0);
        $p->pushTransform(1.0, 10, 0)->pushTransform(1.2, 5, 6, 0.8);
        $inside = $e->matrix();
        $p->popTransform();
        $after = $e->matrix();
    });

    // x: (5 + 10) x 2 device, scaled 1.2; y: 6 x 2, scaled 0.8
    expect($inside->toArray())->toEqual(Affine::translation(30.0, 12.0)->multiply(Affine::scaling(1.2, 0.8))->toArray())
        ->and($after->toArray())->toEqual(Affine::identity()->toArray());
});

it('stretches text with the shapes on a real engine (Velvet): x by sx, y by sy', function () {
    $ink = function (?float $sx, ?float $sy): array {
        $v = new VelvetGE(new NativeFullFramebuffer(FormatSpec::rgba8(), 120, 60), new Surface\Rasterize\Native\NativeRasterizeDriver, Surface\Contracts\Rasterize\Edges::HARD);
        $v->frame(function () use ($v, $sx, $sy) {
            $v->clear(Color::rgb(0, 0, 0));
            $p = new EnginePainter($v, 1.0, new ClassicFont);
            $sx !== null && $p->pushTransform($sx, 0, 0, $sy);
            $p->text('Ab', 4, 4, Color::rgb(255, 255, 255), new Silk\Contracts\Look\FontSpec);
            $p->rect(new Rect(4, 20, 10, 4), Color::rgb(255, 0, 0));
            $sx !== null && $p->popTransform();
        });
        $px = $v->framebuffer()->toRgba8();
        $box = fn (int $channel, bool $only) => (function () use ($px, $channel, $only) {
            [$xs, $ys] = [[], []];
            for ($y = 0; $y < 60; $y++) {
                for ($x = 0; $x < 120; $x++) {
                    $o = ($y * 120 + $x) * 4;
                    if (ord($px[$o + $channel]) > 0 && (! $only || ord($px[$o + 1]) === 0)) {
                        [$xs[], $ys[]] = [$x, $y];
                    }
                }
            }

            return [max($xs) - min($xs) + 1, max($ys) - min($ys) + 1];
        })();

        return [$box(1, false), $box(0, true)];
    };
    [[$tw, $th], [$rw, $rh]] = $ink(null, null);

    expect($ink(2.0, 1.0))->toBe([[2 * $tw, $th], [2 * $rw, $rh]])
        ->and($ink(1.0, 2.0))->toBe([[$tw, 2 * $th], [$rw, 2 * $rh]]);
});
