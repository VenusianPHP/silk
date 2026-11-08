<?php

use Silk\Contracts\Look\Metric;
use Silk\Contracts\Look\Role;
use Silk\Widgets\Radio;

it('keeps one radio of a parent on: checking one turns the other off, both post Toggled (checkbox.c:383-396)', function () {
    $ui = silkUi(function ($root) {
        $root->radio('small', 'Small', true);
        $root->radio('large', 'Large');
    });
    [$small, $large] = $ui->root->children();

    click($ui->router, ...centre($large));

    expect($large)->toBeInstanceOf(Radio::class)
        ->and([$small->isChecked(), $large->isChecked()])->toBe([false, true])
        ->and(array_map(fn ($m) => [$m->on], $ui->loop->posted))->toBe([[true], [false]])
        ->and($ui->loop->names())->toBe(['view.toggled.main.window.large', 'view.toggled.main.window.small']);
});

it('does not turn a checked radio off when clicked again (checkbox.c:400-405)', function () {
    $ui = silkUi(fn ($root) => $root->radio('small', 'Small', true));
    $r = $ui->root->children()[0];

    click($ui->router, ...centre($r));

    expect($r->isChecked())->toBeTrue()->and($ui->loop->posted)->toBe([]);
});

it('leaves checkboxes of the same parent alone, and posts nothing for setChecked', function () {
    $ui = silkUi(function ($root) {
        $root->checkbox('c', 'Check', true);
        $root->radio('a', 'A', true);
        $root->radio('b', 'B');
    });
    [$c, $a, $b] = $ui->root->children();

    $b->setChecked(true);

    expect([$c->isChecked(), $a->isChecked(), $b->isChecked()])->toBe([true, false, true])->and($ui->loop->posted)->toBe([]);
});

it('paints RavenDark as Raven: a round box, an AccentText dot 0.4 of the mark (checkbox.c:343, 353-356)', function () {
    [, $calls, $look] = radioIn('raven-dark', true);
    $mark = $look->metric(Metric::CheckSize);
    $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));

    expect($rects[0][1][2])->toBe($mark / 2)
        ->and($rects[0][1][1])->toBe($look->color(Role::Accent)->toHex())
        ->and(round($rects[1][1][0]->w, 4))->toBe(round($mark * 0.4, 4))
        ->and($rects[1][1][1])->toBe($look->color(Role::AccentText)->toHex())
        ->and(array_column($calls, 0))->not->toContain('line');
});

it('paints the platforms round, with their dot: macOS 6, GNOME 8 (checkbox.c:150-155, 230-241)', function () {
    foreach (['macos-light' => 6.0, 'gnome-dark' => 8.0] as $name => $dot) {
        [, $calls, $look] = radioIn($name, true);
        $rects = array_values(array_filter($calls, fn ($x) => $x[0] === 'rect'));

        expect($rects[0][1][2])->toBe($rects[0][1][0]->h / 2)
            ->and($rects[0][1][1])->toBe($look->color(Role::Accent)->toHex())
            ->and(end($rects)[1][0]->w)->toBe($dot)
            ->and(end($rects)[1][1])->toBe($look->color(Role::AccentText)->toHex());
    }
});
