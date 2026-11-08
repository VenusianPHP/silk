<?php

use Silk\Core\Layout\Spacer;
use Silk\Widgets\Splitter;
use Surface\Contracts\Windows\Mail\View\SplitMoved;

function splitUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new Splitter('split'), new Silk\Contracts\Widget\Slot(grow: 1)));
    $ui->split = $ui->root->children()[0];
    $ui->split->add($ui->left = new Spacer('left', 60, 10));
    $ui->split->add($ui->right = new Spacer('right', 80, 10));
    settleAll($ui);

    return $ui;
}

it('splits evenly with a 5-unit divider, and setPositions posts nothing', function () {
    $ui = splitUi();
    $b = $ui->split->bounds();

    expect($ui->split->positions())->toBe([0.5])->and($ui->split->isHorizontal())->toBeTrue()
        ->and($ui->right->bounds()->x - ($ui->left->bounds()->x + $ui->left->bounds()->w))->toBe(5.0);

    $ui->split->setPositions([0.4]);
    settleAll($ui);
    expect($ui->left->bounds()->w)->toEqualWithDelta($b->w * 0.4 - 2.5, 1e-9)->and($ui->loop->posted)->toBe([]);
});

it('moves the divider on a drag and posts SplitMoved', function () {
    $ui = splitUi();
    $d = $ui->split->dividerRect(0);
    $b = $ui->split->bounds();

    drag3($ui, [$d->x + 2, $d->y + 2], [$b->x + $b->w * 0.6, $d->y + 2]);

    expect($ui->split->positions()[0])->toEqualWithDelta(0.6, 0.01)
        ->and($ui->loop->posted)->not->toBe([])
        ->and($ui->loop->posted[0])->toBeInstanceOf(SplitMoved::class);
});

it('keeps each pane at least its measured size', function () {
    $ui = splitUi();
    $d = $ui->split->dividerRect(0);
    $b = $ui->split->bounds();

    drag3($ui, [$d->x + 2, $d->y + 2], [$b->x + 1, $d->y + 2]);
    settleAll($ui);
    expect($ui->left->bounds()->w)->toBeGreaterThanOrEqual(60.0 - 1e-9);

    $d = $ui->split->dividerRect(0);
    drag3($ui, [$d->x + 2, $d->y + 2], [$b->x + $b->w - 1, $d->y + 2]);
    settleAll($ui);
    expect($ui->right->bounds()->w)->toBeGreaterThanOrEqual(80.0 - 1e-9);
});
