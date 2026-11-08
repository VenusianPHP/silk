<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\MenuBar;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\MenuActivated;

it('opens a title\'s menu below it on a click and closes it on a second click', function () {
    $ui = menuBarUi();
    click($ui->router, ...centre($ui->titles[0]));
    settleMenus($ui);
    $menu = $ui->bar->openMenu();
    expect($menu)->toBe($ui->titles[0]->menu)->and($menu->bounds()->y)->toBeGreaterThanOrEqual($ui->titles[0]->bounds()->y + $ui->titles[0]->bounds()->h - 0.01);
    click($ui->router, ...centre($ui->titles[0]));
    settleMenus($ui);
    expect($ui->bar->openMenu())->toBeNull();
});

it('switches menus on hover while one is open, and not otherwise', function () {
    $ui = menuBarUi();
    [$x, $y] = centre($ui->titles[1]);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y));
    expect($ui->bar->openMenu())->toBeNull();
    click($ui->router, ...centre($ui->titles[0]));
    settleMenus($ui);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y));
    expect($ui->bar->openMenu())->toBe($ui->titles[1]->menu)->and($ui->titles[0]->menu->isOpen())->toBeFalse();
});

it('does not step between bar menus with Left and Right, as Raven; Right still opens a submenu', function () {
    $ui = menuBarUi();
    click($ui->router, ...centre($ui->titles[1]));
    settleMenus($ui);
    pressKey($ui->router, Key::LEFT);
    expect($ui->bar->openMenu())->toBe($ui->titles[1]->menu);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::RIGHT);
    expect($ui->bar->openMenu())->toBe($ui->titles[1]->menu)->and($ui->titles[1]->menu->highlighted()->submenu()->isOpen())->toBeTrue();
});

it('places titles 2 units in, inset 2, each its text plus PaddingX a side', function () {
    $ui = menuBarUi();
    $b = $ui->bar->bounds();
    $t = $ui->titles[0]->bounds();

    expect([$t->x - $b->x, $t->y - $b->y, $t->h])->toBe([2.0, 2.0, $b->h - 4])
        ->and($b->h)->toBe($ui->look->metric(Silk\Contracts\Look\Metric::ControlHeight))
        ->and($ui->titles[1]->bounds()->x)->toBe($t->x + $t->w);
});

it('closes on Escape and gives focus back', function () {
    $ui = menuBarUi();
    $below = $ui->root->children()[1];
    $ui->focus->focus($below);
    click($ui->router, ...centre($ui->titles[0]));
    settleMenus($ui);
    pressKey($ui->router, Key::ESCAPE);
    settleMenus($ui);

    expect($ui->bar->openMenu())->toBeNull()->and($ui->focus->focused())->toBe($below);
});

it('slides the open-title mark on BOUNCY springs', function () {
    $ui = menuBarUi();
    click($ui->router, ...centre($ui->titles[0]));
    $ui->bar->render(new Venusian\Silk\Tests\Support\SpyPainter);
    [$ox, $oy] = centre($ui->titles[1]);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $ox, y: $oy));
    $ui->bar->render(new Venusian\Silk\Tests\Support\SpyPainter);
    [$x, $w] = array_map(fn ($s) => $s->value(), $ui->bar->springs());
    expect($x)->toBe($ui->titles[0]->bounds()->x)
        ->and($ui->bar->springs()[0]->preset)->toBe(Silk\Core\Motion\SpringPreset::Bouncy);
    for ($i = 0; $i < 120; $i++) {
        $ui->tree->advance(1 / 60);
    }
    expect($ui->bar->springs()[0]->value())->toEqualWithDelta($ui->titles[1]->bounds()->x, 0.01)
        ->and($ui->bar->springs()[1]->value())->toEqualWithDelta($ui->titles[1]->bounds()->w, 0.01);
});

it('keeps a menu from the rightmost title inside the host', function () {
    $ui = menuBarUi();
    click($ui->router, ...centre($ui->titles[3]));
    settleMenus($ui);
    $m = $ui->bar->openMenu()->bounds();

    expect($m->x)->toBeGreaterThanOrEqual(0.0)->and($m->x + $m->w)->toBeLessThanOrEqual(300.0);
});

it('opens nothing from a disabled title; an item posts MenuActivated', function () {
    $ui = menuBarUi();
    click($ui->router, ...centre($ui->titles[2]));
    settleMenus($ui);
    expect($ui->bar->openMenu())->toBeNull();
    click($ui->router, ...centre($ui->titles[0]));
    settleMenus($ui);
    click($ui->router, ...centre($ui->titles[0]->menu->children()[0]));

    expect(array_values(array_filter($ui->loop->posted, fn ($m) => $m instanceof MenuActivated))[0]->item)->toBe('quit');
});
