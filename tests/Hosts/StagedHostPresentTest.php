<?php

use Silk\Core\Tree\Tree;
use Silk\Hosts\StagedHost;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Silk\Widgets\Column;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyLoop;
use Venusian\Surface\Tests\Fixtures\FakeSession;

/** A 400x300 staged window (scale 2: 200x150 points) and a StagedHost drawing with a SpyEngine over $over(window). */
function presentUi(?Closure $over = null): object
{
    $window = (new FakeStager(new FakeSession))->openStaged('stage', 400, 300, []);
    $root = new Column('window', 6, 10);
    $root->button('ok', 'OK');
    $look = (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark')->current();
    $engine = $over ? $over($window) : new SpyEngine(400, 300, $window->framebuffer());
    $host = new StagedHost($window, new Tree($root, $look, new SpyLoop, 'stage'), $engine);

    return (object) ['window' => $window, 'host' => $host, 'engine' => $engine, 'shown' => fn (): int => count($window->pixels) + count($window->addresses)];
}

it('shows the window what the engine drew over its framebuffer (Velvet does not present by itself)', function () {
    $ui = presentUi();

    $ui->host->frame();

    expect($ui->engine->calls)->not->toBe([])->and(($ui->shown)())->toBe(1);
});

it('shows nothing for a frame that painted nothing', function () {
    $ui = presentUi();
    $ui->host->frame();

    $ui->host->frame();

    expect(($ui->shown)())->toBe(1);
});

it('does not present a window the engine does not draw over', function () {
    $ui = presentUi(fn () => new SpyEngine(400, 300));

    $ui->host->frame();

    expect($ui->engine->calls)->not->toBe([])->and(($ui->shown)())->toBe(0);
});

it('does not present once the window is closed', function () {
    $ui = presentUi();
    $ui->host->frame();
    $ui->window->close();

    $ui->host->present();

    expect(($ui->shown)())->toBe(1);
});
