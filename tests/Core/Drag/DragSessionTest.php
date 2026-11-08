<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Drag\DragPayload;
use Silk\Core\Drag\DragSession;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Venusian\Silk\Tests\Support\SpyPainter;

function release(object $ui, array $at): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $at[0], y: $at[1], button: MouseButton::LEFT));
}

it('sends Enter, Move and Leave to the nearest widget with a callback, and Dropped on release when it takes it', function () {
    $ui = dragUi();
    $drag = $ui->tree->beginDrag($ui->plain, new DragPayload('A thing', 'thing', 'd1'), ...centre($ui->plain));
    expect($drag->target())->toBeNull();
    dragMoveTo($ui, centre($ui->open));
    expect($drag->target())->toBe($ui->outer)->and($drag->isAccepted())->toBeTrue();
    dragMoveTo($ui, centre($ui->open));
    dragMoveTo($ui, centre($ui->plain));
    dragMoveTo($ui, centre($ui->open));
    release($ui, centre($ui->open));

    expect($ui->log)->toBe(['outer:Enter:d1', 'outer:Move:d1', 'outer:Leave:d1', 'outer:Enter:d1', 'outer:Move:d1', 'outer:Dropped:d1'])
        ->and($ui->tree->drag())->toBeNull();
});

it('makes the nearest widget with a callback the target even when it refuses: no drop, no frame', function () {
    $ui = dragUi();
    $drag = $ui->tree->beginDrag($ui->plain, new DragPayload('x', 'thing'), ...centre($ui->leaf));
    expect($drag->target())->toBe($ui->inner)->and($drag->isAccepted())->toBeFalse()->and($drag->frameRect())->toBeNull();
    release($ui, centre($ui->leaf));

    expect($ui->log)->toBe(['inner:Enter', 'inner:Move'])->not->toContain('inner:Dropped');
});

it('skips a disabled widget for the next one up', function () {
    $ui = dragUi();
    $ui->inner->setEnabled(false);
    $drag = $ui->tree->beginDrag($ui->plain, new DragPayload('x', 'thing'), ...centre($ui->open));

    expect($drag->target())->toBe($ui->outer);
});

it('cancels on Escape and on focus loss: Leave, never Dropped', function () {
    $ui = dragUi();
    $ui->tree->beginDrag($ui->plain, new DragPayload('x', 'thing'), ...centre($ui->open));
    pressKey($ui->router, Key::ESCAPE);
    expect($ui->tree->drag())->toBeNull()->and($ui->log)->toBe(['outer:Enter:', 'outer:Leave:']);

    $ui->log = [];
    $ui->tree->beginDrag($ui->plain, new DragPayload('x', 'thing'), ...centre($ui->open));
    $ui->router->dispatch(new SilkEvent(EventType::FocusLost));
    release($ui, centre($ui->open));
    expect($ui->tree->drag())->toBeNull()->and($ui->log)->toBe(['outer:Enter:', 'outer:Leave:']);
});

it('draws the chip at the pointer +14, +10, the label + 20 wide, its edge Accent while a drop would be taken', function () {
    $ui = dragUi();
    $drag = $ui->tree->beginDrag($ui->plain, new DragPayload('A thing', 'thing'), ...centre($ui->open));
    [$x, $y] = centre($ui->open);
    $p = new SpyPainter;
    $ui->tree->repaintAll();
    $ui->tree->paintAll($p);
    $chip = $drag->chipRect();
    $edge = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect' && $c[1][0] == $chip))[0][1][3];

    expect([$chip->x, $chip->y])->toBe([$x + 14, $y + 10])
        ->and($chip->w)->toBe($ui->tree->measurer()->measure('A thing', $ui->look->font())->w + 20)
        ->and($edge)->toEqual($ui->look->color(Silk\Contracts\Look\Role::Accent));
});

it('lands the frame BOUNCY from 14 units further out while it would be taken, and lets it go SNAPPY', function () {
    $ui = dragUi();
    $drag = $ui->tree->beginDrag($ui->plain, new DragPayload('x', 'thing'), ...centre($ui->open));
    $ui->tree->advance(1 / 60);
    expect($drag->frameRect()->w)->toBeGreaterThan($ui->outer->bounds()->w);
    settleAll($ui);
    expect($drag->frameRect())->toEqual($ui->outer->bounds());
    dragMoveTo($ui, centre($ui->plain));
    for ($i = 0; $i < 60; $i++) {
        $ui->tree->advance(1 / 60);
    }
    expect($drag->frameRect())->toBeNull();
});
