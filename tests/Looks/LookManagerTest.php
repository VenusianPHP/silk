<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Layout\Column;
use Silk\Core\Tree\Tree;
use Silk\Looks\LookManager;
use Silk\Looks\RavenDark;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

function looks(): LookManager
{
    return (new LookManager)->extend('raven-dark', fn () => RavenDark::theme())->use('raven-dark');
}

it('has every Raven role and metric', function () {
    expect(Role::cases())->toHaveCount(36)->and(Metric::cases())->toHaveCount(20);
});

it('gives RavenDark the Raven numbers', function () {
    $look = looks()->current();

    expect($look->color(Role::Window)->toHex())->toBe('#1e1e20')
        ->and($look->color(Role::Accent)->toHex())->toBe('#3b82f6')
        ->and($look->color(Role::Shadow)->alpha)->toEqualWithDelta(120 / 255, 1e-9)
        ->and($look->metric(Metric::ControlHeight))->toBe(26.0)
        ->and($look->metric(Metric::FontSize))->toBe(13.0);
});

it('switches to an extended theme laid over its base look', function () {
    $m = looks()->extend('ocean', fn () => ['look' => 'raven-dark', 'accent' => Color::hex('#0EA5E9'), 'metrics' => ['CornerRadius' => 8]]);

    $m->use('ocean');

    expect($m->current()->color(Role::Accent)->toHex())->toBe('#0ea5e9')
        ->and($m->current()->color(Role::Window)->toHex())->toBe('#1e1e20')
        ->and($m->current()->metric(Metric::CornerRadius))->toBe(8.0);
});

it('marks watched trees paint-dirty on use()', function () {
    $m = looks();
    $tree = new Tree(new Column('window'));
    $tree->layout(new Size(10, 10));
    $tree->paintAll(new SpyPainter);
    $m->watch($tree);

    $m->use('raven-dark');

    expect($tree->needsPaint())->toBeTrue();
});

it('refuses an unknown look', function () {
    looks()->use('nope');
})->throws(InvalidArgumentException::class);

it('gives the body font as a Silk FontSpec from config', function () {
    $font = looks()->current()->font(\Silk\Contracts\Look\FontRole::Ui);

    expect($font)->toBeInstanceOf(\Silk\Contracts\Look\FontSpec::class)
        ->and($font->family)->toBe('sans-serif')
        ->and($font->size)->toBe(13.0)
        ->and($font->weight)->toBe(400)
        ->and($font->italic)->toBeFalse()
        ->and(looks()->current()->font(\Silk\Contracts\Look\FontRole::Mono)->family)->toBe('monospace');
});

it('rebuilds the look in use with the accent set at run time, and with its own after null', function () {
    $m = looks();
    $tree = new Tree(new Column('window'));
    $m->watch($tree);

    $m->setAccent(Color::hex('#3584E4'));

    expect($m->current()->color(Role::Accent)->toHex())->toBe('#3584e4')
        ->and($m->current()->color(Role::AccentPressed)->toHex())->toBe('#2b6cbb')
        ->and($tree->needsPaint())->toBeTrue();

    $m->setAccent(null);

    expect($m->current()->color(Role::Accent)->toHex())->toBe('#3b82f6');
});

it('lets a theme with its own accent derive the roles that follow it', function () {
    $m = looks()->extend('ocean', fn () => ['look' => 'raven-dark', 'accent' => Color::hex('#3584E4')])->use('ocean');

    expect($m->current()->color(Role::AccentHovered)->toHex())->toBe('#599ae9');
});
