<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Looks\BuiltInThemes;
use Silk\Widgets\Blocks\BlockPlace;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

it('paints the palette and the scripts on every one of the twelve looks', function (string $look) {
    $ui = blockUi($look);
    $a = beBlock($ui, 'move', 260, 60);
    $b = beBlock($ui, 'repeat', 260, 130);
    $ui->e->attach(beBlock($ui, 'say', 0, 0), $b, BlockPlace::Body, 0);
    $ui->e->setBlockGlow($a);
    $p = blockPaint($ui);
    $texts = array_map(fn ($c) => $c[1][0], blockCalls($p, 'text'));

    expect(count(blockCalls($p, 'fill')))->toBeGreaterThan(10)
        ->and($texts)->toContain('Motion', 'Control')->and(implode(' ', $texts))->toContain('repeat')
        ->and(count(blockCalls($p, 'shadow')))->toBeGreaterThan(0);
})->with(array_keys(BuiltInThemes::NAMES));

it('makes a block when one is dragged out of the palette, and drops it where the pointer lets go', function () {
    $ui = blockUi();
    $to = beCanvas($ui, 300, 200);
    ngDrag($ui, bePalette($ui, 'move'), $to, [beLocal($ui, 120, 80), beLocal($ui, 260, 150)]);
    blockPaint($ui);
    $scripts = $ui->e->model()->scripts();

    expect($scripts)->toHaveCount(1)->and($ui->e->model()->block($scripts[0])->kind)->toBe('move')
        ->and($ui->e->model()->block($scripts[0])->x)->toBeGreaterThan(200.0)
        ->and(ngMail($ui))->toContain('BlockEditorChanged')
        ->and($ui->e->isCarrying())->toBeFalse();
});

it('does not make a block when the palette is only clicked', function () {
    $ui = blockUi();
    ngMouse($ui, EventType::MouseDown, bePalette($ui, 'move'));
    ngMouse($ui, EventType::MouseUp, bePalette($ui, 'move'));

    expect($ui->e->model()->count())->toBe(0);
});

it('snaps a dragged block under another when released near its bottom, and takes what is under it along', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $b = beBlock($ui, 'say', 300, 300);
    $c = beBlock($ui, 'move', 0, 0);
    $ui->e->attach($c, $b, BlockPlace::Next);
    blockPaint($ui);
    $under = $ui->e->model()->block($a);
    ngDrag($ui, beOn($ui, $b), beCanvas($ui, $under->lx + 6, $under->ly + $under->h + 14), [beCanvas($ui, 500, 200)]);
    $m = $ui->e->model();

    expect($m->block($a)->next)->toBe($b)->and($m->block($b)->next)->toBe($c)->and($m->scripts())->toBe([$a])
        ->and(ngMail($ui))->toContain('BlockEditorChanged');
});

it('opens a gap and shows a shadow where a carried block would go, and a value slot that would take a reporter lights up', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $held = beBlock($ui, 'say', 600, 300);
    $A = $ui->e->model()->block($a);
    ngMouse($ui, EventType::MouseDown, beOn($ui, $held));
    ngMouse($ui, EventType::MouseMove, beCanvas($ui, 450, 250));
    ngMouse($ui, EventType::MouseMove, beCanvas($ui, $A->lx + 6 + 6, $A->ly + $A->h + 8 + 6));
    $p = blockPaint($ui);
    $ghost = $ui->e->blockLayout();

    expect($ui->e->isCarrying())->toBeTrue()->and([$ghost->ghostX, $ghost->ghostY])->toBe([$A->lx, $A->ly + $A->h]);
    ngMouse($ui, EventType::MouseUp, beCanvas($ui, $A->lx + 12, $A->ly + $A->h + 14));
    $reporter = beBlock($ui, 'add', 600, 400);
    $move = $ui->e->model()->block($a);
    ngMouse($ui, EventType::MouseDown, beOn($ui, $reporter));
    ngMouse($ui, EventType::MouseMove, beCanvas($ui, 500, 300));
    ngMouse($ui, EventType::MouseMove, beCanvas($ui, $move->lx + $move->slotX[0] + 8, $move->ly + $move->slotY[0] + 4));
    blockPaint($ui);
    ngMouse($ui, EventType::MouseUp, beCanvas($ui, $move->lx + $move->slotX[0] + 8, $move->ly + $move->slotY[0] + 4));

    expect($ui->e->model()->block($a)->input[0] ?? 0)->toBe($reporter);
});

it('deletes a block dropped on the palette, with what is under it, and a locked one comes back', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $b = beBlock($ui, 'say', 0, 0);
    $ui->e->attach($b, $a, BlockPlace::Next);
    $keep = beBlock($ui, 'move', 300, 300);
    $ui->e->setBlockDeletable($keep, false);
    blockPaint($ui);
    ngDrag($ui, beOn($ui, $a), beLocal($ui, 100, 300), [beCanvas($ui, 250, 150)]);

    expect($ui->e->model()->exists($a))->toBeFalse()->and($ui->e->model()->exists($b))->toBeFalse();
    ngDrag($ui, beOn($ui, $keep), beLocal($ui, 100, 300), [beCanvas($ui, 250, 350)]);

    expect($ui->e->model()->exists($keep))->toBeTrue()->and($ui->e->model()->block($keep)->x)->toBeGreaterThan(0.0);
});

it('selects a block on a click, adds with Ctrl, posts the click of its script, and Delete removes the selection', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $b = beBlock($ui, 'say', 300, 300);
    $c = beBlock($ui, 'start', 600, 100);
    ngMouse($ui, EventType::MouseDown, beOn($ui, $a));
    ngMouse($ui, EventType::MouseUp, beOn($ui, $a));

    expect($ui->e->selected())->toBe([$a])->and(ngMail($ui))->toBe(['BlockEditorClicked']);
    $ctrl = new Modifiers(ctrl: true);
    ngMouse($ui, EventType::MouseDown, beOn($ui, $b), MouseButton::LEFT, 1, $ctrl);
    ngMouse($ui, EventType::MouseUp, beOn($ui, $b), MouseButton::LEFT, 1, $ctrl);

    expect($ui->e->selected())->toBe([$a, $b]);
    ngKey($ui, Key::DELETE);

    expect($ui->e->model()->exists($a))->toBeFalse()->and($ui->e->model()->exists($b))->toBeFalse()->and($ui->e->model()->exists($c))->toBeTrue();
});

it('draws a selection rectangle on the background and selects the blocks it touches', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $b = beBlock($ui, 'say', 300, 300);
    ngDrag($ui, beCanvas($ui, 270, 70), beCanvas($ui, 520, 180), [beCanvas($ui, 400, 120)]);

    expect($ui->e->selected())->toBe([$a]);
    ngDrag($ui, beCanvas($ui, 600, 450), beCanvas($ui, 280, 80), [beCanvas($ui, 500, 300)]);

    expect($ui->e->selected())->toBe([$a, $b]);
});

it('moves the whole selection when one of its blocks is dragged', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $b = beBlock($ui, 'say', 300, 300);
    $ui->e->select($a);
    $ui->e->select($b, true);
    ngDrag($ui, beOn($ui, $a), beCanvas($ui, 360, 150), [beCanvas($ui, 330, 120)]);
    $m = $ui->e->model();

    expect([$m->block($a)->x, $m->block($b)->x])->toBe([$m->block($a)->x, $m->block($a)->x])->and($m->block($a)->x)->toBeGreaterThan(300.0)
        ->and($m->block($b)->y - $m->block($a)->y)->toBe(200.0);
});

it('types in a value slot and keeps a number a number, and Escape leaves it as it was', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $slotAt = fn () => beCanvas($ui, $ui->e->model()->block($a)->lx + $ui->e->model()->block($a)->slotX[0] + 6, $ui->e->model()->block($a)->ly + $ui->e->model()->block($a)->slotY[0] + 6);
    ngMouse($ui, EventType::MouseDown, $slotAt());
    ngMouse($ui, EventType::MouseUp, $slotAt());
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '7x5'));
    ngKey($ui, Key::BACKSPACE);
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '2'));
    ngKey($ui, Key::ENTER);

    expect($ui->e->model()->value($a, 0))->toBe('72')->and(ngMail($ui))->toContain('BlockEditorChanged');
    blockPaint($ui);
    ngMouse($ui, EventType::MouseDown, $slotAt());
    ngMouse($ui, EventType::MouseUp, $slotAt());
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '9'));
    ngKey($ui, Key::ESCAPE);

    expect($ui->e->model()->value($a, 0))->toBe('72');
});

it('opens the choices of a menu slot and takes the one that is clicked', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'point', 300, 100);
    $blk = $ui->e->model()->block($a);
    $slot = beCanvas($ui, $blk->lx + $blk->slotX[0] + 6, $blk->ly + $blk->slotY[0] + 6);
    ngMouse($ui, EventType::MouseDown, $slot);
    ngMouse($ui, EventType::MouseUp, $slot);
    $p = blockPaint($ui);
    $labels = array_map(fn ($c) => $c[1][0], blockCalls($p, 'text'));

    expect($labels)->toContain('north', 'east', 'south', 'west');
    $at = beCanvas($ui, $blk->lx + $blk->slotX[0] + 6, $blk->ly + $blk->slotY[0] + $blk->slotH[0] + 2 + 4 + 26 + 10);
    ngMouse($ui, EventType::MouseDown, $at);

    expect($ui->e->model()->value($a, 0))->toBe('east');
});

it('posts the right click and opens a menu with Delete and Duplicate for a block, and Delete takes the block away', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $at = beOn($ui, $a);
    ngMouse($ui, EventType::MouseDown, $at, MouseButton::RIGHT);
    ngMouse($ui, EventType::MouseUp, $at, MouseButton::RIGHT);
    $labels = array_map(fn ($c) => $c[1][0], blockCalls(blockPaint($ui), 'text'));

    expect(ngMail($ui))->toBe(['BlockEditorContext'])->and($labels)->toContain('Delete', 'Duplicate', 'Clean up');
    $box = $ui->e->bounds();
    $row = [$at[0] + 10, $at[1] + 4 + 13];
    ngMouse($ui, EventType::MouseDown, $row);

    expect($ui->e->model()->exists($a))->toBeFalse();
});

it('pans with the middle button and zooms with Ctrl and the wheel about the pointer', function () {
    $ui = blockUi();
    $from = beCanvas($ui, 400, 300);
    ngDrag($ui, $from, [$from[0] + 60, $from[1] + 30], [[$from[0] + 20, $from[1] + 10]], MouseButton::MIDDLE);
    [$x, $y, $zoom] = $ui->e->view();

    expect($x)->toBe(-60.0)->and($y)->toBe(-30.0)->and($zoom)->toBe(1.0);
    $at = beCanvas($ui, 400, 300);
    $before = $ui->e->view();
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $at[0], y: $at[1], wheelY: 2.0, modifiers: new Modifiers(ctrl: true)));
    [$nx, $ny, $nz] = $ui->e->view();

    expect($nz)->toBeGreaterThan(1.0)->and(beCanvas($ui, 400, 300))->toEqualWithDelta($at, 0.01);
});

it('keeps ticking while a block glows, and stops when none does', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'move', 300, 100);
    $ui->e->setBlockGlow($a);
    blockPaint($ui);

    expect($ui->tree->advance(1 / 60))->toBeTrue();
    $ui->e->setBlockGlow($a, false);
    $ui->tree->advance(1 / 60);

    expect($ui->tree->advance(1 / 60))->toBeFalse();
});

it('saves the scripts and loads them back into another editor', function () {
    $ui = blockUi();
    $a = beBlock($ui, 'start', 300, 100);
    $b = beBlock($ui, 'repeat', 0, 0);
    $ui->e->attach($b, $a, BlockPlace::Next);
    $ui->e->attach(beBlock($ui, 'say', 0, 0), $b, BlockPlace::Body, 0);
    $text = $ui->e->save();
    $other = blockUi();

    expect($other->e->load($text))->toBeTrue()->and($other->e->save())->toBe($text);
});
