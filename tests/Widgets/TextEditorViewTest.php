<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Text\Editor\MarkerKind;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand as C;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\NutsAndBolts\Color;

it('paints only the rows in view of a 50,000-line text', function () {
    $lines = [];
    for ($i = 0; $i < 50000; $i++) {
        $lines[] = "line {$i}";
    }
    $ui = editorUi(implode("\n", $lines));
    $ui->ed->scrollToLine(30000);
    toastRun($ui, 1); // the view glides there (SMOOTH), as Raven's does
    $texts = edTexts($ui);

    expect(count($texts))->toBeLessThan(40)->and($texts[0])->toBe('line 30000');
});

it('wraps a long line into rows, moves down through them, and End stops before a cut', function () {
    $ui = editorUi(trim(str_repeat('word ', 80)));
    $rows = (new ReflectionMethod($ui->ed, 'update'))->invoke($ui->ed)[0];
    expect($rows->rowCount(0))->toBeGreaterThan(2);

    edCommand($ui, C::MoveDown);
    [$line, $column] = $ui->ed->cursor();
    expect($line)->toBe(0)->and($column)->toBeGreaterThan(0);

    $ui->ed->setCursor(0, 0);
    edCommand($ui, C::MoveToLineEnd);
    $end = $ui->ed->cursor()[1];
    expect($end)->toBeLessThan(strlen($ui->ed->value()))->and($ui->ed->value()[$end])->toBe(' ');

    $ui->ed->setWrap(false);
    $rows = (new ReflectionMethod($ui->ed, 'update'))->invoke($ui->ed)[0];
    expect($rows->rowCount(0))->toBe(1);
});

it('places the caret by click, a word by double click, the line by triple click, extends with Shift', function () {
    $ui = editorUi("alpha beta\ngamma");
    [$x, $y] = edPoint($ui, 0, 7);
    edClick($ui, $x, $y);
    expect($ui->ed->cursor())->toBe([0, 7]);

    edClick($ui, $x, $y, 2);
    expect($ui->ed->selectedText())->toBe('beta');

    edClick($ui, $x, $y, 3);
    expect($ui->ed->selectedText())->toBe("alpha beta\n");

    [$x2, $y2] = edPoint($ui, 1, 2);
    edClick($ui, ...edPoint($ui, 0, 2));
    edClick($ui, $x2, $y2, 1, true);
    expect($ui->ed->selectedText())->toBe("pha beta\nga");
});

it('scrolls to a line at the top, round-trips its scroll position, and posts ViewScrolled only when watched', function () {
    $ui = editorUi(implode("\n", range(1, 400)));
    $ui->ed->scrollToLine(100);
    $top = $ui->ed->scrollPosition();
    $ui->ed->scrollTo(0, 0);
    $ui->ed->scrollTo($top->x, $top->y);
    $wheel = new SilkEvent(EventType::Wheel, x: 50, y: 50, wheelY: -1);
    $ui->router->dispatch($wheel);
    $quiet = array_filter($ui->loop->posted, fn ($m) => $m instanceof ViewScrolled);
    $ui->ed->watchScroll();
    $ui->router->dispatch($wheel);

    expect(edTexts($ui)[0] ?? null)->not->toBeNull()
        ->and($ui->ed->scrollPosition()->y)->not->toBe($top->y)
        ->and($quiet)->toBe([])
        ->and(array_filter($ui->loop->posted, fn ($m) => $m instanceof ViewScrolled))->toHaveCount(1);
    $ui->ed->scrollTo($top->x, $top->y);
    expect($ui->ed->scrollPosition()->y)->toEqualWithDelta($top->y, 1e-9);
});

it('zooms by 1.1 with Ctrl + =, back to 1 with Ctrl + 0, within 0.5 to 4, and not when it may not', function () {
    $ui = editorUi('text');
    $key = fn (Key $k) => $ui->router->dispatch(new SilkEvent(EventType::KeyDown, key: $k, modifiers: new Modifiers(ctrl: true)));
    $key(Key::EQUALS);
    expect($ui->ed->zoom())->toBe(1.1);
    for ($i = 0; $i < 40; $i++) {
        $key(Key::EQUALS);
    }
    expect($ui->ed->zoom())->toBe(4.0);
    $key(Key::DIGIT_0);
    expect($ui->ed->zoom())->toBe(1.0);
    $ui->ed->setZoomable(false);
    $key(Key::MINUS);
    expect($ui->ed->zoom())->toBe(1.0);
});

it('numbers the lines in a gutter, the caret\'s in Text, a dot for a marker, a wave under it', function () {
    $ui = editorUi("one\ntwo");
    $ui->ed->setLineNumbers()->addMarker(1, 0, 3, MarkerKind::Error, 'bad');
    $calls = edPaint($ui);
    $texts = array_column(array_map(fn ($c) => [$c[1][0], $c[1][3]], array_filter($calls, fn ($c) => $c[0] === 'text')), 1, 0);
    $danger = $ui->look->color(Role::Danger);

    expect($texts['1'])->toEqual($ui->look->color(Role::Text))->and($texts['2'])->toEqual($ui->look->color(Role::TextPlaceholder))
        ->and(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][1] == $danger))->toHaveCount(1)
        ->and(array_filter($calls, fn ($c) => $c[0] === 'line' && $c[1][4] == $danger))->not->toBeEmpty();
});

it('shows a marker\'s message as the tooltip when the pointer is on it', function () {
    $ui = editorUi('fault here');
    $ui->ed->addMarker(0, 0, 5, MarkerKind::Warning, 'fix me');
    [$x, $y] = edPoint($ui, 0, 2);
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y));

    expect($ui->ed->tooltip())->toBe('fix me');
});

it('colours code with the theme\'s syntax colours and lights the bracket by the caret and its partner', function () {
    $ui = editorUi('int f(x);', 'C');
    $ui->ed->setCursor(0, 5);
    $calls = edPaint($ui);
    $texts = array_column(array_map(fn ($c) => [$c[1][0], $c[1][3]], array_filter($calls, fn ($c) => $c[0] === 'text')), 1, 0);
    $accent = $ui->look->color(Role::Accent);
    $boxes = array_filter($calls, fn ($c) => $c[0] === 'rect' && ($c[1][3] ?? null) !== null && abs($c[1][3]->alpha - $accent->alpha * 0.7) < 1e-6);

    expect(rgbKey($texts['int']))->toBe(rgbKey($ui->look->syntax(SyntaxToken::Type)))
        ->and(rgbKey($texts['f']))->toBe(rgbKey($ui->look->syntax(SyntaxToken::Function)))
        ->and($boxes)->toHaveCount(2);
});

it('paints the twin\'s line backgrounds and range styles', function () {
    $ui = editorUi("aé bc\nde");
    $ui->ed->setLineBackgrounds([1 => Color::hex('#112233')]);
    $ui->ed->setRangeStyles([new TextRangeStyle(0, 1, 2, foreground: Color::hex('#445566'), underline: true)]);
    $calls = edPaint($ui);
    $texts = array_column(array_map(fn ($c) => [$c[1][0], $c[1][3]], array_filter($calls, fn ($c) => $c[0] === 'text')), 1, 0);

    expect(array_filter($calls, fn ($c) => $c[0] === 'rect' && $c[1][1]->toHex() === Color::hex('#112233')->toHex()))->toHaveCount(1)
        ->and($texts['é '] ?? null)->not->toBeNull()
        ->and($texts['é ']->toHex())->toBe(Color::hex('#445566')->toHex());
});

it('keeps a painted 50,000-line text, rows and caches included, under 8 MB', function () {
    edPaint(editorUi("warm\nup")); // classes, fonts and look caches load once; not the editor's
    foreach ([true, false] as $wrap) {
        gc_collect_cycles();
        $before = memory_get_usage();
        $ui = editorUi(str_repeat("line of some code\n", 50000));
        $ui->ed->setWrap($wrap);
        edPaint($ui);

        // lines ~ 3.7 MB; per line a cached width or cut list and a row start (16 B packed each, ~ 0.8 MB a
        // table), the lexer states when a language is set; measured 6.7 MB wrapped and 5.7 MB unwrapped
        expect(memory_get_usage() - $before)->toBeLessThan(8 * 1024 * 1024);
        unset($ui);
    }
});
