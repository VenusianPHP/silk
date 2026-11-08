<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Layout\Spacer;
use Silk\Core\Motion\SpringPreset;
use Silk\Core\Overlay\Placement;
use Silk\Core\Overlay\Popup;
use Surface\Contracts\HumanInput\Key;
use Venusian\Silk\Tests\Support\SpyPainter;

/** A 300x300 ui with a button 'ok' and a popup holding a 100x40 spacer. */
function popupUi(float $w = 100, float $h = 40): object
{
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ui->ok = $ui->root->children()[0];
    $ui->popup = (new Popup('menu', 0, 0))->in($ui->tree);
    $ui->popup->add(new Spacer('body', $w, $h));

    return $ui;
}

function settle(object $ui): void
{
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(300, 300));
}

it('opens below its anchor', function () {
    $ui = popupUi();

    $ui->popup->open($ui->ok, Placement::Below);
    $ui->tree->layout(new Size(300, 300));

    $a = $ui->ok->bounds();
    expect($ui->popup->isOpen())->toBeTrue()
        ->and($ui->tree->overlays()->top())->toBe($ui->popup)
        ->and($ui->popup->bounds()->x)->toBe($a->x)
        ->and($ui->popup->bounds()->y)->toBe($a->y + $a->h)
        ->and($ui->popup->path())->toBe('menu');
});

it('places above, right, left and at a point', function () {
    $ui = popupUi(40, 20);
    $a = $ui->ok->bounds();

    $ui->popup->open($ui->ok, Placement::Above);
    $ui->tree->layout(new Size(300, 300));
    $above = $ui->popup->bounds();
    $ui->popup->openAt(50, 60);
    $ui->tree->layout(new Size(300, 300));
    $at = $ui->popup->bounds();

    expect($above->y)->toBeGreaterThanOrEqual(0.0)
        ->and([$at->x, $at->y])->toBe([50.0, 60.0]);
});

it('clamps a popup near the edges inside the host, and one larger than the host to its size (Review Focus 1)', function () {
    $ui = popupUi(120, 80);
    $ui->popup->openAt(250, 280);
    $ui->tree->layout(new Size(300, 300));
    $b = $ui->popup->bounds();
    expect($b->x + $b->w)->toBeLessThanOrEqual(300.0)->and($b->y + $b->h)->toBeLessThanOrEqual(300.0)
        ->and($b->x)->toBeGreaterThanOrEqual(0.0)->and($b->y)->toBeGreaterThanOrEqual(0.0);

    $big = popupUi(500, 400);
    $big->popup->openAt(10, 10);
    $big->tree->layout(new Size(300, 300));
    expect($big->popup->bounds())->toEqual(new Silk\Contracts\Geometry\Rect(0, 0, 300, 300));
});

it('grows out of its anchor on a bouncy spring: scale 0.72 + 0.28 shown, opacity min(1, shown x 1.8)', function () {
    $ui = popupUi();
    $ui->popup->open($ui->ok);
    $ui->tree->layout(new Size(300, 300));
    $ui->tree->advance(1 / 60);
    $shown = $ui->popup->shown();
    $p = new SpyPainter;

    $ui->tree->paintAll($p);

    $calls = array_column(array_filter($p->calls, fn ($c) => in_array($c[0], ['pushOpacity', 'pushTransform'])), 1, 0);
    expect($ui->popup->springs()[0]->preset)->toBe(SpringPreset::Bouncy)
        ->and($shown)->toBeGreaterThan(0.0)->toBeLessThan(1.0)
        ->and($calls['pushTransform'][0])->toEqualWithDelta(0.72 + 0.28 * $shown, 1e-9)
        ->and($calls['pushOpacity'][0])->toEqualWithDelta(min(1.0, $shown * 1.8), 1e-9);
});

it('closes on an outside click and eats it (Review Focus 2)', function () {
    $ui = popupUi();
    $ui->popup->openAt(150, 150);
    settle($ui);

    click($ui->router, ...centre($ui->ok));

    expect($ui->popup->isOpen())->toBeFalse()
        ->and($ui->loop->posted)->toBe([])
        ->and($ui->ok->isPressed())->toBeFalse();
});

it('closes only the top popup on Escape', function () {
    $ui = popupUi();
    $second = (new Popup('sub', 0, 0))->in($ui->tree);
    $second->add(new Spacer('x', 20, 20));
    $ui->popup->openAt(10, 100);
    $second->openAt(150, 150);
    settle($ui);

    pressKey($ui->router, Key::ESCAPE);

    expect($second->isOpen())->toBeFalse()->and($ui->popup->isOpen())->toBeTrue();
});

it('lets a modal popup take every event under it', function () {
    $ui = popupUi();
    $ui->popup->setModal(true)->openAt(150, 150);
    settle($ui);

    click($ui->router, ...centre($ui->ok));

    expect($ui->popup->isOpen())->toBeTrue()->and($ui->loop->posted)->toBe([])
        ->and($ui->tree->hitTest(...centre($ui->ok)))->toBe($ui->popup);
});

it('paints a closing popup but does not hit it, then drops it once shown reaches 0', function () {
    $ui = popupUi();
    $ui->popup->openAt(150, 150);
    settle($ui);
    $inside = [160.0, 160.0];
    expect($ui->tree->hitTest(...$inside))->not->toBe($ui->tree->root);

    $ui->popup->close();
    $ui->tree->advance(1 / 60);
    $p = new SpyPainter;
    $ui->tree->paintAll($p);

    expect($ui->tree->hitTest(...$inside))->toBe($ui->tree->root)
        ->and(count($p->calls))->toBeGreaterThan(count((function () use ($ui) {
            $q = new SpyPainter;
            $ui->tree->root->render($q);

            return $q->calls;
        })()));

    settle($ui);
    expect($ui->tree->overlays()->top())->toBeNull()->and($ui->popup->shown())->toBe(0.0);
});
