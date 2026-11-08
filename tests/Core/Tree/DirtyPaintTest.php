<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Layout\Spacer;
use Silk\Core\Overlay\Popup;
use Silk\Core\Tree\Widget;
use Silk\Painter\EnginePainter;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyPainter;

/** Two buttons far apart in a 300x300 ui, painted once in full. */
function dirtyUi(): object
{
    $ui = silkUi(function ($root) {
        $root->button('a', 'Alpha');
        $root->spacer('gap', 0, 150);
        $root->button('b', 'Beta');
    });
    [$ui->a, , $ui->b] = $ui->root->children();
    $ui->tree->paintAll(new SpyPainter);

    return $ui;
}

/** The single region a paint clipped to. */
function paintedRegion(SpyPainter $p): Rect
{
    $clips = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'pushClip'));
    expect($clips)->not->toBe([]);

    return $clips[0][1][0];
}

/** Rects drawn whose box lies inside $r. */
function rectsWithin(SpyPainter $p, Rect $r): int
{
    return count(array_filter($p->calls, fn ($c) => in_array($c[0], ['rect', 'shadow'], true)
        && $c[1][0]->x >= $r->x - 1e-9 && $c[1][0]->y >= $r->y - 1e-9
        && $c[1][0]->x + $c[1][0]->w <= $r->x + $r->w + 1e-9 && $c[1][0]->y + $c[1][0]->h <= $r->y + $r->h + 1e-9));
}

it('paints the whole viewport on the first frame', function () {
    $ui = silkUi(fn ($root) => $root->button('a', 'Alpha'));
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    expect(paintedRegion($p))->toEqual(new Rect(0, 0, 300, 300));
});

it('paints only the hovered button (and what overlaps it) on a hover frame, in one clip', function () {
    $ui = dirtyUi();
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, ...centre($ui->a)));
    $ui->tree->advance(1 / 60);
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    $region = paintedRegion($p);
    $extent = $ui->a->paintExtent();
    expect($region)->toEqual($extent)
        ->and(count(array_filter($p->calls, fn ($c) => $c[0] === 'pushClip')))->toBe(1)
        ->and($region->intersect($ui->b->bounds()))->toBeNull()
        ->and(array_column(array_filter($p->calls, fn ($c) => $c[0] === 'text'), 1))->toHaveCount(1)
        ->and(array_column(array_filter($p->calls, fn ($c) => $c[0] === 'text'), 1)[0][0])->toBe('Alpha');
});

it('hands the engine the union in device pixels as its clip, and the surface after', function () {
    $ui = dirtyUi();
    $ui->a->hover()->target(1.0);
    $ui->a->invalidatePaint();
    $e = new SpyEngine(600, 600);

    $e->frame(fn () => $ui->tree->paintAll(new EnginePainter($e, 2.0)));

    $x = $ui->a->paintExtent();
    $clips = $e->argsOf('clip');
    expect(end($clips)[0])->toEqual(new Surface\Contracts\Framebuffers\Region(0, 0, 600, 600))
        ->and($clips[0][0])->toEqual(new Surface\Contracts\Framebuffers\Region(
            (int) floor($x->x * 2 + 0.5), (int) floor($x->y * 2 + 0.5),
            (int) floor(($x->x + $x->w) * 2 + 0.5) - (int) floor($x->x * 2 + 0.5),
            (int) floor(($x->y + $x->h) * 2 + 0.5) - (int) floor($x->y * 2 + 0.5)));
});

it('paints nothing when nothing needs paint', function () {
    $ui = dirtyUi();
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    expect($p->calls)->toBe([]);
});

it('includes where a moved widget was and where it is now', function () {
    $ui = dirtyUi();
    $before = $ui->b->paintExtent();
    $ui->root->children()[1]->setSize(0, 100);
    $ui->tree->layout(new Size(300, 300));
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    $region = paintedRegion($p);
    expect($region->intersect($before))->toEqual($before)
        ->and($region->intersect($ui->b->paintExtent()))->toEqual($ui->b->paintExtent());
});

it('clears where a hidden widget was', function () {
    $ui = dirtyUi();
    $was = $ui->b->paintExtent();
    $ui->b->setVisible(false);
    $ui->tree->layout(new Size(300, 300));
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    expect(paintedRegion($p)->intersect($was))->toEqual($was)->and(rectsWithin($p, $was))->toBeGreaterThanOrEqual(1);
});

it('includes an opening popup with its shadow, and clears it once it has gone', function () {
    $ui = dirtyUi();
    $popup = (new Popup('menu', 0, 0))->in($ui->tree);
    $popup->add(new Spacer('x', 60, 40));
    $popup->openAt(150, 150);
    $ui->tree->layout(new Size(300, 300));
    $ui->tree->advance(1 / 60);
    $p = new SpyPainter;
    $ui->tree->paintAll($p);
    $shown = $popup->paintExtent();
    expect(paintedRegion($p)->intersect($shown))->toEqual($shown)
        ->and($shown->x)->toBeLessThan($popup->bounds()->x - Popup::SHADOW + 1e-9);

    $popup->close();
    for ($i = 0; $i < 300 && $ui->tree->advance(1 / 60); $i++) {
        $ui->tree->paintAll(new SpyPainter);
    }
    $last = new SpyPainter;
    $ui->tree->paintAll($last);
    expect($ui->tree->overlays()->all())->toBe([]);
});

it('repaints everything on a viewport change, a scale change and a theme change', function () {
    $ui = dirtyUi();

    $ui->tree->layout(new Size(320, 300));
    $p = new SpyPainter;
    $ui->tree->paintAll($p);
    expect(paintedRegion($p))->toEqual(new Rect(0, 0, 320, 300));

    $ui->tree->repaintAll();
    $q = new SpyPainter;
    $ui->tree->paintAll($q);
    expect(paintedRegion($q))->toEqual(new Rect(0, 0, 320, 300));

    $looks = (new Silk\Looks\LookManager)->extend('raven-dark', fn () => Silk\Looks\RavenDark::theme())->use('raven-dark');
    $looks->watch($ui->tree)->use('raven-dark');
    $r = new SpyPainter;
    $ui->tree->paintAll($r);
    expect(paintedRegion($r))->toEqual(new Rect(0, 0, 320, 300));
});

it('grows the extent with a playing transition', function () {
    $ui = dirtyUi();
    $ui->a->appear(Silk\Core\Motion\Transition::FlyUp)->setVisible(false);
    $ui->tree->layout(new Size(300, 300));
    $ui->a->setVisible(true);
    $ui->tree->layout(new Size(300, 300));
    $ui->tree->advance(1 / 60);

    expect($ui->a->paintExtent()->y + $ui->a->paintExtent()->h)->toBeGreaterThan($ui->a->bounds()->y + $ui->a->bounds()->h + 20);
});
