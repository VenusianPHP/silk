<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\Switcher;
use Silk\Core\Motion\Transition;
use Venusian\Silk\Tests\Support\SpyPainter;

it('lays out, paints and hits only the active page', function () {
    $tree = new Tree($sw = new Switcher('window'));
    $sw->add($one = new Spacer('one', 10, 10));
    $sw->add($two = new Spacer('two', 50, 50));

    expect($sw->measure(new Size(500, 500)))->toEqual(new Size(10, 10))
        ->and($sw->children())->toBe([$one])->and($sw->pages())->toBe([$one, $two]);

    $sw->setIndex(1);
    $tree->layout(new Size(80, 80));
    expect($sw->children())->toBe([$two])
        ->and($two->bounds())->toEqual(new Rect(0, 0, 80, 80))
        ->and($tree->hitTest(5, 5))->toBe($two);
});

it('plays its transition as a page comes in', function () {
    $tree = new Tree($sw = new Switcher('window'));
    $sw->add(new Spacer('one', 10, 10));
    $sw->add($two = new Spacer('two', 10, 10));
    $sw->setTransition(Transition::Fade)->setIndex(1);

    expect($two->isVisible())->toBeTrue()->and(count($two->motionSprings()))->toBe(count($two->springs()) + 1);
});
