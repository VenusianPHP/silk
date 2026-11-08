<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Role;
use Silk\Core\Layout\Column;
use Silk\Core\Overlay\Placement;
use Silk\Core\Tree\Tree;
use Silk\Looks\BuiltInThemes;
use Silk\Core\Motion\LookBlend;
use Silk\Looks\LookManager;
use Silk\Widgets\Button;
use Silk\Widgets\Menu;
use Venusian\Silk\Tests\Support\SpyPainter;

it('hands the new look to a watched tree', function () {
    [$m, $tree] = switching();

    $m->use('raven-light');
    $tree->advance(5.0);

    expect($tree->look)->not->toBeInstanceOf(LookBlend::class)
        ->and($tree->look->color(Role::Window)->toHex())->toBe('#f3f3f5');
});

it('blends colours while the spring moves', function () {
    [$m, $tree] = switching();

    $m->use('raven-light');
    expect($tree->advance(1 / 60))->toBeTrue();

    expect($tree->look->color(Role::Window)->toHex())->not->toBeIn(['#1e1e20', '#f3f3f5']);
});

it('lands at once with reduced motion', function () {
    [$m, $tree] = switching();
    $tree->reducedMotion = true;

    $m->use('raven-light');

    expect($tree->look)->not->toBeInstanceOf(LookBlend::class)
        ->and($tree->look->color(Role::Window)->toHex())->toBe('#f3f3f5');
});

it('starts a second switch from the colours on screen', function () {
    [$m, $tree] = switching();
    $m->use('raven-light');
    $tree->advance(0.05);
    $before = $tree->look->color(Role::Window);

    $m->use('gnome-dark');
    $after = $tree->look->color(Role::Window);

    expect([$after->red, $after->green, $after->blue, $after->alpha])
        ->toEqualWithDelta([$before->red, $before->green, $before->blue, $before->alpha], 1e-9);
});

it('lays out again when the look changes', function () {
    [$m, $tree] = switching();

    $m->use('macos-dark');

    expect($tree->needsLayout())->toBeTrue();
});

it('repaints open popups while the colours move', function () {
    [$m, $tree, $root] = switching();
    $root->add($anchor = new Button('anchor', 'Anchor'));
    $menu = (new Menu('edit'))->in($tree);
    $menu->item('cut', 'Cut');
    $tree->layout(new Size(200, 100));
    $menu->open($anchor, Placement::Below);
    $tree->advance(5.0);
    $tree->layout(new Size(200, 100));
    $tree->paintAll(new SpyPainter);

    $m->use('raven-light');
    $tree->advance(1 / 60);

    expect($menu->needsPaint())->toBeTrue();
});
