<?php

use Silk\Contracts\Geometry\Size;
use Silk\Widgets\Dropdown;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;

function dropUi(): object
{
    $ui = silkUi(fn ($root) => $root->add(new Dropdown('size', ['Small', 'Medium', 'Large'], 1)));
    $ui->drop = $ui->root->children()[0];

    return $ui;
}

function settleDrop(object $ui): void
{
    for ($i = 0; $i < 200 && $ui->tree->advance(1 / 60); $i++) {
    }
    $ui->tree->layout(new Size(300, 300));
}

it('is created as TKDropdown: options and selection', function () {
    $d = new Dropdown('size', ['Small', 'Medium'], 1);

    expect($d->options())->toBe(['Small', 'Medium'])->and($d->selected())->toBe(1)->and($d->selectedOption())->toBe('Medium')
        ->and((new Dropdown('none', []))->selected())->toBe(-1)
        ->and($d->setOptions(['Only'])->selected())->toBe(0);
});

it('opens its list on a click, below the field and at least as wide (Raven combo_box.c:290-297, window.c:616-629)', function () {
    // room above, so the list is not clamped against the top
    $ui = silkUi(function ($root) {
        $root->spacer('gap', 0, 100);
        $root->add(new Dropdown('size', ['Small', 'Medium', 'Large'], 1));
    });
    $ui->drop = $ui->root->children()[1];

    click($ui->router, ...centre($ui->drop));
    settleDrop($ui);

    $list = $ui->drop->list();
    expect($list->isOpen())->toBeTrue()
        ->and($list->bounds()->y)->toBe(floor($ui->drop->bounds()->y + $ui->drop->bounds()->h + 2))
        ->and($list->highlight())->toBe(1)
        ->and($list->bounds()->w)->toBeGreaterThanOrEqual($ui->drop->bounds()->w);
});

it('selects a clicked row, closes, and posts SelectionChanged once', function () {
    $ui = dropUi();
    click($ui->router, ...centre($ui->drop));
    settleDrop($ui);

    $r = $ui->drop->list()->rowRect(2);
    click($ui->router, $r->x + $r->w / 2, $r->y + $r->h / 2);

    expect($ui->drop->selected())->toBe(2)
        ->and($ui->drop->list()->isOpen())->toBeFalse()
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(SelectionChanged::class)
        ->and([$ui->loop->posted[0]->index, $ui->loop->posted[0]->option])->toBe([2, 'Large'])
        ->and($ui->loop->names())->toBe(['view.selection-changed.main.window.size']);
});

it('posts nothing for a programmatic select', function () {
    $ui = dropUi();

    $ui->drop->select(0);

    expect($ui->drop->selected())->toBe(0)->and($ui->loop->posted)->toBe([]);
});

it('refuses an index out of range', function () {
    (new Dropdown('size', ['a']))->select(3);
})->throws(InvalidArgumentException::class);

it('steps the selection with Up and Down when closed and focused', function () {
    $ui = dropUi();
    $ui->focus->focus($ui->drop);

    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::UP);

    expect($ui->drop->selected())->toBe(1)
        ->and(array_map(fn ($m) => $m->index, $ui->loop->posted))->toBe([2, 1])
        ->and($ui->drop->list()?->isOpen() ?? false)->toBeFalse();
});

it('chooses from the open list with the keyboard', function () {
    $ui = dropUi();
    $ui->focus->focus($ui->drop);
    pressKey($ui->router, Key::SPACE);
    settleDrop($ui);

    pressKey($ui->router, Key::UP);
    pressKey($ui->router, Key::ENTER);

    expect($ui->drop->selected())->toBe(0)->and($ui->drop->list()->isOpen())->toBeFalse()
        ->and($ui->focus->focused())->toBe($ui->drop);
});

it('measures as Raven: ceil(max(widest, 40) + 2 x PaddingX + 0.7 x height) (combo_box.c:304-314)', function () {
    [$ui, , $look] = dropIn('raven-dark');
    $widest = max(array_map(fn ($o) => $ui->tree->measurer()->measure($o, $look->font())->w, ['Small', 'Medium', 'Large']));
    $h = $look->metric(Silk\Contracts\Look\Metric::ControlHeight);

    expect($ui->drop->measure(new Size(300, 300))->w)->toBe(ceil(max($widest, 40) + 2 * $look->metric(Silk\Contracts\Look\Metric::PaddingX) + 0.7 * $h));
});

it('draws the Raven field: Control box, the text clipped before the arrow, a 7-unit chevron icon (combo_box.c:330-388, draw.c:783-785)', function () {
    [$ui, $calls, $look] = dropIn('raven-dark');
    $b = $ui->drop->bounds();
    $box = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'))[0][1];
    $clip = array_values(array_filter($calls, fn ($c) => $c[0] === 'pushClip'))[0][1][0];
    $lines = array_values(array_filter($calls, fn ($c) => $c[0] === 'line'));
    $pad = $look->metric(Silk\Contracts\Look\Metric::PaddingX);
    [$cx, $cy] = [$b->x + $b->w - $b->h * 0.35 - 2, $b->y + $b->h / 2];

    expect([$box[1]->toHex(), $box[2], $box[3]->toHex(), $box[4]])->toBe([$look->color(Silk\Contracts\Look\Role::Control)->toHex(), 4.0, $look->color(Silk\Contracts\Look\Role::ControlBorder)->toHex(), 1.0])
        ->and([$clip->x, $clip->w])->toBe([$b->x + $pad, $b->w - $pad - $b->h * 0.7])
        ->and(array_map(fn ($l) => array_slice($l[1], 0, 4), $lines))->toEqualWithDelta([[$cx - 3.5, $cy - 1.75, $cx, $cy + 1.75], [$cx, $cy + 1.75, $cx + 3.5, $cy - 1.75]], 1e-9)
        ->and($lines[0][1][5])->toBe(1.25);
});

it('fills with ControlPressed while open (combo_box.c:322)', function () {
    [, $calls, $look] = dropIn('raven-dark', function ($ui) {
        (fn () => $this->activate())->call($ui->drop);
        $ui->drop->press()->snap(1.0);
    });
    $box = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'))[0][1];

    expect($box[1]->toHex())->toBe($look->color(Silk\Contracts\Look\Role::ControlPressed)->toHex());
});

it('halves the fill and the outline when disabled (combo_box.c:327-330)', function () {
    [, $calls, $look] = dropIn('raven-dark', fn ($ui) => $ui->drop->setEnabled(false));
    $box = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'))[0][1];

    expect($box[1]->alpha)->toEqualWithDelta($look->color(Silk\Contracts\Look\Role::Control)->alpha * 0.5, 1e-9)
        ->and($box[3]->alpha)->toEqualWithDelta($look->color(Silk\Contracts\Look\Role::ControlBorder)->alpha * 0.5, 1e-9);
});

it('draws the macOS pop-up button: the Aqua button, the text before 32, two chevrons on an Accent square (combo_box.c:340-364)', function () {
    [$ui, $calls, $look] = dropIn('macos-light');
    $b = $ui->drop->bounds();
    $square = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][0]->w === 16.0))[0][1];
    $clip = array_values(array_filter($calls, fn ($c) => $c[0] === 'pushClip' && $c[1][0]->h === $b->h))[0][1][0];

    expect([$square[0]->x, $square[0]->y, $square[1]->toHex(), $square[2]])->toBe([$b->x + $b->w - 19, $b->y + floor(($b->h - 16) / 2), $look->color(Silk\Contracts\Look\Role::Accent)->toHex(), 4.0])
        ->and($clip->w)->toBe($b->w - $look->metric(Silk\Contracts\Look\Metric::PaddingX) - 32)
        ->and(count(array_filter($calls, fn ($c) => $c[0] === 'line')))->toBe(4);
});

it('draws the GNOME button with a chevron 18 from the right, 14 x 0.6 tall (combo_box.c:366-370)', function () {
    [$ui, $calls] = dropIn('gnome-dark');
    $b = $ui->drop->bounds();
    $lines = array_values(array_filter($calls, fn ($c) => $c[0] === 'line'));

    expect($lines)->toHaveCount(2)
        ->and($lines[0][1][2])->toEqualWithDelta($b->x + $b->w - 18, 1e-9)
        ->and($lines[0][1][0])->toEqualWithDelta($b->x + $b->w - 18 + 14 * 0.6 / 2, 1e-9);   // (cx + h, cy - q) first, at pi/2 (draw.c:807)
});

it('draws the Raven list: shadow 12, rows one unit in, a Selection highlight, the chosen text in AccentHovered (combo_box.c:103-159)', function () {
    [, $list, $calls, $look] = openListIn('raven-dark');
    $b = $list->bounds();
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));
    $row = $look->metric(Silk\Contracts\Look\Metric::RowHeight);

    expect(array_values(array_filter($calls, fn ($c) => $c[0] === 'shadow'))[0][1][2])->toBe(12.0)
        ->and($b->h)->toBe($row * 3 + 2)
        ->and([$rects[1][1][0]->x, $rects[1][1][0]->y, $rects[1][1][0]->w, $rects[1][1][1]->toHex()])->toBe([$b->x + 1, $b->y + 1 + $row, $b->w - 2, $look->color(Silk\Contracts\Look\Role::Selection)->toHex()])
        ->and(array_map(fn ($t) => [$t[1][0], $t[1][3]->toHex()], $texts))->toBe([
            ['Small', $look->color(Silk\Contracts\Look\Role::Text)->toHex()],
            ['Medium', $look->color(Silk\Contracts\Look\Role::AccentHovered)->toHex()],
            ['Large', $look->color(Silk\Contracts\Look\Role::Text)->toHex()],
        ]);
});

it('shows at most ten rows and scrolls the rest (combo_box.c:13, 64-75, 96-102)', function () {
    [$ui, $list, , $look] = openListIn('raven-dark', array_map(fn ($i) => "Option {$i}", range(1, 14)));
    $row = $look->metric(Silk\Contracts\Look\Metric::RowHeight);
    expect($list->bounds()->h)->toBe($row * 10 + 2);

    $list->setHighlight(13);
    expect($list->rowRect(13)->y + $row)->toBeLessThanOrEqual($list->bounds()->y + $list->bounds()->h - 1 + 1e-9);
});

it('draws the macOS list as a menu: rows 5 in, the hot row in the Accent, a check before the chosen text (combo_box.c:118-143)', function () {
    [, $list, $calls, $look] = openListIn('macos-light');
    $b = $list->bounds();
    $accent = $look->color(Silk\Contracts\Look\Role::Accent)->toHex();
    $hot = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][1]->toHex() === $accent))[0][1];
    $lines = array_values(array_filter($calls, fn ($c) => $c[0] === 'line'));

    expect([$hot[0]->x, $hot[0]->w, $hot[2]])->toBe([$b->x + 5, $b->w - 10, 4.0])
        ->and($lines)->toHaveCount(2)
        ->and($lines[0][1][0])->toEqualWithDelta($b->x + 5 + 12 - 4.5, 1e-9);
});

it('draws the GNOME list: rows 6 in, ink on the chosen row, a check after its text (combo_box.c:118-143)', function () {
    [, $list, $calls] = openListIn('gnome-light');
    $b = $list->bounds();
    $lines = array_values(array_filter($calls, fn ($c) => $c[0] === 'line'));

    expect($lines)->toHaveCount(2)
        ->and($lines[0][1][0])->toEqualWithDelta($b->x + $b->w - 6 - 16 - 4.5, 1e-9);
});

it('draws its Raven scrollbar past ten rows at the right inside the padding, and a press on the track scrolls (combo_box.c:84-88, common.c:645-680)', function () {
    [$ui, $list, $calls, $look] = openListIn('raven-dark', array_map(fn ($i) => "Option {$i}", range(1, 14)));
    $b = $list->bounds();
    $bar = $look->metric(Silk\Contracts\Look\Metric::ScrollbarSize);
    $track = new Silk\Contracts\Geometry\Rect($b->x + $b->w - $bar - 1, $b->y + 1, $bar, $b->h - 2);
    $tracks = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][0] == $track));

    expect($tracks)->toHaveCount(1);
    $before = $list->rowRect(0)->y;
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseDown, x: $track->x + $bar / 2, y: $track->y + $track->h - 2, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
    $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::MouseUp, x: $track->x + $bar / 2, y: $track->y + $track->h - 2, button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));

    expect($list->rowRect(0)->y)->toBeLessThan($before)->and($list->isOpen())->toBeTrue();
});
