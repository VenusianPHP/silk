<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Core\Layout\Spacer;
use Silk\Widgets\Expander;
use Surface\Contracts\Windows\Mail\View\Toggled;

it('grows its measured height over frames, on BOUNCY opening and SNAPPY closing (expander.c:149)', function () {
    $ui = silkUi(fn ($root) => $root->add(new Expander('more', 'More options')));
    $x = $ui->root->children()[0];
    $x->body()->add(new Spacer('body', 100, 120));
    settleAll($ui);
    $closed = $x->bounds()->h;

    $x->setOpen(true);
    $ui->tree->advance(1 / 60);
    $ui->tree->layout(new Size(300, 300));
    $mid = $x->bounds()->h;
    settleAll($ui);
    $open = $x->bounds()->h;

    expect($mid)->toBeGreaterThan($closed)->toBeLessThan($open)
        ->and($open)->toEqualWithDelta($closed + 120, 1e-6)
        ->and($ui->loop->posted)->toBe([])
        ->and($x->springs()[0]->preset)->toBe(Silk\Core\Motion\SpringPreset::Bouncy);
    $x->setOpen(false);
    expect($x->springs()[0]->preset)->toBe(Silk\Core\Motion\SpringPreset::Snappy);
});

it('toggles on a header click and posts Toggled', function () {
    $ui = silkUi(fn ($root) => $root->add(new Expander('more', 'More options')));
    $x = $ui->root->children()[0];
    $h = $x->headerRect();

    click($ui->router, $h->x + 10, $h->y + $h->h / 2);

    expect($x->isOpen())->toBeTrue()->and($ui->loop->posted[0])->toBeInstanceOf(Toggled::class)->and($ui->loop->posted[0]->on)->toBeTrue();
});

it('paints RavenDark as Raven: a Header bar a row and 4 high, the chevron in it, the title after it (expander.c:29-36, 235-245)', function () {
    [$x, $calls, $look] = expanderIn('raven-dark');
    $h = $look->metric(Metric::RowHeight) + 4;
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));

    expect($x->headerRect()->h)->toBe($h)
        ->and($calls[0][0])->toBe('rect')
        ->and([$calls[0][1][0]->h, $calls[0][1][1]])->toBe([$h, $look->color(Role::Header)->toHex()])
        ->and(array_column($calls, 0))->toContain('line')
        ->and($texts[0][1][1])->toBe($x->bounds()->x + $h);
});

it('paints macOS as a disclosure: a row high, nothing around, the title at 20 (expander.c:209-222)', function () {
    [$x, $calls, $look] = expanderIn('macos-dark');
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));

    expect($x->headerRect()->h)->toBe($look->metric(Metric::RowHeight))
        ->and(array_column($calls, 0))->not->toContain('rect')
        ->and($texts[0][1][1])->toBe($x->bounds()->x + 20);
});

it('paints GNOME as an expander row: a Panel card 50 high, square at the bottom once open, the content under it (expander.c:31-32, 59-92)', function () {
    [$x, $calls, $look] = expanderIn('gnome-light', true);
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    $r = $look->metric(Metric::PopupRadius);

    expect($x->headerRect()->h)->toBe(50.0)
        ->and($rects[0][1][2])->toBe([0.0, 0.0, $r, $r])
        ->and($rects[1][1][1])->toBe($look->color(Role::Panel)->toHex())
        ->and($rects[1][1][2])->toBe([$r, $r, 0.0, 0.0])
        ->and($rects[1][1][3])->toBe($look->color(Role::Border)->toHex());
});
