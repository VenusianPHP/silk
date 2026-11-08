<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\TabClosed;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Framebuffers\Native\NativeFullFramebuffer;

it('lays documents tabs from 4 in, 96 to 280 wide, under a strip ControlHeight and 10 high (tab_view.c:47-69, 144-160)', function () {
    $ui = docsUi();
    $look = $ui->tree->look;
    $level = $ui->tabs->tabRect('level');

    expect($level->x)->toBe(4.0)
        ->and($level->h)->toBe($look->metric(Metric::ControlHeight) + 10)
        ->and($level->w)->toBe(96.0)
        ->and($ui->tabs->tabRect('hero')->x)->toBe(100.0)
        ->and($ui->tabs->measure(new Silk\Contracts\Geometry\Size(500, 500))->h)->toBe($look->metric(Metric::ControlHeight) + 50);
});

it('shrinks the tabs when they do not fit, to 48 at least (tab_view.c:71-81)', function () {
    $ui = docsUi(extra: 4);
    $total = array_sum(array_map(fn ($n) => $ui->tabs->tabRect($n)->w, $ui->tabs->pages()));

    expect($total)->toBeLessThanOrEqual(392.0 + 1e-9)
        ->and($ui->tabs->tabRect('hero')->w)->toBeLessThan(96.0)->toBeGreaterThanOrEqual(48.0);
});

it('closes a tab on its close button, released on it: TabClosed, the page gone, the next shown (tab_view.c:162-173, 225-241)', function () {
    $ui = docsUi();
    $ui->tabs->select('hero');
    $c = $ui->tabs->closeRect('hero');

    click($ui->router, $c->x + 9, $c->y + 9);

    expect($ui->tabs->pages())->toBe(['level', 'rock'])
        ->and($ui->tabs->selected())->toBe('rock')
        ->and($ui->loop->posted[0])->toBeInstanceOf(TabClosed::class)
        ->and($ui->loop->posted[0]->page)->toBe('hero')
        ->and($ui->loop->names())->toBe(['view.tab-closed.main.window.docs']);
});

it('closes on a middle click, but never a fixed tab, and the callback may keep it (tab_view.c:53-55, 162-173, 219-222)', function () {
    $ui = docsUi();
    $kept = [];
    $ui->tabs->setCloseCallback(function (string $page) use (&$kept) {
        $kept[] = $page;

        return $page !== 'rock';
    });
    [$level, $rock, $hero] = [$ui->tabs->tabRect('level'), $ui->tabs->tabRect('rock'), $ui->tabs->tabRect('hero')];

    buttonClick($ui, MouseButton::MIDDLE, $level->x + 10, $level->y + 10);
    buttonClick($ui, MouseButton::MIDDLE, $rock->x + 10, $rock->y + 10);
    buttonClick($ui, MouseButton::MIDDLE, $hero->x + 10, $hero->y + 10);

    expect($ui->tabs->pages())->toBe(['level', 'rock'])
        ->and($kept)->toBe(['rock', 'hero'])
        ->and($ui->tabs->isTabClosable('level'))->toBeFalse()
        ->and($ui->tabs->selected())->toBe('level');
});

it('does not close when the press leaves the close button, and a click on the tab selects at once, no slide', function () {
    $ui = docsUi();
    $c = $ui->tabs->closeRect('rock');
    $rock = $ui->tabs->tabRect('rock');

    click($ui->router, $c->x + 9, $c->y + 9, $rock->x + 5, $rock->y + 5);
    click($ui->router, $rock->x + 10, $rock->y + 10);

    expect($ui->tabs->pages())->toContain('rock')
        ->and($ui->tabs->selected())->toBe('rock')
        ->and($ui->tabs->page('rock')->paintExtent())->toEqual($ui->tabs->page('rock')->bounds()->grow(8.0));
});

it('paints RavenDark documents: the active tab TabActive with an Accent top, rounded at the top; a cross on it (tab_view.c:532-570)', function () {
    $ui = docsUi();
    $look = $ui->tree->look;
    $calls = docsPaint($ui);
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    $r = min(6.0, $look->metric(Metric::CornerRadius) + 2);

    expect($rects[0][1][1])->toBe($look->color(Role::Header)->toHex())
        ->and([$rects[2][1][1], $rects[2][1][2]])->toBe([$look->color(Role::TabActive)->toHex(), [$r, $r, 0.0, 0.0]])
        ->and([$rects[3][1][0]->h, $rects[3][1][1]])->toBe([2.0, $look->color(Role::Accent)->toHex()])
        // the level tab is fixed: no cross on it
        ->and(count(array_filter($calls, fn ($c) => $c[0] === 'line')))->toBe(0);

    $ui->tabs->select('hero');
    expect(count(array_filter(docsPaint($ui), fn ($c) => $c[0] === 'line')))->toBe(2);
});

it('draws a tab icon 16 square before the title, faded to .75 on a tab not active (tab_view.c:548-553)', function () {
    $ui = docsUi();
    $ui->tabs->setTabIcon('hero', new NativeFullFramebuffer(FormatSpec::rgba8(), 16, 16));
    $ui->tabs->arrange(new Silk\Contracts\Geometry\Rect(0, 0, 400, 200));
    $images = array_values(array_filter(docsPaint($ui), fn ($c) => $c[0] === 'image'));

    expect($images)->toHaveCount(1)
        ->and([$images[0][1][1]->w, $images[0][1][1]->h])->toBe([16.0, 16.0])
        ->and($images[0][1][3])->toBe('#ffffffbf');
});

it('paints macOS and GNOME documents as rounded tabs, the active ControlPressed (tab_view.c:398-408, 414-445)', function () {
    foreach (['macos-light' => 1.0, 'gnome-dark' => 2.0] as $name => $grow) {
        $ui = docsUi($name);
        $look = $ui->tree->look;
        $rects = array_values(array_filter(docsPaint($ui), fn ($c) => $c[0] === 'rect'));

        expect([$rects[0][1][1], $rects[0][1][2]])->toBe([$look->color(Role::ControlPressed)->toHex(), $look->metric(Metric::CornerRadius) + $grow])
            ->and($rects[0][1][0]->y)->toBe(5.0);
    }
});
