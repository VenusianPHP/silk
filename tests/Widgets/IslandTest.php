<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Mail\IslandChanged;
use Silk\Core\Motion\SpringPreset;

it('measures a folded capsule around its first content and an unfolded panel around its second (island.c:108-131)', function () {
    $ui = islandUi();
    $label = $ui->island->children()[0]->measure(new Silk\Contracts\Geometry\Size(400, 400));

    $size = fn () => $ui->island->measure(new Silk\Contracts\Geometry\Size(400, 400));

    expect([$size()->w, $size()->h])->toBe([max($label->w + 28, 96.0), max($label->h + 10, 30.0)]);

    $ui->island->setExpanded(true);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
    settleAll($ui);
    expect([$size()->w, $size()->h])->toBe([236.0, 110.0])
        ->and($ui->loop->posted)->toBe([]);
});

it('springs open on BOUNCY and settles shut on SMOOTH; the layout follows it (island.c:285-292)', function () {
    $ui = islandUi();
    $ui->island->setExpanded(true);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
    $ui->tree->advance(1 / 60);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
    [$w] = $ui->island->springs()[1] === null ? [0] : [$ui->island->springs()[1]];

    expect($w->preset)->toBe(SpringPreset::Bouncy)
        ->and($ui->island->bounds()->h)->toBeGreaterThan(30.0)->toBeLessThan(110.0);
    $ui->island->setExpanded(false);
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
    expect($w->preset)->toBe(SpringPreset::Smooth);
});

it('shows only the content it is on to the pointer (island.c:358-361)', function () {
    $ui = islandUi();
    [$fps, $panel] = $ui->island->children();

    expect([$fps->isVisible(), $panel->isVisible()])->toBe([true, false]);
    $ui->island->setExpanded(true);
    expect([$fps->isVisible(), $panel->isVisible()])->toBe([false, true]);
});

it('unfolds under the pointer and folds 0.28 after it leaves; IslandChanged both times (island.c:35-36, 196-212)', function () {
    $ui = islandUi();
    islandPointer($ui, EventType::MouseMove);

    expect($ui->island->isExpanded())->toBeTrue();
    settleAll($ui);
    islandPointer($ui, EventType::Leave);
    $ui->tree->advance(0.2);
    expect($ui->island->isExpanded())->toBeTrue();
    $ui->tree->advance(0.1);
    expect($ui->island->isExpanded())->toBeFalse()
        ->and(array_map(fn ($m) => [$m::class, $m->expanded], $ui->loop->posted))->toBe([[IslandChanged::class, true], [IslandChanged::class, false]]);
});

it('stays pinned by a click, and a second click or a press elsewhere folds it (island.c:226-264, 499-512)', function () {
    $ui = islandUi();
    $ui->island->setExpandOnHover(false);
    click($ui->router, ...centre($ui->island));
    expect($ui->island->isExpanded())->toBeTrue();
    islandPointer($ui, EventType::Leave);
    $ui->tree->advance(1.0);
    expect($ui->island->isExpanded())->toBeTrue();

    click($ui->router, ...centre($ui->root->children()[1]));
    expect($ui->island->isExpanded())->toBeFalse();
});

it('holds open for expandFor seconds, then folds when the pointer is not there (island.c:582-591)', function () {
    $ui = islandUi();
    $ui->island->expandFor(1.0);

    expect($ui->island->isExpanded())->toBeTrue();
    $ui->tree->advance(0.6);
    expect($ui->island->isExpanded())->toBeTrue();
    $ui->tree->advance(0.5);
    expect($ui->island->isExpanded())->toBeFalse();
});

it('does not unfold on hover when told not to, nor on a click when told not to', function () {
    $ui = islandUi();
    $ui->island->setExpandOnHover(false)->setExpandOnClick(false);
    islandPointer($ui, EventType::MouseMove);
    click($ui->router, ...centre($ui->island));

    expect($ui->island->isExpanded())->toBeFalse();
});

it('paints dark whatever the theme: its subtree takes the dark twin (island.c:12-15, 249-252)', function () {
    $ui = islandUi('raven-light');
    $twin = $ui->tree->look->darkTwin();
    $label = $ui->island->children()[0];
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->island->paint($p);
    $texts = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text'));
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($texts[0][1][3]->toHex())->toBe($twin->color(Role::Text)->toHex())
        ->and($rects[0][1][1]->toHex())->toBe('#08080afc')
        ->and(count(array_filter($p->calls, fn ($c) => $c[0] === 'shadow')))->toBe(2)
        ->and((fn () => $this->look())->call($label)->color(Role::Window)->toHex())->toBe($twin->color(Role::Window)->toHex());
});

it('paints as a panel of the window\'s light look when given it (island.c:404-409)', function () {
    $ui = islandUi('raven-light');
    $ui->island->setTheme('window');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->island->paint($p);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($rects[0][1][1]->toHex())->toBe($ui->tree->look->color(Role::Popup)->toHex());
});

it('records the camera housing but never goes there: blocked (roadmap #11)', function () {
    $ui = islandUi();

    expect($ui->island->setNotch(true)->notch())->toBeTrue()->and($ui->island->isInNotch())->toBeFalse();
});

it('takes the pointer on its capsule alone, so over a window it lets the rest through', function () {
    $ui = silkUi(function ($root) {
        $root->add($island = new Silk\Widgets\Island('island'), new Silk\Contracts\Widget\Slot(align: Silk\Contracts\Widget\Align::Fill));
        $island->add(new Silk\Widgets\Label('fps', '60 fps'));
    });
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(400, 400));
    settleAll($ui);
    $island = $ui->root->children()[0];

    expect($ui->tree->hitTest(200, 10))->not->toBeNull()
        ->and($ui->tree->hitTest(200, 300))->not->toBe($island)
        ->and($ui->tree->hitTest(10, 10))->not->toBe($island);
});

it('is smoked glass in a glass look, dark whatever the theme (island.c:434-448)', function () {
    $ui = islandUi('raven-glass-light');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->island->paint($p);
    $glass = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
    $g = $glass[0][1][2];

    // folded, not hovered: bevel 7, saturation 1.6, the rim 0.34, the tint 0x07070B at 184
    expect($glass)->toHaveCount(1)
        ->and([$g->bevel, $g->saturation, $g->frost, $g->shadow])->toBe([7.0, 1.6, 1.0, 12.0])
        ->and($g->rim->alpha)->toBe(0.34)
        ->and(round($g->tint->alpha * 255))->toBe(184.0)
        ->and(round($g->tint->red * 255))->toBe(7.0)
        ->and($p->methods())->not->toContain('rect');
});

it('is a panel of the window\'s light glass when given it (island.c:416-423)', function () {
    $ui = islandUi('raven-glass-light');
    $ui->island->setTheme('window');
    $p = new Venusian\Silk\Tests\Support\SpyPainter;
    $ui->island->paint($p);
    $glass = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));

    expect($glass)->toHaveCount(1)
        ->and($glass[0][1][2]->shadow)->toBe(12.0)
        ->and($glass[0][1][2]->refraction)->toBe(14.0);
});
