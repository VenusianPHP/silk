<?php


use Silk\Contracts\Look\Role;
use Venusian\Silk\Tests\Support\SpyPainter;

it('measures, paints its text in the text colour and posts nothing', function () {
    $ui = silkUi(fn ($root) => $root->label('title', 'Hello'));
    $label = $ui->root->children()[0];
    $p = new SpyPainter;

    $label->paint($p);
    click($ui->router, ...centre($label));

    expect($label->measure(new Silk\Contracts\Geometry\Size(300, 300))->w)->toBeGreaterThan(0.0)
        ->and($p->methods())->toBe(['text'])
        ->and($p->calls[0][1][0])->toBe('Hello')
        ->and($p->calls[0][1][3]->toHex())->toBe($ui->look->color(Role::Text)->toHex())
        ->and($ui->loop->posted)->toBe([]);
});

it('re-lays out on setText', function () {
    $ui = silkUi(fn ($root) => $root->label('title', 'Hi'));
    $label = $ui->root->children()[0];

    $label->setText('Hello there');

    expect($label->text())->toBe('Hello there')->and($ui->tree->needsLayout())->toBeTrue();
});

it('builds the rest of the first slice from the factory', function () {
    $ui = silkUi(function ($root) {
        $root->separator('line');
        $root->progressBar('load', 0.25);
        $root->spacer('gap', 0, 10);
        $root->row('bar', 4)->button('a', 'A');
    });

    [$line, $load, $gap, $bar] = $ui->root->children();
    $p = new SpyPainter;
    // the bar glides from 0 (Raven progress_bar.c:29): settle it first
    foreach ($load->springs() as $s) {
        $s->finish();
    }
    $line->paint($p);
    $load->paint($p);

    expect($line)->toBeInstanceOf(Silk\Widgets\Separator::class)
        ->and($load->fraction())->toBe(0.25)
        ->and($bar->children()[0]->path())->toBe('window.bar.a')
        ->and($p->methods())->toBe(['line', 'rect', 'pushClip', 'rect', 'popClip']);
});

it('keeps one font per spec over 600 frames of the same label (Review Focus 4)', function () {
    $measurer = new Silk\Text\TextMeasurer(new Venusian\Surface\Tests\Support\Fakes\FakeFontDriver);
    $ui = silkUi(fn ($root) => $root->label('title', 'Hello'), $measurer);
    $label = $ui->root->children()[0];
    $e = new Venusian\Silk\Tests\Support\SpyEngine;
    $fonts = new ReflectionProperty($measurer, 'fonts');

    $e->frame(fn () => $label->paint(new Silk\Painter\EnginePainter($e, 1.0, null, $measurer)));
    $first = count($fonts->getValue($measurer));
    for ($i = 1; $i < 600; $i++) {
        $e->frame(fn () => $label->paint(new Silk\Painter\EnginePainter($e, 1.0, null, $measurer)));
    }

    expect($first)->toBe(1)->and(count($fonts->getValue($measurer)))->toBe($first)->and($e->argsOf('text'))->toHaveCount(600);
});

it('takes a colour role that follows a theme switch', function () {
    $looks = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-dark');
    $root = new Silk\Widgets\Column('window');
    $root->add($l = new Silk\Widgets\Label('error', 'Oops'));
    $tree = new Silk\Core\Tree\Tree($root, $looks->current());
    $tree->reducedMotion = true;
    $looks->watch($tree);
    $l->setColor(Silk\Contracts\Look\Role::Text, Silk\Contracts\Look\Role::Danger);
    $tree->layout(new Silk\Contracts\Geometry\Size(100, 30));
    $ink = function () use ($tree) {
        $p = new Venusian\Silk\Tests\Support\SpyPainter;
        $tree->repaintAll();
        $tree->paintAll($p);

        return array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text'))[0][1][3];
    };

    expect($ink())->toEqual($looks->current()->color(Silk\Contracts\Look\Role::Danger));
    $looks->use('raven-light');
    expect($ink())->toEqual($looks->current()->color(Silk\Contracts\Look\Role::Danger))
        ->and($looks->current()->color(Silk\Contracts\Look\Role::Danger))->not->toEqual(
            Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-dark')->current()->color(Silk\Contracts\Look\Role::Danger));
});
