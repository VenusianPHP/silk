<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\Border;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

it('pads its child', function () {
    $tree = new Tree($b = new Border('window', 8));
    $b->add($s = new Spacer('s', 20, 10));

    expect($b->measure(new Size(500, 500)))->toEqual(new Size(36, 26));
    $tree->layout(new Size(100, 50));
    expect($s->bounds())->toEqual(new Rect(8, 8, 84, 34));
});

it('paints its shadow, then background and outline, then the child', function () {
    $tree = new Tree($b = new Border('window', 4));
    $b->setBackground(Color::hex('#27272A'))->setOutline(Color::hex('#3F3F46'))->setCornerRadius(6)->setShadow(8, Color::rgba(0, 0, 0, 0.5));
    $b->add(new Spacer('s', 10, 10));
    $tree->layout(new Size(50, 50));
    $p = new SpyPainter;
    $tree->paintAll($p);

    // paintAll wraps the frame in its one region clip
    expect(array_values(array_diff($p->methods(), ['pushClip', 'popClip'])))->toBe(['shadow', 'rect']);
});

it('is only padding without a background', function () {
    $tree = new Tree($b = new Border('window', 4));
    $tree->layout(new Size(50, 50));
    $p = new SpyPainter;
    $tree->paintAll($p);

    expect(array_values(array_diff($p->methods(), ['pushClip', 'popClip'])))->toBe([]);
});

it('takes colour roles, read from the look each paint, so a theme switch reaches it', function () {
    $looks = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-dark');
    $tree = new Tree($b = new Border('window', 4), $looks->current());
    $tree->reducedMotion = true;
    $looks->watch($tree);
    $b->setBackground(Silk\Contracts\Look\Role::Panel)->setOutline(Silk\Contracts\Look\Role::Border)->setShadow(8, Silk\Contracts\Look\Role::Shadow);
    $tree->layout(new Size(50, 50));
    $rect = function () use ($tree) {
        $p = new SpyPainter;
        $tree->paintAll($p);

        return array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect' && ($c[1][3] ?? null) !== null))[0][1];
    };

    $dark = $rect();
    expect($dark[1]->toHex())->toBe('#27272a')->and($dark[3]->toHex())->toBe('#3f3f46');
    $looks->use('raven-light');
    $tree->layout(new Size(50, 50));
    $light = $rect();
    expect($light[1]->toHex())->toBe('#ffffff')->and($light[3]->toHex())->toBe('#d0d0d6');
});
