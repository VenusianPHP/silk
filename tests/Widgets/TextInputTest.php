<?php

use Silk\Contracts\Look\Role;

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\TextInput;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\Services\ClipboardText;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Services\Clipboard;
use Venusian\Silk\Tests\Support\SpyPainter;

beforeEach(function () {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('needs intl');
    }
});

/** A UI with one TextInput 'name', focused. */
function inputUi(string $value = ''): object
{
    $ui = silkUi(fn ($root) => $root->add(new TextInput('name', $value, 'Your name')));
    $ui->input = $ui->root->children()[0];
    $ui->focus->focus($ui->input);

    return $ui;
}

function type(object $ui, string $text): void
{
    $ui->router->dispatch(new SilkEvent(EventType::Text, text: $text));
}

function edit(object $ui, EditCommand $c): void
{
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: $c));
}

it('is created as the twin: value, placeholder, secret', function () {
    $t = new TextInput('pw', 'abc', 'Password', true);

    expect($t->value())->toBe('abc')->and($t->placeholder())->toBe('Password')->and($t->isSecret())->toBeTrue();
});

it('inserts committed text and posts TextChanged for each user edit', function () {
    $ui = inputUi();
    type($ui, 'hi');
    edit($ui, EditCommand::DeleteBackward);

    expect($ui->input->value())->toBe('h')
        ->and(array_map(fn ($m) => [$m::class, $m->value], $ui->loop->posted))->toBe([[TextChanged::class, 'hi'], [TextChanged::class, 'h']])
        ->and($ui->loop->posted[0]->path)->toBe('window.name');
});

it('posts TextSubmitted once on Enter, and nothing for setValue', function () {
    $ui = inputUi('done');
    $ui->input->setValue('changed by code');
    $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: Key::ENTER));
    $ui->router->dispatch(new SilkEvent(EventType::Edit, command: EditCommand::InsertNewline));

    expect($ui->loop->posted)->toHaveCount(1)
        ->and($ui->loop->posted[0])->toBeInstanceOf(TextSubmitted::class)
        ->and($ui->loop->posted[0]->value)->toBe('changed by code');
});

it('maps the one-line edit commands to the buffer', function () {
    $ui = inputUi('hello world');
    edit($ui, EditCommand::MoveToLineStart);
    edit($ui, EditCommand::SelectWordRight);
    type($ui, 'HELLO');
    edit($ui, EditCommand::MoveDown);
    edit($ui, EditCommand::DeleteWordBackward);
    edit($ui, EditCommand::Undo);

    expect($ui->input->value())->toBe('HELLO world');
});

it('selects a word on double click and everything on triple click', function () {
    $ui = inputUi('hello world');
    $b = $ui->input->bounds();
    $down = fn (int $clicks, float $x) => $ui->router->dispatch(new SilkEvent(EventType::MouseDown, x: $x, y: $b->y + 5, button: MouseButton::LEFT, clicks: $clicks));
    $up = fn () => $ui->router->dispatch(new SilkEvent(EventType::MouseUp, x: $b->x + 1, y: $b->y + 5, button: MouseButton::LEFT, clicks: 1));

    $down(2, $b->x + $b->w - 12);
    $up();
    expect($ui->input->selectedText())->toBe('world');
    $down(3, $b->x + 12);
    $up();
    expect($ui->input->selectedText())->toBe('hello world');
});

it('copies and pastes through the clipboard when there is one', function () {
    $ui = inputUi('abc');
    $clip = new class implements Clipboard {
        public ?string $text = null;
        public function setText(string $text): void { $this->text = $text; }
        public function requestText(): string { return 'req-1'; }
        public function hasText(): bool { return $this->text !== null; }
    };
    edit($ui, EditCommand::SelectAll);
    edit($ui, EditCommand::Copy);
    expect($clip->text)->toBeNull();   // no clipboard yet: nothing happens

    $ui->input->setClipboard($clip);
    edit($ui, EditCommand::Copy);
    edit($ui, EditCommand::MoveToLineEnd);
    edit($ui, EditCommand::Paste);
    expect($ui->input->handles(new ClipboardText('other', 'no')))->toBeFalse()
        ->and($ui->input->handles(new ClipboardText('req-1', 'xyz')))->toBeTrue()
        ->and($clip->text)->toBe('abc')
        ->and($ui->input->value())->toBe('abcxyz');
});

it('paints the field, the text and a caret when focused', function () {
    $ui = inputUi('hey');
    $p = new SpyPainter;
    $ui->input->paint($p);

    expect($p->methods())->toContain('rect')->toContain('text')
        ->and(inputTexts($p))->toContain('hey');
});

it('paints dots for a secret value', function () {
    $ui = silkUi(fn ($root) => $root->add(new TextInput('pw', 'abc', null, true)));
    $p = new SpyPainter;
    $ui->root->children()[0]->paint($p);

    expect(inputTexts($p))->toBe(['•••']);
});

it('stays fast with 10,000 characters: 100 keys in under 200 ms', function () {
    $ui = inputUi(str_repeat('word ', 2000));
    $ui->input->paint(new SpyPainter);
    $start = hrtime(true);
    for ($i = 0; $i < 50; $i++) {
        type($ui, 'x');
        edit($ui, EditCommand::MoveLeft);
    }
    $ms = (hrtime(true) - $start) / 1e6;

    expect($ms)->toBeLessThan(200.0)->and(strlen($ui->input->value()))->toBe(10_050);
});

it('posts nothing while disabled', function () {
    $ui = inputUi('a');
    $ui->input->setEnabled(false);
    type($ui, 'b');

    expect($ui->loop->posted)->toBe([])->and($ui->input->value())->toBe('a');
});

it('repaints a focused idle field only when the caret toggles', function () {
    $now = 100.0;
    Silk\Text\CaretBlink::$clock = function () use (&$now) { return $now; };
    try {
        $ui = inputUi('idle');
        // let the focus ring land
        for ($i = 0; $i < 120; $i++) {
            $now += 1 / 60;
            $ui->tree->advance(1 / 60);
            $ui->tree->needsPaint() && $ui->tree->paintAll(new SpyPainter);
        }
        $paints = 0;
        $moving = false;
        for ($i = 0; $i < 120; $i++) {
            $now += 1 / 60;
            $moving = $ui->tree->advance(1 / 60) || $moving;
            if ($ui->tree->needsPaint()) {
                $paints++;
                $ui->tree->paintAll(new SpyPainter);
            }
        }

        expect($paints)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5)
            ->and($moving)->toBeFalse();
    } finally {
        Silk\Text\CaretBlink::$clock = null;
    }
});

/** RavenDark TextInput paints, in four states: the identity fixture the family move must keep. */
it('keeps RavenDark identical', function (string $state) {
    $ui = silkUi(fn ($root) => $root->add(new TextInput('name', $state === 'text' ? 'Ada' : '', 'Your name')));
    $input = $ui->root->children()[0];
    match ($state) {
        'disabled' => $input->setEnabled(false),
        'focusing' => (fn () => $this->focusSpring->snap(0.5))->call($input),
        'text' => $ui->focus->focus($input),
        default => null,
    };

    expectPaintFixture("textinput-raven-dark-{$state}", paintSnapshot($input));
})->with(['blank', 'disabled', 'focusing', 'text']);

it('draws the Aqua field on macOS: Input, a ControlBorder hairline, radius 4, text 5 in (platform_looks.c:111-125, text_input.c:33)', function () {
    [$input, $calls, $look] = fieldIn('macos-light');
    // the fill, then the outline in bands (sunk: a top edge of its own); the middle band is the hairline
    expect([$calls[0][1][1], $calls[0][1][2]])->toBe([$look->color(Role::Input)->toHex(), 4.0])
        ->and(array_slice($calls[5][1], 2))->toBe([4.0, $look->color(Role::ControlBorder)->toHex(), 1.0]);
    $pushClip = array_values(array_filter($calls, fn ($c) => $c[0] === 'pushClip' && $c[1][0][3] === $input->bounds()->h))[0][1][0];
    expect($pushClip[0])->toBe($input->bounds()->x + 5.0);
});

it('rings a focused macOS field outside, three wide, in Focus faded by the focus (platform_looks.c:317-321)', function () {
    [$input, $calls, $look] = fieldIn('macos-light', 'focusing');
    $b = $input->bounds();
    $rings = array_values(array_filter($calls, fn ($c) => $c[0] === 'rect' && ($c[1][4] ?? null) === 3.0));
    $focus = $look->color(Role::Focus);

    expect($rings)->toHaveCount(1)
        ->and($rings[0][1][0])->toBe([$b->x - 3, $b->y - 3, $b->w + 6, $b->h + 6])
        ->and($rings[0][1][2])->toBe(7.0)
        ->and($rings[0][1][3])->toBe((new Surface\NutsAndBolts\Color($focus->red, $focus->green, $focus->blue, $focus->alpha * 0.5))->toHex())
        ->and(array_filter($calls, fn ($c) => $c[0] === 'rect' && ($c[1][4] ?? null) === 1.0 && $c[1][0] !== [$b->x, $b->y, $b->w, $b->h]))->toBe([]);
});

it('draws the Adwaita entry on GNOME: no border, a Focus ring two wide inside when focused, text 9 in (platform_looks.c:181-194, text_input.c:33)', function () {
    [$input, $blank, $look] = fieldIn('gnome-light');
    [, $focusing] = fieldIn('gnome-light', 'focusing');

    // at rest the border is invisible: no width, or no alpha
    expect($blank[0][1][4])->toBe(0.0)
        ->and(substr($blank[0][1][3] ?? '#00000000', 7))->toBe('00')
        ->and($focusing[0][1][4])->toBe(1.0)
        ->and(count(array_filter($focusing, fn ($c) => $c[0] === 'rect')))->toBe(1);
    $pushClip = array_values(array_filter($blank, fn ($c) => $c[0] === 'pushClip'))[0][1][0];
    expect($pushClip[0])->toBe($input->bounds()->x + 9.0);
});

it('halves a disabled GNOME entry and its text (platform_looks.c:190-193)', function () {
    [, $calls, $look] = fieldIn('gnome-dark', 'disabled');
    $input = $look->color(Role::Input);

    expect($calls[0][1][1])->toBe((new Surface\NutsAndBolts\Color($input->red, $input->green, $input->blue, $input->alpha * 0.5))->toHex());
});

it('sinks the Aqua field: a top edge of its border at 1.5 times the alpha (platform_looks.c:118)', function () {
    $look = Silk\Looks\BuiltInThemes::register(new Silk\Looks\LookManager, 'sans-serif', 'monospace')->use('macos-light')->current();
    $body = $look->part('TextInput', 'Body', Silk\Contracts\Look\State::Normal);
    $border = $look->color(Role::ControlBorder);

    expect($body->borderTop->alpha)->toEqualWithDelta(min(1.0, $border->alpha * 1.5), 1e-9)
        ->and(substr($body->borderTop->toHex(), 0, 7))->toBe(substr($border->toHex(), 0, 7));
});
