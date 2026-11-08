<?php

use Silk\Contracts\Geometry\Size;
use Silk\Core\Layout\Column;
use Silk\Core\Layout\Row;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;

it('builds dotted paths like TK primitives and gives each widget a uuid', function () {
    $root = new Column('window');
    $root->add($bar = new Row('bar'));
    $bar->add($ok = new Spacer('ok', 10, 10));

    expect($ok->path())->toBe('window.bar.ok')
        ->and($ok->name())->toBe('ok')
        ->and($ok->uuid())->toMatch('/^[0-9a-f-]{36}$/')
        ->and($ok->uuid())->not->toBe($bar->uuid());
});

it('runs zero passes when nothing is dirty', function () {
    $tree = new Tree($root = new Column('window', 6, 10));
    $root->add($a = new Spacer('a', 50, 20));

    expect($tree->layout(new Size(200, 100)))->toBe(1)
        ->and($tree->needsLayout())->toBeFalse()
        ->and($tree->layout(new Size(200, 100)))->toBe(0);
});

it('re-arranges the parent chain after a child changes size', function () {
    $tree = new Tree($root = new Column('window', 6, 10));
    $root->add($inner = new Column('inner', 6, 0));
    $inner->add($a = new Spacer('a', 50, 20));
    $inner->add($b = new Spacer('b', 50, 20));
    $tree->layout(new Size(200, 200));

    $a->setSize(50, 40);

    expect($inner->isDirty())->toBeTrue()
        ->and($root->isDirty())->toBeTrue()
        ->and($tree->needsLayout())->toBeTrue()
        ->and($tree->layout(new Size(200, 200)))->toBe(1)
        ->and($b->bounds()->y)->toBe(10.0 + 40 + 6);
});

it('re-lays out when the viewport changes', function () {
    $tree = new Tree($root = new Column('window'));
    $tree->layout(new Size(200, 200));

    expect($tree->layout(new Size(300, 200)))->toBe(1)
        ->and($root->bounds()->w)->toBe(300.0);
});

it('hit tests the deepest visible enabled widget, top-most sibling first', function () {
    $tree = new Tree($root = new Column('window'));
    $root->add($a = new Spacer('a', 100, 50));
    $root->add($b = new Spacer('b', 100, 50));
    $tree->layout(new Size(100, 100));

    expect($tree->hitTest(10, 10))->toBe($a)
        ->and($tree->hitTest(10, 60))->toBe($b);

    $a->setVisible(false);
    $tree->layout(new Size(100, 100));
    expect($tree->hitTest(10, 10))->toBe($b);

    $b->setEnabled(false);
    expect($tree->hitTest(10, 10))->toBe($root)
        ->and($tree->hitTest(500, 500))->toBeNull();
});

it('skips the children of a disabled container', function () {
    $tree = new Tree($root = new Column('window'));
    $root->add($inner = new Column('inner'));
    $inner->add($a = new Spacer('a', 100, 50));
    $tree->layout(new Size(100, 100));

    $inner->setEnabled(false);

    expect($tree->hitTest(10, 10))->toBe($root);
});

it('tracks paint dirt and clears it after paintAll', function () {
    $tree = new Tree($root = new Column('window'));
    $root->add($a = new Spacer('a', 10, 10));
    $tree->layout(new Size(100, 100));

    expect($tree->needsPaint())->toBeTrue();
    $tree->paintAll(new Venusian\Silk\Tests\Support\SpyPainter);
    expect($tree->needsPaint())->toBeFalse();

    $a->invalidatePaint();
    expect($tree->needsPaint())->toBeTrue()->and($tree->needsLayout())->toBeFalse();
});

it('lays out a 0x0 viewport with no negative rects', function () {
    $tree = new Tree($root = new Column('window', 6, 10));
    $root->add($a = new Spacer('a', 50, 20));
    $root->add($b = new Spacer('b', 50, 20));

    $tree->layout(new Size(0, 0));

    foreach ([$root, $a, $b] as $w) {
        expect($w->bounds()->w)->toBeGreaterThanOrEqual(0.0)
            ->and($w->bounds()->h)->toBeGreaterThanOrEqual(0.0);
    }
});
