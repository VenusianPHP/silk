<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\Stack;

it('wants its largest child and places each in the whole panel by align', function () {
    $tree = new Tree($s = new Stack('window'));
    $s->add($back = new Spacer('back', 40, 10));
    $s->add($mid = new Spacer('mid', 10, 30), new Slot(align: Align::Center));
    $s->add($end = new Spacer('end', 10, 10), new Slot(align: Align::End));

    expect($s->measure(new Size(500, 500)))->toEqual(new Size(40, 30));
    $tree->layout(new Size(100, 60));
    expect($back->bounds())->toEqual(new Rect(0, 0, 100, 60))
        ->and($mid->bounds())->toEqual(new Rect(45, 15, 10, 30))
        ->and($end->bounds())->toEqual(new Rect(90, 50, 10, 10))
        ->and($tree->hitTest(95, 55))->toBe($end);
});
