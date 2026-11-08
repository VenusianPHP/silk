<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\ClipStack;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Framebuffers\Region;
use Venusian\Silk\Tests\Support\SpyEngine;

it('intersects on push and restores on pop', function () {
    $s = new ClipStack(new Rect(0, 0, 400, 300));
    $s->push(new Rect(0, 0, 100, 100));
    $s->push(new Rect(50, 50, 100, 100));

    expect($s->current())->toEqual(new Rect(50, 50, 50, 50));

    $s->pop();
    expect($s->current())->toEqual(new Rect(0, 0, 100, 100));

    $s->pop();
    expect($s->current())->toEqual(new Rect(0, 0, 400, 300));
});

it('keeps an empty clip empty', function () {
    $s = new ClipStack(new Rect(0, 0, 400, 300));
    $s->push(new Rect(0, 0, 10, 10));
    $s->push(new Rect(50, 50, 10, 10));

    expect($s->current()->w)->toBe(0.0)->and($s->isEmpty())->toBeTrue();
});

it('refuses a pop with nothing pushed', function () {
    (new ClipStack(new Rect(0, 0, 1, 1)))->pop();
})->throws(LogicException::class);

it('hands the engine the intersected clip on push and the outer clip on pop', function () {
    $e = new SpyEngine;
    $e->frame(fn () => (new EnginePainter($e, 2.0))
        ->pushClip(new Rect(0, 0, 100, 100))
        ->pushClip(new Rect(50.2, 50.2, 100, 100))
        ->popClip());

    expect($e->methods())->toBe(['clip', 'clip', 'clip'])
        ->and($e->argsOf('clip'))->toEqual([[new Region(0, 0, 200, 200)], [new Region(100, 100, 100, 100)], [new Region(0, 0, 200, 200)]]);
});

it('gives the engine the whole surface after the last pop', function () {
    $e = new SpyEngine(400, 300);
    $e->frame(fn () => (new EnginePainter($e, 1.0))
        ->pushClip(new Rect(350, 250, 200, 200))
        ->pushClip(new Rect(500, 500, 10, 10))
        ->popClip()
        ->popClip());

    expect($e->argsOf('clip'))->toEqual([[new Region(350, 250, 50, 50)], [new Region(350, 250, 0, 0)], [new Region(350, 250, 50, 50)], [new Region(0, 0, 400, 300)]]);
});
