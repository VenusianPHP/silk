<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\SpinBox;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\ValueChanged;

beforeEach(function () {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('needs intl');
    }
});

/** A UI with one SpinBox 'n' (0..100, step 5, value 50), focused. */
function spinUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new SpinBox('n', 50, 0, 100, 5)));
    $ui->spin = $ui->root->children()[0];
    $ui->focus->focus($ui->spin);

    return $ui;
}

/** @return list<float> */
function values(object $ui): array
{
    return array_map(fn (ValueChanged $m) => $m->value, $ui->loop->posted);
}

it('clamps to its range and posts nothing for setValue', function () {
    $ui = spinUi();
    $ui->spin->setValue(500);
    expect($ui->spin->value())->toBe(100.0)->and($ui->spin->text())->toBe('100');
    $ui->spin->setValue(-3);
    expect($ui->spin->value())->toBe(0.0)->and($ui->loop->posted)->toBe([]);
});

it('steps with the arrow keys and stops at the ends', function () {
    $ui = spinUi();
    pressKey($ui->router, Key::UP);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::DOWN);

    expect(values($ui))->toBe([55.0, 50.0, 45.0]);
    $ui->spin->setValue(100);
    pressKey($ui->router, Key::UP);
    expect(values($ui))->toBe([55.0, 50.0, 45.0]);
});

it('commits typing on Enter', function () {
    $ui = spinUi();
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::SelectAll));
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '42'));
    expect($ui->loop->posted)->toBe([]);
    pressKey($ui->router, Key::ENTER);

    expect(values($ui))->toBe([42.0])->and($ui->spin->value())->toBe(42.0);
});

it('reverts an invalid entry', function () {
    $ui = spinUi();
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::SelectAll));
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: 'abc'));
    pressKey($ui->router, Key::ENTER);

    expect($ui->loop->posted)->toBe([])->and($ui->spin->value())->toBe(50.0)->and($ui->spin->text())->toBe('50');
});

it('commits on focus loss and accepts a decimal comma', function () {
    $ui = spinUi();
    $ui->spin->setDecimals(1);
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::SelectAll));
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '12,5'));
    $ui->focus->focus(null);

    expect(values($ui))->toBe([12.5])->and($ui->spin->text())->toBe('12.5');
});

it('scrubs on the Raven look: a horizontal drag past 3 px, one step per 4 px, Shift ten times finer (spin_box.c:9-10, 272-279)', function () {
    $ui = spinIn('raven-dark');
    $b = $ui->spin->bounds();
    [$x, $y] = [$b->x + 20, $b->y + $b->h / 2];
    spinMouse($ui, EventType::MouseDown, $x, $y);
    spinMouse($ui, EventType::MouseMove, $x + 3, $y);
    expect($ui->loop->posted)->toBe([]);
    spinMouse($ui, EventType::MouseMove, $x + 8, $y);
    spinMouse($ui, EventType::MouseMove, $x - 4, $y);
    spinMouse($ui, EventType::MouseUp, $x - 4, $y);
    expect(values($ui))->toBe([60.0, 45.0])->and($ui->spin->isEditing())->toBeFalse();

    spinMouse($ui, EventType::MouseDown, $x, $y, true);
    spinMouse($ui, EventType::MouseMove, $x + 40, $y, true);
    expect($ui->spin->value())->toBe(50.0);   // 45 + 10 steps x 5 x 0.1, at 0 decimals
});

it('starts typing on a click without a drag, the value selected (spin_box.c:298-300)', function () {
    $ui = spinIn('raven-dark');
    [$x, $y] = centre($ui->spin);
    spinMouse($ui, EventType::MouseDown, $x, $y);
    spinMouse($ui, EventType::MouseUp, $x, $y);

    expect($ui->spin->isEditing())->toBeTrue();
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '7'));
    pressKey($ui->router, Key::ENTER);
    expect(values($ui))->toBe([7.0])->and($ui->spin->isEditing())->toBeFalse();
});

it('starts typing when Tab brings focus (spin_box.c:243-245); Escape drops the edit', function () {
    $ui = silkUi(fn ($root) => $root->add(new SpinBox('n', 50, 0, 100, 5)));
    $ui->focus->next();
    $spin = $ui->root->children()[0];
    expect($spin->isEditing())->toBeTrue();
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: '9'));
    pressKey($ui->router, Key::ESCAPE);

    expect($spin->value())->toBe(50.0)->and($spin->text())->toBe('50')->and($spin->isEditing())->toBeFalse();
});

it('steps with the wheel only while focused and not typing (spin_box.c:303-310)', function () {
    $ui = silkUi(fn ($root) => $root->add(new SpinBox('n', 50, 0, 100, 5)));
    $spin = $ui->root->children()[0];
    [$x, $y] = centre($spin);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1.0));
    expect($ui->loop->posted)->toBe([]);
    $ui->focus->focus($spin);
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: 1.0));
    $ui->router->dispatch(new SilkEvent(EventType::Wheel, x: $x, y: $y, wheelY: -2.0));

    expect(values($ui))->toBe([55.0, 45.0]);
});

it('has no buttons on the Raven look, and draws the value centred (spin_box.c:73-79, 222-235)', function () {
    $ui = spinIn('raven-dark');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->spin->paint($p);
    $b = $ui->spin->bounds();
    $text = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text'))[0][1];
    $w = $ui->tree->measurer()->measure('50', $text[4])->w;

    expect(array_column($p->calls, 0))->not->toContain('triangle')->not->toContain('line')
        ->and($text[1])->toEqualWithDelta($b->x + ($b->w - $w) / 2, 1e-6)
        ->and(Silk\Widgets\Steppers::hit('drag', $b, 26.0, $b->x + $b->w - 2, $b->y + 2))->toBe(0);
});

it('halves the fill of a disabled Raven spin box (spin_box.c:189)', function () {
    $ui = spinIn('raven-dark');
    $ui->spin->setEnabled(false);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->spin->paint($p);

    expect($p->calls[0][1][1]->alpha)->toEqualWithDelta($ui->tree->look->color(Silk\Contracts\Look\Role::Input)->alpha * 0.5, 1e-9);
});

it('steps on the macOS stepper halves and repeats while held (spin_box.c:83-108)', function () {
    $ui = spinIn('macos-light');
    $b = $ui->spin->bounds();
    $up = Silk\Widgets\Steppers::rects('stacked', $b, 26.0)['up'];
    expect([$up->x, $up->w, $up->h])->toBe([$b->x + $b->w - 15, 15.0, floor($b->h / 2)]);
    spinMouse($ui, EventType::MouseDown, $up->x + 7, $up->y + 3);
    expect(values($ui))->toBe([55.0]);
    $ui->tree->advance(0.5);
    expect(count(values($ui)))->toBeGreaterThan(1);
    spinMouse($ui, EventType::MouseUp, $up->x + 7, $up->y + 3);
});

it('puts minus then plus at the end on GNOME, each min(ControlHeight, 24%) wide (spin_box.c:75, 99-101)', function () {
    $ui = spinIn('gnome-light');
    $b = $ui->spin->bounds();
    $r = Silk\Widgets\Steppers::rects('sideBySide', $b, 26.0);
    $width = min(26.0, floor($b->w * 0.24));

    expect([$r['up']->x, $r['down']->x, $r['up']->w])->toBe([$b->x + $b->w - $width, $b->x + $b->w - 2 * $width, $width]);
    spinMouse($ui, EventType::MouseDown, $r['down']->x + 3, $r['down']->y + 3);
    spinMouse($ui, EventType::MouseUp, $r['down']->x + 3, $r['down']->y + 3);
    expect(values($ui))->toBe([45.0]);
});

it('draws the GNOME steppers as line glyphs, minus and plus (spin_box.c:193-197)', function () {
    $ui = spinIn('gnome-dark');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->spin->paint($p);

    expect(count(array_filter($p->calls, fn ($c) => $c[0] === 'line' && $c[1][5] === 1.5)))->toBe(3);
});

it('draws the macOS stepper: a button cut in two by a Separator line, with chevrons (spin_box.c:174-182, 199-200)', function () {
    $ui = spinIn('macos-dark');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->spin->paint($p);
    $b = $ui->spin->bounds();
    $sep = $ui->tree->look->color(Silk\Contracts\Look\Role::Separator)->toHex();
    $lines = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect' && $c[1][0]->h === 1.0 && $c[1][1]->toHex() === $sep));

    expect($lines)->toHaveCount(1)
        ->and($lines[0][1][0]->x)->toBe($b->x + $b->w - 15 + 2)
        ->and(count(array_filter($p->calls, fn ($c) => $c[0] === 'line')))->toBe(4);
});
