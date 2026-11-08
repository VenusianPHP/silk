<?php

use Silk\Contracts\Host\WindowAction;
use Silk\Core\Tree\Tree;
use Silk\Hosts\StagedHost;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Column;
use Silk\Widgets\HeaderBar;
use Surface\Contracts\Windows\HitArea;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowMode;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyLoop;
use Venusian\Surface\Tests\Fixtures\FakeSession;


it('installs a hit test that maps points to units and asks the HeaderBar', function () {
    $ui = stagedUi();
    $b = $ui->bar->bounds();
    $close = $ui->bar->buttonRect(WindowAction::Close);

    // points are units x 2
    // past the buttons, whichever side the system puts them on
    expect(($ui->test)((int) (2 * ($b->x + $b->w / 2)), (int) (2 * ($b->y + $b->h / 2))))->toBe(HitArea::Draggable)
        ->and(($ui->test)((int) (2 * ($close->x + $close->w / 2)), (int) (2 * ($close->y + $close->h / 2))))->toBe(HitArea::Normal)
        ->and(($ui->test)(200, 250))->toBe(HitArea::Normal);
});

it('resizes in a 6 u band at the window edges, diagonally within 18 u of a corner', function () {
    $ui = stagedUi();
    [$w, $h] = [900, 640];

    expect(($ui->test)(450, 2 * 2))->toBe(HitArea::ResizeTop)
        ->and(($ui->test)(2, 2))->toBe(HitArea::ResizeTopLeft)
        ->and(($ui->test)(2 * 17, 2))->toBe(HitArea::ResizeTopLeft)
        ->and(($ui->test)($w - 2, 320))->toBe(HitArea::ResizeRight)
        ->and(($ui->test)($w - 2, $h - 2))->toBe(HitArea::ResizeBottomRight)
        ->and(($ui->test)(450, $h - 2))->toBe(HitArea::ResizeBottom)
        ->and(($ui->test)(2, 320))->toBe(HitArea::ResizeLeft)
        ->and(($ui->test)(2 * 7, 320))->toBe(HitArea::Normal);
});

it('offers the window actions and runs them on the window', function () {
    $ui = stagedUi();
    expect($ui->host->windowActions())->toBe(WindowAction::cases())->and($ui->tree->windowActions())->toBe($ui->host);
    $ui->host->performWindowAction(WindowAction::Maximize);
    expect($ui->window->mode())->toBe(WindowMode::Maximized);
    $ui->host->performWindowAction(WindowAction::Maximize);
    expect($ui->window->mode())->toBe(WindowMode::Windowed);
    $ui->host->performWindowAction(WindowAction::Minimize);
    expect($ui->window->mode())->toBe(WindowMode::Minimized);
    $ui->host->performWindowAction(WindowAction::Close);
    expect($ui->window->isOpen())->toBeFalse();
});

it('installs no hit test on a stager without HitTest', function () {
    $ui = stagedUi([]);
    expect($ui->test)->toBeNull();
});
