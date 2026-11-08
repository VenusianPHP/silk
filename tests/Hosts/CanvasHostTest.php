<?php

use Silk\Core\Tree\Tree;
use Silk\Hosts\CanvasHost;
use Silk\Hosts\FrameLoop;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Column;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Venusian\Silk\Tests\Support\SpyEngine;

/**
 * A TKCanvas stub of $w x $h points at scale 2 and a CanvasHost over it. 'cpu' engines draw into the
 * canvas's own framebuffer (as Velvet over 'output'); 'gpu' engines own their target (as Metal, which
 * borrows the view's layer and follows its size in begin()).
 */
function canvasHost(TKCanvas $canvas, int $w, int $h, string $kind = 'cpu', ?string $engine = null, bool $surfaceDefault = false, FrameLoop $frames = new FrameLoop): object
{
    $t = new stdClass;
    $t->size = [$w, $h];
    $t->bound = null;
    $t->removed = false;
    $gone = function () use ($t) {
        if ($t->removed) {
            throw new LogicException("Primitive 'page.silk' was removed.");
        }
    };
    $canvas->method('isRemoved')->willReturnCallback(fn () => $t->removed);
    $t->made = [];
    $t->presents = 0;
    $canvas->method('uuid')->willReturn('canvas-uuid');
    $canvas->method('size')->willReturnCallback(fn () => $gone() ?? $t->size);
    $canvas->method('pixelSize')->willReturnCallback(fn () => $gone() ?? [$t->size[0] * 2, $t->size[1] * 2]);
    $canvas->method('boundFramebuffer')->willReturnCallback(fn () => $t->bound);
    $canvas->method('present')->willReturnCallback(function () use ($t, $canvas, $gone) {
        $gone();
        $t->presents++;

        return $canvas;
    });
    foreach (['watchPointer', 'setFocusable', 'watchSize'] as $m) {
        $canvas->method($m)->willReturnSelf();
    }

    $t->root = new Column('bar', 6, 10);
    $t->root->button('ok', 'OK');
    $look = (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark')->current();
    $t->host = new CanvasHost($canvas, new Tree($t->root, $look), function (TKCanvas $c, ?string $name) use ($t, $kind) {
        [$pw, $ph] = $c->pixelSize();
        $t->made[] = $name;
        if ($kind === 'gpu') {
            return new SpyEngine($pw, $ph);
        }
        $t->bound = new NativeFullFramebuffer(FormatSpec::rgba8(), $pw, $ph);

        return new SpyEngine($pw, $ph, $t->bound);
    }, frames: $frames, engine: $surfaceDefault ? null : ($engine ?? $kind));

    return $t;
}

function resized(object $t): bool
{
    return $t->host->handles(new ViewResized('main', 'page.silk', 'canvas-uuid', width: $t->size[0], height: $t->size[1]));
}

it('makes its engine for the canvas output and lays out in points at scale 2', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200);

    expect(resized($t))->toBeTrue();
    $t->host->frame();

    expect($t->made)->toBe(['cpu'])
        ->and([$t->host->engine()->width(), $t->host->engine()->height()])->toBe([1280, 400])
        ->and($t->host->scale())->toBe(2.0)
        ->and([$t->root->bounds()->w, $t->root->bounds()->h])->toBe([640.0, 200.0])
        ->and($t->presents)->toBe(1);
});

it('takes the engine name it is given (Metal by one setting)', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, 'gpu', engine: 'metal');

    resized($t);

    expect($t->made)->toBe(['metal']);
});

it('leaves the engine to Surface when none is given: the maker gets null', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, surfaceDefault: true);

    resized($t);

    expect($t->made)->toBe([null])
        ->and($t->host->engineName())->toBeNull();
});

it('makes a new engine on resize when it draws into the canvas framebuffer, and keeps it at the same size', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, 'cpu');
    resized($t);
    resized($t);
    expect($t->made)->toHaveCount(1);

    $t->size = [800, 300];
    resized($t);
    $t->host->frame();

    expect($t->made)->toHaveCount(2)
        ->and([$t->host->engine()->width(), $t->host->engine()->height()])->toBe([1600, 600])
        ->and($t->root->bounds()->w)->toBe(800.0);
});

it('keeps an engine that owns its target across a resize: it follows its output itself', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, 'gpu');
    resized($t);
    $engine = $t->host->engine();

    $t->size = [800, 300];
    resized($t);
    $t->host->frame();

    expect($t->made)->toHaveCount(1)->and($t->host->engine())->toBe($engine)
        ->and($t->root->bounds()->w)->toBe(800.0)
        ->and($t->presents)->toBe(1);
});

it('ignores mail for another canvas', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200);

    expect($t->host->handles(new ViewResized('main', 'x', 'other-uuid', width: 1, height: 1)))->toBeFalse()->and($t->made)->toBe([]);
});

it('pauses on a 0x0 canvas and makes its engine once it has a size', function () {
    $t = canvasHost($this->createStub(TKCanvas::class), 0, 0);

    resized($t);
    $t->host->frame();
    expect($t->host->engine())->toBeNull()->and($t->made)->toBe([]);

    $t->size = [640, 200];
    resized($t);
    expect($t->made)->toHaveCount(1);
});

it('touches nothing once its canvas is removed (the window closed before the sketch stopped)', function (string $kind) {
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, $kind);
    resized($t);
    $t->host->frame();
    $t->root->children()[0]->invalidatePaint();

    $t->removed = true;
    $t->host->frame();
    $t->host->present();
    $t->size = [800, 300];

    expect(resized($t))->toBeTrue()->and($t->presents)->toBe(1)->and($t->made)->toHaveCount(1);
})->with(['cpu', 'gpu']);

it('paints nothing when its canvas goes during the frame (a HeaderBar close dispatched while the frame drains)', function (string $kind) {
    $closing = false;
    $t = canvasHost($this->createStub(TKCanvas::class), 640, 200, $kind, frames: new FrameLoop(now: function () use (&$t, &$closing) {
        $closing && $t->removed = true; // the frame's clock reads after its events are dispatched

        return 16_000_000;
    }));
    resized($t);
    $t->host->frame();
    $engine = $t->host->engine();
    $drawn = count($engine->calls);

    $closing = true;
    $t->root->children()[0]->invalidatePaint();
    $t->host->frame();

    expect($t->removed)->toBeTrue()->and(count($engine->calls))->toBe($drawn)->and($t->presents)->toBe(1);
})->with(['cpu', 'gpu']);
