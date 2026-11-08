<?php

use Silk\Text\Editor\EditorText;
use Silk\Text\Editor\MarkerKind;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand as C;

it('replaces across lines and gives the position after the new text (rvn_ed_replace)', function () {
    $t = new EditorText;
    $t->reset("one\ntwo\nthree");
    $end = $t->replace([0, 1], [2, 2], "X\r\nY", false);

    expect($t->lines)->toBe(['oX', 'Yree'])->and($end)->toBe([1, 1])
        ->and(EditorText::after([3, 4], "ab\ncd\r\nef"))->toBe([5, 2]);
});

it('normalises "\r\n" and "\r" in setValue, forgets the history and posts nothing', function () {
    $ui = editorUi();
    $ui->ed->setValue("a\r\nb\rc");

    expect($ui->ed->value())->toBe("a\nb\nc")->and($ui->ed->lineCount())->toBe(3)
        ->and($ui->ed->canUndo())->toBeFalse()->and(textMail($ui))->toBe([]);
});

it('undoes each edit path in one step, typing in runs of up to 48 characters', function () {
    $ui = editorUi();
    edType($ui, str_repeat('a', 50));
    edCommand($ui, C::InsertNewline);
    edPaste($ui, "pasted\ntext");
    edCommand($ui, C::InsertTab);
    $steps = [];
    while ($ui->ed->canUndo()) {
        edCommand($ui, C::Undo);
        $steps[] = $ui->ed->value();
    }

    expect($steps)->toBe([
        str_repeat('a', 50)."\npasted\ntext",
        str_repeat('a', 50)."\n",
        str_repeat('a', 50),
        str_repeat('a', 48),
        '',
    ]);
    edCommand($ui, C::Redo, C::Redo);
    expect($ui->ed->value())->toBe(str_repeat('a', 50));
});

it('posts TextChanged for the user\'s edits only', function () {
    $ui = editorUi('x');
    $ui->ed->insertText('code');
    $ui->ed->undo();
    edType($ui, 'y');

    expect(textMail($ui))->toBe(['yx']);
});

it('pairs brackets and quotes in code, steps over the closer, and takes an empty pair back together', function () {
    $ui = editorUi('', 'C');
    edType($ui, 'f(');
    expect(edShow($ui))->toBe('f(|)');
    edType($ui, '"');
    expect(edShow($ui))->toBe('f("|")');
    edType($ui, 'a"');
    expect(edShow($ui))->toBe('f("a"|)');
    edType($ui, ')');
    expect(edShow($ui))->toBe('f("a")|');
    edType($ui, '[');
    edCommand($ui, C::DeleteBackward);
    expect(edShow($ui))->toBe('f("a")|');
});

it('does not pair inside a comment, or a quote after a word', function () {
    $ui = editorUi('// x', 'C');
    $ui->ed->setCursor(0, 4);
    edType($ui, '(');
    $ui->ed->setValue('');
    edType($ui, "it's");

    expect($ui->ed->value())->toBe("it's");
    $ui->ed->setValue('// x');
    $ui->ed->setCursor(0, 4);
    edType($ui, '(');
    expect($ui->ed->value())->toBe('// x(');
});

it('wraps a selection in brackets', function () {
    $ui = editorUi('abc', 'C');
    $ui->ed->select(0, 0, 0, 3);
    edType($ui, '(');

    expect($ui->ed->value())->toBe('(abc)');
});

it('indents a new line, one level deeper after a bracket, the closer on its own line', function () {
    $ui = editorUi('  if (x) {}', 'C');
    $ui->ed->setTabInsertsSpaces()->setTabSize(4);
    $ui->ed->setCursor(0, 10);
    edCommand($ui, C::InsertNewline);

    expect(edShow($ui))->toBe("  if (x) {\n      |\n  }");
});

it('brings a closing brace alone on its line back one level', function () {
    $ui = editorUi("{\n        ", 'C');
    $ui->ed->setTabInsertsSpaces()->setTabSize(4);
    $ui->ed->setCursor(1, 8);
    edType($ui, '}');

    expect($ui->ed->value())->toBe("{\n    }");
});

it('closes an opening tag in markup, not a void one in HTML', function () {
    $ui = editorUi('<p', 'HTML');
    $ui->ed->setCursor(0, 2);
    edType($ui, '>');
    expect(edShow($ui))->toBe('<p>|</p>');

    $ui->ed->setValue('<br');
    $ui->ed->setCursor(0, 3);
    edType($ui, '>');
    expect($ui->ed->value())->toBe('<br>');
});

it('indents and outdents selected lines, keeping them selected', function () {
    $ui = editorUi("a\nb\n\nc");
    $ui->ed->select(0, 0, 3, 1);
    edCommand($ui, C::InsertTab);
    expect($ui->ed->value())->toBe("\ta\n\tb\n\n\tc")->and($ui->ed->selectedLines())->toBe([0, 3]);

    edCommand($ui, C::InsertBacktab);
    expect($ui->ed->value())->toBe("a\nb\n\nc");
});

it('writes spaces to the next tab stop', function () {
    $ui = editorUi("\tab");
    $ui->ed->setTabInsertsSpaces()->setTabSize(4);
    $ui->ed->setCursor(0, 3);
    edCommand($ui, C::InsertTab);

    expect($ui->ed->value())->toBe("\tab  ");
});

it('comments lines out at the least indentation, and back in', function () {
    $ui = editorUi("  a\n    b", 'C');
    $ui->ed->select(0, 0, 1, 5);
    $slash = new Silk\Contracts\Input\SilkEvent(Silk\Contracts\Input\EventType::KeyDown, key: Key::SLASH, modifiers: new Modifiers(meta: true));
    $ui->router->dispatch($slash);
    expect($ui->ed->value())->toBe("  // a\n  //   b");

    $ui->router->dispatch($slash);
    expect($ui->ed->value())->toBe("  a\n    b");
});

it('moves by characters over UTF-8, by words by class, Home to the indentation then the start', function () {
    $ui = editorUi("  héllo, world");
    $ui->ed->setCursor(0, 14);
    edCommand($ui, C::MoveWordLeft);
    expect($ui->ed->cursor())->toBe([0, 10]);
    edCommand($ui, C::MoveWordLeft, C::MoveWordLeft);
    expect($ui->ed->cursor())->toBe([0, 2]);
    edCommand($ui, C::MoveRight, C::MoveRight);
    expect($ui->ed->cursor())->toBe([0, 5]);
    edCommand($ui, C::MoveToLineStart);
    expect($ui->ed->cursor())->toBe([0, 2]);
    edCommand($ui, C::MoveToLineStart);
    expect($ui->ed->cursor())->toBe([0, 0]);
    edCommand($ui, C::SelectToLineEnd);
    expect($ui->ed->selectedText())->toBe('  héllo, world');
});

it('copies and cuts through the clipboard; read-only copies but does not change', function () {
    $ui = editorUi('abc def');
    $ui->ed->select(0, 4, 0, 7);
    edCommand($ui, C::Copy);
    expect($ui->clip->text)->toBe('def');

    $ui->ed->setReadOnly(true);
    edCommand($ui, C::Cut);
    edType($ui, 'x');
    expect($ui->ed->value())->toBe('abc def');

    $ui->ed->setReadOnly(false);
    edCommand($ui, C::Cut);
    expect($ui->ed->value())->toBe('abc ');
});

it('keeps markers on the lines below an edit and drops those of the lines it changes', function () {
    $ui = editorUi("a\nb\nc");
    $ui->ed->addMarker(0, 0, 1, MarkerKind::Error, 'top');
    $ui->ed->addMarker(2, 0, -1, MarkerKind::Warning, 'below');
    $ui->ed->setCursor(1, 1);
    edCommand($ui, C::InsertNewline);

    expect($ui->ed->markerCount())->toBe(2);
    edType($ui, 'x');
    $ui->ed->setCursor(0, 1);
    edType($ui, 'y');
    expect($ui->ed->markerCount())->toBe(1);
});

it('holds 50,000 lines in under 5 MB', function () {
    $before = memory_get_usage();
    $ui = editorUi(str_repeat("line of some code\n", 50000));
    $used = memory_get_usage() - $before;

    // a list of 50,001 strings: about 32 B of zval + 24 B header + 18 B text each, rounded up by the
    // allocator to ~ 3.7 MB (measured 3.73); the reset copy is freed before this point
    expect($ui->ed->lineCount())->toBe(50001)->and($used)->toBeLessThan(5 * 1024 * 1024);
});
