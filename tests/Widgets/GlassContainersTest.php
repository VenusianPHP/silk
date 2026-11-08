<?php

use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Core\Layout\Spacer;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Widgets\Splitter;
use Venusian\Silk\Tests\Support\SpyPainter;

function glassContainerIn(string $theme, \Closure $make): object
{
    $ui = silkUi($make);
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Size(300, 300));
    settleAll($ui);

    return $ui;
}

it('rounds the header of an expander by 10 in a glass look and leaves it square in Raven\'s plain look (expander.c:197)', function (string $theme, float $radius) {
    $ui = glassContainerIn($theme, fn ($root) => $root->expander('x', 'More'));
    $p = new SpyPainter;
    $ui->root->children()[0]->paint($p);
    $rects = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($rects[0][1][2])->toBe($radius);
})->with([['raven-glass-dark', 10.0], ['raven-dark', 0.0]]);

it('draws no line at rest in a glass splitter, a 4-wide 44-long grip under the pointer (splitter.c:209-217)', function () {
    $ui = glassContainerIn('raven-glass-dark', function ($root) {
        $root->add($s = new Splitter('split'), new Silk\Contracts\Widget\Slot(grow: 1));
        $s->add(new Spacer('l', 60, 200));
        $s->add(new Spacer('r', 80, 200));
    });
    $split = $ui->root->children()[0];
    $rest = new SpyPainter;
    $split->paint($rest);
    $d = $split->dividerRect(0);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $d->x + 2, y: $d->y + 10));
    $hot = new SpyPainter;
    $split->paint($hot);
    $grip = array_values(array_filter($hot->calls, fn ($c) => $c[0] === 'rect'));

    expect(array_filter($rest->calls, fn ($c) => in_array($c[0], ['line', 'rect'], true)))->toBe([])
        ->and($grip)->toHaveCount(1)->and([$grip[0][1][0]->w, $grip[0][1][0]->h])->toBe([4.0, 44.0])
        ->and($grip[0][1][1])->toEqual($ui->tree->look->color(Role::HandleHovered));
});

it('lays a dock group out with a bar 10 taller and 6 of padding, and paints it as an 18-radius panel of glass with a pill under the shown tab (dock_area.c:313-317, 658, 1289)', function () {
    $ui = dockUi('raven-glass-dark');
    $files = array_values(array_filter($ui->dock->children(), fn ($c) => $c->name() === 'files'))[0];
    $p = new SpyPainter;
    $ui->dock->paint($p);
    $glass = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
    $panels = array_filter($glass, fn ($c) => $c[1][1] === 18.0);

    expect([$files->bounds()->x, $files->bounds()->y])->toBe([6.0, $ui->tree->look->metric(Silk\Contracts\Look\Metric::ControlHeight) + 10])
        ->and($panels)->toHaveCount(3)->and(count($glass))->toBe(6);
});

it('paints no glass for a dock in a look without it', function () {
    $ui = dockUi('raven-dark');
    $p = new SpyPainter;
    $ui->dock->paint($p);

    expect(array_filter($p->calls, fn ($c) => $c[0] === 'glass'))->toBe([]);
});

it('draws a glass scroll bar as one thin capsule without a track, 2 in from the sides, 1 under the pointer, nothing when faded (common.c:623)', function () {
    $ui = glassContainerIn('raven-glass-dark', fn ($root) => $root->add(new Silk\Widgets\ScrollBar('bar')));
    $look = $ui->tree->look;
    $track = new Silk\Contracts\Geometry\Rect(0, 0, 12, 100);
    $paint = function (bool $hot, float $visible) use ($look, $track) {
        $p = new SpyPainter;
        Silk\Widgets\ScrollBars::paint($p, $look, $track, true, 10.0, 40.0, $hot, $visible);

        return $p->calls;
    };
    $rest = $paint(false, 1.0);
    $hot = $paint(true, 1.0);

    expect($paint(false, 0.0))->toBe([])->and($rest)->toHaveCount(1)
        ->and([$rest[0][1][0]->x, $rest[0][1][0]->w, $rest[0][1][2]])->toBe([4.0, 4.0, 12.0])
        ->and([$hot[0][1][0]->x, $hot[0][1][0]->w])->toBe([3.0, 6.0]);
});

it('turns the buttons of a header bar into glass capsules on macOS 26, the flat ones only while pressed (button.c:92-110)', function () {
    $ui = glassContainerIn('macos-glass-dark', function ($root) {
        $bar = new Silk\Widgets\HeaderBar('bar', 'T');
        $bar->button('plain', 'Plain');
        $bar->button('off', 'Off')->setStyle(Silk\Widgets\ButtonStyle::Flat);
        $root->add($bar);
        $root->button('outside', 'Outside')->setStyle(Silk\Widgets\ButtonStyle::Flat);
    });
    $count = function (Silk\Contracts\Widget\Widget $w) {
        $p = new SpyPainter;
        $w->paint($p);

        return count(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
    };
    [$bar, $outside] = $ui->root->children();
    [$plain, $flat] = $bar->children();

    expect($count($plain))->toBe(1)->and($count($flat))->toBe(0)->and($count($outside))->toBe(0);
});
