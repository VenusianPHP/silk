<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Host\WindowAction;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Widgets\HeaderBar;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Venusian\Silk\Tests\Support\SpyPainter;

it('drags on the bar, not on a child or a button, and nothing outside it', function () {
    $ui = headerUi();
    $b = $ui->bar->bounds();
    $back = $ui->bar->children()[0];

    expect($ui->bar->hitArea($b->x + 120, $b->y + 2))->toBe(HitArea::Draggable)
        ->and($ui->bar->hitArea(...centre($back)))->toBe(HitArea::Normal)
        ->and($ui->bar->hitArea(...centre_of($ui->bar->buttonRect(WindowAction::Close))))->toBe(HitArea::Normal)
        ->and($ui->bar->hitArea($b->x + 2, $b->y + $b->h + 20))->toBe(HitArea::Normal);
});

it('puts flat 46-wide buttons at the right in minimize, maximize, close order, full height', function () {
    $ui = headerUi();
    [$min, $max, $close] = array_map(fn ($a) => $ui->bar->buttonRect($a), [WindowAction::Minimize, WindowAction::Maximize, WindowAction::Close]);
    $b = $ui->bar->bounds();

    expect([$min->w, $min->h])->toBe([46.0, $b->h])->and($max->x)->toBe($min->x + 46)->and($close->x)->toBe($max->x + 46)
        ->and($close->x + $close->w)->toBe($b->x + $b->w);
});

it('puts the lights at the left in close, minimize, maximize order: 13 wide, 8 apart, 14 in', function () {
    $ui = headerUi(side: Align::Start);
    [$close, $min, $max] = array_map(fn ($a) => $ui->bar->buttonRect($a), [WindowAction::Close, WindowAction::Minimize, WindowAction::Maximize]);
    $b = $ui->bar->bounds();

    expect($ui->bar->lights())->toBeTrue()->and($close->x)->toBe($b->x + 14)->and($close->w)->toBe(13.0)
        ->and($min->x)->toBe($close->x + 21)->and($max->x)->toBe($min->x + 21)
        ->and($ui->bar->children()[0]->bounds()->x)->toBeGreaterThan($max->x + $max->w);
});

it('shows only the buttons the window offers and setButtons allows', function () {
    $ui = headerUi(new RecordingWindowActions([WindowAction::Close]));
    expect($ui->bar->buttons())->toBe([WindowAction::Close]);
    $ui->bar->setButtons(true, true, false);
    expect($ui->bar->buttons())->toBe([]);
});

it('posts ButtonClicked and asks the window on a release over the button pressed', function () {
    $ui = headerUi();
    click($ui->router, ...centre_of($ui->bar->buttonRect(WindowAction::Maximize)));
    click($ui->router, ...centre_of($ui->bar->buttonRect(WindowAction::Close)));
    drag3($ui, centre_of($ui->bar->buttonRect(WindowAction::Minimize)), centre_of($ui->bar->buttonRect(WindowAction::Maximize)));
    $clicked = array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof ButtonClicked));

    expect($ui->actions->done)->toBe([WindowAction::Maximize, WindowAction::Close])
        ->and(array_map(fn ($m) => $m->path, $clicked))->toBe(['window.bar.maximize', 'window.bar.close']);
});

it('kicks the hover by +6 on a click', function () {
    $ui = headerUi();
    $spring = $ui->bar->springs()[2];
    $v = $spring->velocity();
    click($ui->router, ...centre_of($ui->bar->buttonRect(WindowAction::Close)));

    expect($spring->velocity() - $v)->toEqualWithDelta(6.0, 1e-9)->and($spring->preset)->toBe(Silk\Core\Motion\SpringPreset::Bouncy);
});

it('shows the window\'s title unless it has its own, centred in the window when it fits', function () {
    $ui = headerUi(new RecordingWindowActions(title: 'My Window'));
    $ui->bar->setTitle(null);
    $ui->tree->layout(new Size(700, 300));
    $p = new SpyPainter;
    $ui->bar->render($p);
    $title = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text' && $c[1][0] === 'My Window'));
    $w = $ui->tree->measurer()->measure('My Window', $ui->look->font())->w;
    $b = $ui->bar->bounds();

    expect($title)->toHaveCount(1)->and($title[0][1][1])->toBe($b->x + floor(($b->w - $w) / 2));
});

it('packs Align::End children from the right and lets grow share what is left', function () {
    $ui = silkUi(function ($root) {
        $bar = (new HeaderBar('bar', ''))->setButtonSide(Align::End);
        $bar->button('left', 'L');
        $bar->add(new Silk\Core\Layout\Spacer('fill', 0, 10), new Slot(grow: 1));
        $bar->add(new Silk\Widgets\Button('right', 'R'), new Slot(align: Align::End));
        $root->add($bar);
    });
    $ui->tree->setWindowActions(new RecordingWindowActions([]));
    $ui->tree->layout(new Size(300, 300));
    [$left, $fill, $right] = $ui->root->children()[0]->children();
    $b = $ui->root->children()[0]->bounds();

    expect($left->bounds()->x)->toBe($b->x + 8)->and($right->bounds()->x + $right->bounds()->w)->toBe($b->x + $b->w - 8)
        ->and($fill->bounds()->x + $fill->bounds()->w + 6)->toEqualWithDelta($right->bounds()->x, 0.01);
});

it('has 28-wide glass beads 6 apart, 10 in, on a glass bar, in a glass look (header_bar.c:104-108, 359-378)', function () {
    $ui = headerUi();
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current());
    $ui->tree->layout(new Size(300, 300));
    [$min, $max, $close] = array_map(fn ($a) => $ui->bar->buttonRect($a), [WindowAction::Minimize, WindowAction::Maximize, WindowAction::Close]);
    $b = $ui->bar->bounds();
    $spy = new SpyPainter;
    $ui->bar->paint($spy);

    expect([$min->w, $min->h])->toBe([28.0, 28.0])->and($max->x)->toBe($min->x + 34)->and($close->x + $close->w)->toBe($b->x + $b->w - 10)
        ->and(count(array_filter($spy->calls, fn ($c) => ($c[0] ?? '') === 'glass')))->toBe(5); // the bar, three beads, the Back button
});
