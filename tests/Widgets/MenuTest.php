<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Core\Motion\SpringPreset;
use Silk\Contracts\Look\Role;
use Silk\Widgets\Menu;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;

function menuUi(): object
{
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ui->ok = $ui->root->children()[0];
    $ui->menu = (new Menu('edit'))->in($ui->tree);
    $ui->menu->item('cut', 'Cut', 'Cmd+X');
    $ui->menu->separator();
    $ui->menu->item('copy', 'Copy')->setEnabled(false);
    $ui->menu->item('paste', 'Paste');
    $more = $ui->menu->submenu('more', 'More');
    $more->item('wrap', 'Wrap lines')->setCheckable(true);
    $more->item('upper', 'Uppercase');
    $ui->more = $more;

    return $ui;
}

function frames(object $ui, float $seconds = 0.0): void
{
    $ui->tree->layout(new Size(300, 300));
    for ($t = 0.0; $t < $seconds - 1e-9; $t += 1 / 60) {
        $ui->tree->advance(1 / 60);
    }
    $ui->tree->layout(new Size(300, 300));
}

function down(object $ui, float $x, float $y, MouseButton $b = MouseButton::LEFT): void
{
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $y, button: $b, clicks: 1));
}

it('opens the context menu at the pointer on a right press, and eats the press', function () {
    $ui = menuUi();
    $ui->ok->setContextMenu($ui->menu);
    [$x, $y] = centre($ui->ok);

    down($ui, $x, $y, MouseButton::RIGHT);
    frames($ui);

    expect($ui->menu->isOpen())->toBeTrue()
        ->and([$ui->menu->bounds()->x, $ui->menu->bounds()->y])->toBe([$x, $y])
        ->and($ui->ok->isPressed())->toBeFalse();
});

it('opens the context menu after a 0.5 s long press, not before', function () {
    $ui = menuUi();
    $ui->ok->setContextMenu($ui->menu);
    down($ui, ...centre($ui->ok));

    frames($ui, 0.45);
    expect($ui->menu->isOpen())->toBeFalse();
    frames($ui, 0.1);
    expect($ui->menu->isOpen())->toBeTrue();
});

it('moves the highlight with Up and Down on a snappy spring, skipping separators and disabled items', function () {
    $ui = menuUi();
    $ui->menu->openAt(10, 10);
    frames($ui);

    $names = [];
    foreach ([Key::DOWN, Key::DOWN, Key::DOWN, Key::UP] as $k) {
        pressKey($ui->router, $k);
        $names[] = $ui->menu->highlighted()?->name();
    }

    expect($names)->toBe(['cut', 'paste', 'more', 'paste'])
        ->and($ui->menu->springs()[1]->preset)->toBe(SpringPreset::Snappy);
});

it('opens a submenu with Right, closes it with Left, and Enter activates and closes the chain', function () {
    $ui = menuUi();
    $ui->menu->openAt(10, 10);
    frames($ui);
    foreach ([Key::DOWN, Key::DOWN, Key::DOWN, Key::RIGHT] as $k) {
        pressKey($ui->router, $k);
    }
    frames($ui);
    expect($ui->more->isOpen())->toBeTrue()
        // a submenu anchors to its item (inside the menu's padding)
        ->and($ui->more->bounds()->x)->toBeGreaterThanOrEqual($ui->menu->highlighted()->bounds()->x + $ui->menu->highlighted()->bounds()->w - 1);

    pressKey($ui->router, Key::LEFT);
    expect($ui->more->isOpen())->toBeFalse()->and($ui->menu->isOpen())->toBeTrue();

    // Right opens the submenu on its first item (Raven menu.c:541-546): one Down reaches the second
    pressKey($ui->router, Key::RIGHT);
    frames($ui);
    pressKey($ui->router, Key::DOWN);
    pressKey($ui->router, Key::ENTER);

    expect($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(MenuActivated::class)
        ->and($ui->loop->names())->toBe(['menu.activated.main.upper'])
        ->and($ui->more->isOpen())->toBeFalse()
        ->and($ui->menu->isOpen())->toBeFalse();
});

it('posts MenuToggled for a checkable item and flips it', function () {
    $ui = menuUi();
    $ui->menu->openAt(10, 10);
    frames($ui);
    // Right lands on the first item, the checkable one (Raven menu.c:541-546)
    foreach ([Key::DOWN, Key::DOWN, Key::DOWN, Key::RIGHT, Key::ENTER] as $k) {
        pressKey($ui->router, $k);
        frames($ui);
    }

    expect($ui->loop->posted[0])->toBeInstanceOf(MenuToggled::class)
        ->and($ui->loop->posted[0]->on)->toBeTrue()
        ->and($ui->more->children()[0]->isChecked())->toBeTrue();
});

it('opens a submenu to the left at the right edge', function () {
    $ui = menuUi();
    $ui->menu->openAt(240, 10);
    frames($ui);
    foreach ([Key::DOWN, Key::DOWN, Key::DOWN, Key::RIGHT] as $k) {
        pressKey($ui->router, $k);
    }
    frames($ui);

    expect($ui->more->bounds()->x + $ui->more->bounds()->w)->toBeLessThanOrEqual($ui->menu->highlighted()->bounds()->x + 1);
});

it('closes the whole chain on a click outside, and eats the click', function () {
    $ui = menuUi();
    $ui->menu->openAt(150, 10);
    frames($ui);
    foreach ([Key::DOWN, Key::DOWN, Key::DOWN, Key::RIGHT] as $k) {
        pressKey($ui->router, $k);
    }
    frames($ui);

    expect($ui->tree->hitTest(2, 290))->toBe($ui->root);
    click($ui->router, 2, 290);

    expect($ui->menu->isOpen())->toBeFalse()->and($ui->more->isOpen())->toBeFalse()
        ->and($ui->loop->posted)->toBe([]);
});

it('activates an item on a click', function () {
    $ui = menuUi();
    $ui->menu->openAt(10, 10);
    frames($ui, 1.0);
    $paste = $ui->menu->children()[3];

    click($ui->router, ...centre($paste));

    expect($ui->loop->names())->toBe(['menu.activated.main.paste'])->and($ui->menu->isOpen())->toBeFalse();
});

it('keeps the RavenDark popup surface identical', function () {
    $ui = silkUi(fn ($root) => $root->button('anchor', 'Anchor'));
    $menu = (new Menu('edit'))->in($ui->tree);
    $menu->item('cut', 'Cut');
    $menu->open($ui->root->children()[0], Silk\Core\Overlay\Placement::Below);
    for ($i = 0; $i < 120; $i++) {
        $ui->tree->advance(1 / 60);
    }
    $ui->tree->layout(new Size(300, 300));
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($menu);

    expectPaintFixture('popup-raven-dark', array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Surface\NutsAndBolts\Color ? $a->toHex() : ($a instanceof Silk\Contracts\Geometry\Rect ? [$a->x, $a->y, $a->w, $a->h] : $a), $c[1])], $p->calls));
});

it('draws the popup surface by family, with a 1-unit border in every look (common.c:426-430, platform_looks.c:240-259)', function (string $look, float $shadow) {
    $ui = silkUi(fn ($root) => $root->button('anchor', 'Anchor'));
    $ui->tree->look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $menu = (new Menu('edit'))->in($ui->tree);
    $menu->item('cut', 'Cut');
    $menu->open($ui->root->children()[0], Silk\Core\Overlay\Placement::Below);
    $ui->tree->advance(5.0);
    $ui->tree->layout(new Size(300, 300));
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    (fn () => $this->paintSelf($p))->call($menu);
    $calls = array_column($p->calls, 1, 0);

    expect($calls['shadow'][2])->toBe($shadow)->and($calls['rect'][4])->toBe(1.0);
})->with([
    'high contrast keeps Raven\'s 1' => ['raven-high-contrast-dark', 16.0],
    'macOS' => ['macos-dark', 24.0],
    'GNOME' => ['gnome-light', 20.0],
]);

it('opens a submenu on its first item with Right or Enter, as Raven (menu.c:541-561); a click opens it with nothing highlighted', function () {
    foreach ([Key::RIGHT, Key::ENTER] as $key) {
        $ui = menuUi();
        $ui->menu->openAt(10, 10);
        frames($ui);
        foreach ([Key::DOWN, Key::DOWN, Key::DOWN, $key] as $k) {
            pressKey($ui->router, $k);
        }
        expect($ui->more->isOpen())->toBeTrue()->and($ui->more->highlighted()?->name())->toBe('wrap');
    }
    $ui = menuUi();
    $ui->menu->openAt(10, 10);
    frames($ui);
    $ui->menu->activate($ui->menu->children()[4]);
    expect($ui->more->isOpen())->toBeTrue()->and($ui->more->highlighted())->toBeNull();
});

it('draws the Raven menu: rows RowHeight + 2, a Selection highlight at radius 3, text 20 in, separators 7 (menu.c:243-287, 430-505)', function () {
    [, $menu, $calls, $look] = menuIn('raven-dark');
    $cut = $menu->children()[0];
    $row = $cut->bounds();
    $sel = $look->color(Role::Selection)->toHex();
    $hl = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][1]->toHex() === $sel))[0][1];
    $texts = array_column(array_values(array_filter($calls, fn ($c) => $c[0] === 'text')), 1);
    $cutText = array_values(array_filter($texts, fn ($t) => $t[0] === 'Cut'))[0];
    $shortcut = array_values(array_filter($texts, fn ($t) => $t[0] === 'Cmd+X'))[0];
    $text = $look->color(Role::Text);

    expect($row->h)->toBe($look->metric(Silk\Contracts\Look\Metric::RowHeight) + 2)
        ->and($menu->children()[1]->bounds()->h)->toBe(7.0)
        ->and([$hl[0]->x, $hl[0]->w, $hl[2]])->toBe([$row->x, $row->w, 3.0])
        ->and([$cutText[1], $cutText[3]->toHex()])->toBe([$row->x + 20, $text->toHex()])
        ->and($shortcut[3]->toHex())->toBe(Silk\Core\Motion\LookBlend::mix($text, $look->color(Role::TextDisabled), 0.6)->toHex());
});

it('draws the macOS menu: the hot row in the Accent with white text, 1 in, a check 9 at 11 (menu.c:346-428)', function () {
    [, $menu, $calls, $look] = menuIn('macos-light');
    $row = $menu->children()[0]->bounds();
    $accent = $look->color(Role::Accent)->toHex();
    $hl = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][1]->toHex() === $accent))[0][1];
    $cut = array_values(array_filter($calls, fn ($c) => $c[0] === 'text' && $c[1][0] === 'Cut'))[0][1];
    $wrap = $menu->children()[2]->bounds();
    $check = array_values(array_filter($calls, fn ($c) => $c[0] === 'line' && $c[1][1] > $wrap->y && $c[1][1] < $wrap->y + $wrap->h))[0][1];

    expect([$hl[0]->x, $hl[0]->w, $hl[2]])->toBe([$row->x + 1, $row->w - 2, 4.0])
        ->and($cut[3]->toHex())->toBe($look->color(Role::AccentText)->toHex())
        ->and($check[0])->toEqualWithDelta($wrap->x + 11 - 4.5, 1e-9);
});

it('draws the GNOME menu: ink under the pointer inset 2 x 1, text 36 in with checks, separators 6 from the edge (menu.c:346-428)', function () {
    [, $menu, $calls, $look] = menuIn('gnome-dark');
    $row = $menu->children()[0]->bounds();
    $mb = $menu->bounds();
    $cut = array_values(array_filter($calls, fn ($c) => $c[0] === 'text' && $c[1][0] === 'Cut'))[0][1];
    $sep = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][0]->h === 1.0))[0][1][0];
    $hl = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && abs($c[1][1]->alpha - 0.07) < 1e-9))[0][1][0];

    expect($cut[1])->toBe($row->x + 36)
        ->and([$sep->x, $sep->w])->toBe([$mb->x + 6, $mb->w - 12])
        ->and([$hl->x, $hl->y, $hl->w, $hl->h])->toBe([$row->x + 2, $row->y + 1, $row->w - 4, $row->h - 2]);
});
