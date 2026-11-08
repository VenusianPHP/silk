<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Widgets\Button;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Venusian\Silk\Tests\Support\SpyPainter;

it('posts exactly one ButtonClicked with its path on a click inside', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];

    click($ui->router, ...centre($ok));

    expect($ok)->toBeInstanceOf(Button::class)
        ->and($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(ButtonClicked::class)
        ->and($ui->loop->posted[0]->path)->toBe('window.ok')
        ->and($ui->loop->posted[0]->uuid)->toBe($ok->uuid())
        ->and($ui->loop->names())->toBe(['view.clicked.main.window.ok']);
});

it('posts nothing when released outside', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    [$x, $y] = centre($ui->root->children()[0]);

    click($ui->router, $x, $y, 290, 290);

    expect($ui->loop->posted)->toBe([]);
});

it('posts nothing for a programmatic setLabel, and re-lays out', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];

    $ok->setLabel('A longer label');

    expect($ok->label())->toBe('A longer label')
        ->and($ui->tree->needsLayout())->toBeTrue()
        ->and($ui->loop->posted)->toBe([]);
});

it('ignores every event when disabled', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0]->setEnabled(false);
    $ui->focus->focus($ok);

    click($ui->router, ...centre($ok));
    pressKey($ui->router, Key::ENTER);

    expect($ui->loop->posted)->toBe([])
        ->and($ok->handle(new SilkEvent(EventType::MouseDown, button: Surface\Contracts\HumanInput\MouseButton::LEFT)))->toBeFalse();
});

it('moves the hover spring on Enter and back on Leave', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];
    expect($ok->hover()->atRest())->toBeTrue();

    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, ...centre($ok)));
    expect($ok->hover()->atRest())->toBeFalse();

    $ui->router->dispatch(new SilkEvent(EventType::Leave));
    expect($ok->hover()->value())->toBe(0.0)->and($ok->hover()->atRest())->toBeTrue();
});

it('presses on the press spring and kicks it on click', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];

    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, ...centre($ok), button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
    expect($ok->press()->atRest())->toBeFalse();
    $ok->press()->snap(1.0);

    $ui->router->dispatch(new SilkEvent(EventType::MouseUp, ...centre($ok), button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1));
    expect($ok->press()->velocity())->toBe(-11.0);
});

it('activates on Enter and Space when focused', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ui->focus->focus($ui->root->children()[0]);

    pressKey($ui->router, Key::ENTER);
    pressKey($ui->router, Key::SPACE);

    expect($ui->loop->names())->toBe(['view.clicked.main.window.ok', 'view.clicked.main.window.ok']);
});

it('measures to the control height and paints its body and label', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];
    $p = new SpyPainter;

    $ok->paint($p);

    [$rect, $text] = [$p->calls[1], $p->calls[array_key_last($p->calls)]];
    expect($ok->bounds()->h)->toBe(26.0)
        ->and($rect[0])->toBe('rect')
        ->and($rect[1][1]->toHex())->toBe($ui->look->color(Role::Control)->toHex())
        ->and($rect[1][2])->toBe(4.0)
        ->and($text[0])->toBe('text')
        ->and($text[1][0])->toBe('OK');
});

it('casts its part shadow before the body: blur 3, the Shadow role at a third of its alpha', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ok = $ui->root->children()[0];
    $p = new SpyPainter;

    $ok->paint($p);

    [$method, $args] = $p->calls[0];
    expect($method)->toBe('shadow')
        ->and($p->calls[1][0])->toBe('rect')
        ->and($args[0])->toEqual($ok->bounds())
        ->and($args[1])->toBe(4.0)
        ->and($args[2])->toBe(3.0)
        ->and($args[3]->alpha)->toEqualWithDelta((120 / 255) / 3, 1e-9)
        ->and([$args[4] ?? 0.0, $args[5] ?? 0.0])->toBe([0.0, 0.0]);
});

it('centres its label on the font box: equal room above the ascent and below the descent (defect d)', function () {
    if (! hasFontExts()) {
        $this->markTestSkipped('needs the font exts (php 8.4)');
    }
    $ui = silkUi(fn ($root) => $root->button('fit', 'Fit'));
    $fit = $ui->root->children()[0];
    $p = new SpyPainter;

    $fit->paint($p);

    [$text, $x, $y, , $style] = $p->calls[array_key_last($p->calls)][1];
    $m = $ui->tree->measurer()->metrics($style);
    $b = $fit->bounds();
    $above = ($y - $m['ascent']) - $b->y;
    $below = ($b->y + $b->h) - ($y + $m['descent']);
    $width = $ui->tree->measurer()->measure('Fit', $style)->w;
    expect($text)->toBe('Fit')
        ->and($b->h)->toBe(26.0)
        ->and(abs($above - $below))->toBeLessThanOrEqual(1.0)
        ->and(abs(($x + $width / 2) - ($b->x + $b->w / 2)))->toBeLessThanOrEqual(0.5);
});

it('releases with a snappy spring on a smooth look, and a bouncy one on a springy look', function (string $look, Silk\Core\Motion\SpringPreset $release) {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    inLook($ui, $look);
    $ok = $ui->root->children()[0];

    click($ui->router, ...centre($ok));

    expect($ok->press()->preset)->toBe($release);
})->with([
    'macos-light (smooth)' => ['macos-light', Silk\Core\Motion\SpringPreset::Snappy],
    'raven-dark (springy)' => ['raven-dark', Silk\Core\Motion\SpringPreset::Bouncy],
]);

it('does not squash on a smooth look', function () {
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    inLook($ui, 'macos-light');
    $ok = $ui->root->children()[0];
    $ok->press()->snap(1.0);
    $p = new SpyPainter;

    $ok->paint($p);

    $rect = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'))[0];
    expect($rect[1][0])->toEqual($ok->bounds());
});

it('gives each look its motion', function () {
    $m = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace');

    expect($m->use('raven-light')->current()->motion())->toBe(Silk\Contracts\Look\Motion::Springy)
        ->and($m->use('gnome-dark')->current()->motion())->toBe(Silk\Contracts\Look\Motion::Smooth);
});

it('keeps RavenDark identical (the family looks add rows, never change Raven)', function () {
    $calls = buttonPaint('raven-dark');

    expect(array_column($calls, 0))->toBe(['shadow', 'rect', 'text'])
        ->and($calls[0][1][3])->toBe('#0000002'.'8')
        ->and(array_slice($calls[1][1], 1))->toBe(['#35353a', 4.0, '#4c4c54', 1.0]);
});

it('draws the Aqua push button on macOS: Control, a ControlBorder hairline, no shadow, no hover change (platform_looks.c:96-100)', function () {
    $look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('macos-light')->current();
    $calls = buttonPaint('macos-light', fn ($b) => $b->hover()->snap(1.0));

    // the fill, then the outline in bands (the light look has a bottom edge of its own); the middle band is the hairline
    expect(array_column($calls, 0))->toBe(['rect', 'pushClip', 'rect', 'popClip', 'pushClip', 'rect', 'popClip', 'text'])
        ->and(array_slice($calls[0][1], 1))->toBe([$look->color(Role::Control)->toHex(), $look->metric(Silk\Contracts\Look\Metric::CornerRadius)])
        ->and(array_slice($calls[2][1], 2))->toBe([$look->metric(Silk\Contracts\Look\Metric::CornerRadius), $look->color(Role::ControlBorder)->toHex(), 1.0]);
});

it('draws the Adwaita button on GNOME: no border, no shadow, hover ControlHovered (platform_looks.c:160-172)', function () {
    $look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('gnome-dark')->current();
    $calls = buttonPaint('gnome-dark', fn ($b) => $b->hover()->snap(1.0));

    expect(array_column($calls, 0))->toBe(['rect', 'text'])
        ->and($calls[0][1][1])->toBe($look->color(Role::ControlHovered)->toHex())
        ->and($calls[0][1][3] ?? null)->toBeNull();
});

it('halves a disabled button on GNOME: background and text at half alpha (platform_looks.c:175-178)', function () {
    $look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('gnome-light')->current();
    $part = $look->part('Button', 'Body', Silk\Contracts\Look\State::Disabled);

    expect($part->background->alpha)->toEqualWithDelta($look->color(Role::Control)->alpha * 0.5, 1e-9)
        ->and($part->text->alpha)->toEqualWithDelta($look->color(Role::Text)->alpha * 0.5, 1e-9);
});

it('gives the Aqua push button its edge: a lighter top in the dark, a darker bottom in the light (platform_looks.c:98-99)', function () {
    $m = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace');
    $dark = $m->use('macos-dark')->current()->part('Button', 'Body', Silk\Contracts\Look\State::Normal);
    $light = $m->use('macos-light')->current()->part('Button', 'Body', Silk\Contracts\Look\State::Normal);
    $disabled = $m->current()->part('Button', 'Body', Silk\Contracts\Look\State::Disabled);

    expect($dark->borderTop->toHex())->toBe('#ffffff26')->and($dark->borderBottom)->toBeNull()
        ->and($light->borderBottom->toHex())->toBe('#00000040')->and($light->borderTop)->toBeNull()
        ->and($disabled->borderBottom->alpha)->toEqualWithDelta(64 / 255 * 0.6, 1e-9);
});

it('paints the Aqua edge in bands', function () {
    $calls = buttonPaint('macos-light');

    expect(array_column($calls, 0))->toBe(['rect', 'pushClip', 'rect', 'popClip', 'pushClip', 'rect', 'popClip', 'text']);
});

it('paints a primary button in the accent with AccentText, in every family (button.c:56-59; platform_looks.c:87-93, 164-167)', function (string $look) {
    $calls = buttonPaint($look, fn ($b) => $b->setStyle(Silk\Widgets\ButtonStyle::Primary));
    $l = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($look)->current();
    $rects = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'));
    $texts = array_values(array_filter($calls, fn ($c) => $c[0] === 'text'));

    expect($rects[0][1][1])->toBe($l->color(Silk\Contracts\Look\Role::Accent)->toHex())
        ->and($texts[0][1][3])->toBe($l->color(Silk\Contracts\Look\Role::AccentText)->toHex());
})->with(['raven-dark', 'raven-light', 'macos-light', 'macos-dark', 'gnome-dark']);

it('gives macOS\'s default button a lighter top edge and a darker bottom one, from its fill (platform_looks.c:90-91)', function () {
    $calls = buttonPaint('macos-light', fn ($b) => $b->setStyle(Silk\Widgets\ButtonStyle::Primary));
    $l = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('macos-light')->current();
    $accent = $l->color(Silk\Contracts\Look\Role::Accent);
    $borders = array_map(fn ($c) => $c[1][3] ?? null, array_filter($calls, fn ($c) => $c[0] === 'rect'));

    expect($borders)->toContain(Silk\Core\Motion\LookBlend::mix($accent, new Surface\NutsAndBolts\Color(1, 1, 1, 1), 0.25)->toHex())
        ->and($borders)->toContain(Silk\Core\Motion\LookBlend::mix($accent, new Surface\NutsAndBolts\Color(0, 0, 0, 1), 0.15)->toHex());
});

it('paints a flat button as nothing at rest, the ControlHovered fill under the pointer (button.c:60-62)', function () {
    $rest = buttonPaint('raven-dark', fn ($b) => $b->setStyle(Silk\Widgets\ButtonStyle::Flat));
    $hot = buttonPaint('raven-dark', function ($b) {
        $b->setStyle(Silk\Widgets\ButtonStyle::Flat);
        $b->hover()->snap(1.0);
    });
    $l = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('raven-dark')->current();
    $fill = fn ($calls) => array_values(array_filter($calls, fn ($c) => $c[0] === 'rect'))[0][1][1] ?? null;

    expect($fill($rest) === null || substr($fill($rest), 7) === '00')->toBeTrue()
        ->and(array_column($rest, 0))->not->toContain('shadow')
        ->and($fill($hot))->toBe($l->color(Silk\Contracts\Look\Role::ControlHovered)->toHex());
});

/** A button tree in a glass look. */
function glassButtonUi(string $theme, ?Closure $configure = null): object
{
    $ui = silkUi(fn ($root) => $root->button('ok', 'OK'));
    $ui->tree->reducedMotion = true;
    $ui->tree->setLook(Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use($theme)->current());
    $ui->tree->layout(new Silk\Contracts\Geometry\Size(300, 300));
    $ui->button = $ui->root->children()[0];
    $configure && $configure($ui->button);

    return $ui;
}

function glassOf(object $button): array
{
    $p = new SpyPainter;
    $button->paint($p);

    return [$p, array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'))];
}

it('paints a capsule of glass in a Raven glass look: the Control surface, a radius of half its height', function () {
    $ui = glassButtonUi('raven-glass-dark');
    [$p, $glass] = glassOf($ui->button);
    $b = $ui->button->bounds();

    expect($glass)->toHaveCount(1)
        ->and($glass[0][1][1])->toBe($b->h * 0.5)
        ->and(round($glass[0][1][2]->tint->alpha * 255))->toBe(28.0)
        ->and($p->methods())->not->toContain('rect')
        ->and($p->methods())->toContain('text');
});

it('paints a default button as the accent glass with the accent text, and a flat one as glass only while used', function () {
    $primary = glassButtonUi('raven-glass-dark', fn ($b) => $b->setStyle(Silk\Widgets\ButtonStyle::Primary));
    [$p, $glass] = glassOf($primary->button);
    $text = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'text'))[0][1][3];

    expect(round($glass[0][1][2]->tint->alpha * 255))->toBe(214.0)
        ->and($text->toHex())->toBe($primary->look->color(Role::AccentText)->toHex());

    $flat = glassButtonUi('raven-glass-dark', fn ($b) => $b->setStyle(Silk\Widgets\ButtonStyle::Flat));
    expect(glassOf($flat->button)[1])->toBe([]);
});

it('keeps the flat paint of a solid look and of a platform look, glass or not', function (string $theme) {
    $ui = glassButtonUi($theme);

    expect(glassOf($ui->button)[1])->toBe([]);
})->with(['raven-dark', 'macos-glass-dark', 'gnome-light']);
