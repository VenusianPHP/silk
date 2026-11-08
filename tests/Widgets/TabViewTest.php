<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Motion\Transition;
use Silk\Core\Motion\SpringPreset;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Mail\View\TabSelected;

it('is created as TKTabView: pages, titles, the first page selected, select() posts nothing', function () {
    $ui = tabUi();

    expect($ui->tabs->pages())->toBe(['general', 'advanced', 'about'])
        ->and($ui->tabs->selected())->toBe('general')
        ->and($ui->tabs->title('advanced'))->toBe('Advanced')
        ->and($ui->tabs->setTitle('about', 'Info')->title('about'))->toBe('Info');

    $ui->tabs->select('about');
    expect($ui->tabs->selected())->toBe('about')->and($ui->loop->posted)->toBe([])
        ->and($ui->tabs->page('about')->isVisible())->toBeTrue()
        ->and($ui->tabs->page('general')->isVisible())->toBeFalse();
});

it('selects a clicked tab, posts TabSelected once, and slides its mark on a BOUNCY spring (tab_view.c:21-23, 501-502)', function () {
    $ui = tabUi();
    $from = $ui->tabs->indicator();
    $target = $ui->tabs->tabRect('advanced');

    click($ui->router, $target->x + $target->w / 2, $target->y + $target->h / 2);
    $ui->tree->advance(1 / 60);

    expect($ui->tabs->selected())->toBe('advanced')
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(TabSelected::class)
        ->and($ui->loop->posted[0]->page)->toBe('advanced')
        ->and($ui->tabs->indicator())->toBeGreaterThan($from)->toBeLessThan($target->x)
        ->and($ui->tabs->springs()[0]->preset)->toBe(SpringPreset::Bouncy);
    settleAll($ui);
    expect($ui->tabs->indicator())->toBe($target->x);
});

it('cycles tabs with Ctrl-Tab and Ctrl-Shift-Tab', function () {
    $ui = tabUi();
    $ui->focus->focus($ui->tabs);
    $ctrlTab = fn (bool $shift = false) => $ui->router->dispatch(new Silk\Contracts\Input\SilkEvent(
        Silk\Contracts\Input\EventType::KeyDown, key: Key::TAB, modifiers: new Modifiers(shift: $shift, ctrl: true)));

    $ctrlTab();
    $ctrlTab();
    $ctrlTab();
    $ctrlTab(true);

    expect($ui->tabs->selected())->toBe('about')->and(array_map(fn ($m) => $m->page, $ui->loop->posted))
        ->toBe(['advanced', 'about', 'general', 'about']);
});

it('measures tabs as Raven: the title, PaddingX either side, and 4; the strip ControlHeight and 2 (tab_view.c:47-64)', function () {
    $ui = tabUi();
    $look = $ui->tree->look;
    $t = $ui->tabs->tabRect('general');

    expect($t->h)->toBe($look->metric(Metric::ControlHeight) + 2)
        ->and($t->w)->toBeGreaterThan(2 * $look->metric(Metric::PaddingX) + 4);
});

it('brings a chosen page in from the side of its tab, unless it has its own appear (tab_view.c:180-184)', function () {
    $ui = tabUi();
    $ui->tabs->select('about');

    expect($ui->tabs->page('about')->appearTransition())->toBeNull()
        ->and($ui->tabs->page('about')->paintExtent())->not->toEqual($ui->tabs->page('about')->bounds()->grow(8.0));

    $ui->tabs->page('general')->appear(Transition::Fade);
    $ui->tabs->select('general');
    expect($ui->tabs->page('general')->appearTransition())->toBe(Transition::Fade);
});

it('paints RavenDark as Raven: a Header strip, a Separator under it, the active tab TabActive with an Accent top (tab_view.c:590-611)', function () {
    [$tabs, $calls, $look] = tabIn('raven-dark');
    $b = $tabs->bounds();
    $bar = $look->metric(Metric::ControlHeight) + 2;
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));
    $active = $tabs->tabRect('general');

    expect([$rects[0][1][0]->h, $rects[0][1][1]])->toBe([$bar, $look->color(Role::Header)->toHex()])
        ->and([$rects[1][1][0]->y, $rects[1][1][1]])->toBe([$b->y + $bar - 1, $look->color(Role::Separator)->toHex()])
        ->and([$rects[2][1][0]->x, $rects[2][1][0]->w, $rects[2][1][1]])->toBe([$active->x, $active->w, $look->color(Role::TabActive)->toHex()])
        ->and([$rects[3][1][0]->h, $rects[3][1][1]])->toBe([2.0, $look->color(Role::Accent)->toHex()])
        ->and($texts[0][1][3])->toBe($look->color(Role::Text)->toHex())
        ->and($texts[1][1][3])->toBe(Silk\Core\Motion\LookBlend::mix($look->color(Role::Text), $look->color(Role::Header), 0.3)->toHex());
});

it('paints macOS as a segmented control: a groove 22 high, a white pill under the active title, hairlines between the others (tab_view.c:446-484)', function () {
    [$tabs, $calls, $look] = tabIn('macos-light', fn ($t) => $t->select('advanced'));
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    expect([$rects[0][1][0]->h, $rects[0][1][2]])->toBe([22.0, 6.0])
        ->and(array_column($calls, 0))->toContain('shadow')
        ->and(array_map(fn ($r) => $r[1][1], $rects))->toContain('#ffffff')
        // Advanced active: General is next to it, so no hairline anywhere
        ->and(count(array_filter($rects, fn ($r) => $r[1][0]->w === 1.0)))->toBe(0);
    [, $first] = tabIn('macos-light');
    expect(count(array_filter($first, fn ($c) => $c[0] === 'rect' && $c[1][0]->w === 1.0)))->toBe(1);
});

it('paints GNOME\'s inactive titles at ink .75, the active at Text (tab_view.c:404-407)', function () {
    [, $calls, $look] = tabIn('gnome-dark');
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));
    $t = $look->color(Role::Text);

    expect($texts[0][1][3])->toBe($t->toHex())
        ->and($texts[1][1][3])->toBe((new Surface\NutsAndBolts\Color($t->red, $t->green, $t->blue, 0.75))->toHex());
});

it('is a segmented capsule of glass in a Raven glass look: the track, the active title on a pill of Control glass (tab_view.c:566-593)', function () {
    $ui = tabUi();
    inLook($ui, 'raven-glass-dark');
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 300));
    settleAll($ui);
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->tabs->paint($p);
    $bar = $ui->tree->look->metric(Silk\Contracts\Look\Metric::ControlHeight) + 8;
    $track = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'))[0][1];
    [$glass] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));

    expect($track[0]->h)->toBe($bar - 6)
        ->and($track[2])->toBe(($bar - 6) * 0.5)
        ->and($glass[1][0]->h)->toBe($bar - 10)
        ->and($glass[1][1])->toBe(($bar - 10) * 0.5)
        // hovered 1, pressed 0.6: ControlHovered 46 mixed toward ControlPressed 66 by 0.6
        ->and(round($glass[1][2]->tint->alpha * 255))->toBe(58.0);
});
