<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Tree\Tree;
use Silk\Hosts\FrameLoop;
use Silk\Hosts\Host;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Column;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyLoop;

/** A host over a SpyEngine of 400x300 pixels at pixel scale 2 (200x150 points); 16 ms per frame. */
function testHost(?callable $build = null): object
{
    $root = new Column('window', 6, 10);
    $root->button('ok', 'OK');
    $build && $build($root);
    $look = (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark')->current();
    $loop = new SpyLoop;
    $tree = new Tree($root, $look, $loop, 'main');
    $t = 0;
    $frames = new FrameLoop(now: function () use (&$t) { return $t += 16_000_000; });
    $engine = new SpyEngine(400, 300);
    $host = new class($tree, $frames) extends Host {
        public int $presents = 0;

        public function present(): void
        {
            $this->presents++;
        }
    };
    $host->bind($engine, 200, 150, 2.0);

    return (object) compact('host', 'engine', 'tree', 'root', 'loop');
}

it('paints and presents the first frame, then an idle frame does nothing', function () {
    $t = testHost();

    $t->host->frame();
    expect($t->engine->calls)->not->toBe([])->and($t->host->presents)->toBe(1);

    $t->engine->calls = [];
    $t->host->frame();
    expect($t->engine->calls)->toBe([])->and($t->host->presents)->toBe(1);
});

it('paints while a spring moves and stops once it rests', function () {
    $t = testHost();
    $t->host->frame();
    $ok = $t->root->children()[0];

    $ok->hover()->target(1.0);
    $t->engine->calls = [];
    $t->host->frame();
    expect($t->engine->calls)->not->toBe([]);

    for ($i = 0; $i < 60 && ! $ok->hover()->atRest(); $i++) {
        $t->host->frame();
    }
    expect($ok->hover()->atRest())->toBeTrue();

    $t->host->frame(); // the frame that paints the resting value
    $t->engine->calls = [];
    $presents = $t->host->presents;
    $t->host->frame();
    expect($t->engine->calls)->toBe([])->and($t->host->presents)->toBe($presents);
});

it('keeps spring values across a scale change and repaints at the new scale', function () {
    $t = testHost();
    $t->host->frame();
    $ok = $t->root->children()[0];
    $ok->hover()->target(1.0);
    $t->host->frame();
    $mid = $ok->hover()->value();

    $t->host->setPixelScale(1.0);

    expect($ok->hover()->value())->toBe($mid)
        ->and($t->host->scale())->toBe(1.0)
        // a scale change asks for a full repaint
        ->and($t->tree->wantsPaint())->toBeTrue();
});

it('skips layout, paint and present at 0x0 and recovers', function () {
    $t = testHost();
    $t->host->bind(null, 0, 0, 2.0);

    $t->host->frame();
    expect($t->host->presents)->toBe(0);

    $t->host->bind($t->engine, 200, 150, 2.0);
    $t->host->frame();
    expect($t->host->presents)->toBe(1)->and($t->root->bounds()->w)->toBe(200.0);
});

it('routes queued events before painting', function () {
    $t = testHost();
    $t->host->frame();
    $b = $t->root->children()[0]->bounds();
    $at = ['x' => $b->x + 5, 'y' => $b->y + 5, 'button' => MouseButton::LEFT, 'clicks' => 1];

    $t->host->enqueue(new SilkEvent(EventType::MouseDown, ...$at));
    $t->host->enqueue(new SilkEvent(EventType::MouseUp, ...$at));
    $t->host->frame();

    expect($t->loop->names())->toBe(['view.clicked.main.window.ok']);
});

it('relays out when the size changes', function () {
    $t = testHost();
    $t->host->frame();

    $t->host->bind($t->engine, 100, 150, 2.0);
    $t->host->frame();

    expect($t->root->bounds()->w)->toBe(100.0);
});
