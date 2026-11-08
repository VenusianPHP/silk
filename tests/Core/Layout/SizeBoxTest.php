<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\SizeBox;

it('fixes and limits the child size it asks for', function () {
    $box = new SizeBox('b', width: 80, minHeight: 30, maxHeight: 40);
    $box->add(new Spacer('s', 10, 100));

    expect($box->measure(new Size(500, 500)))->toEqual(new Size(80, 40));
});

it('fits the largest rect of its aspect ratio, centred', function () {
    $tree = new Tree($box = new SizeBox('window', 16 / 9));
    $box->add($s = new Spacer('s', 1, 1));
    $tree->layout(new Size(400, 400));

    expect($s->bounds())->toEqual(new Rect(0, 87.5, 400, 225));
});
