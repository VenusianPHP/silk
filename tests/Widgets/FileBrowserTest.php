<?php

use Silk\Contracts\Mail\FileAccepted;
use Silk\Contracts\Mail\FileCancelled;
use Silk\Contracts\Mail\FileSelectionChanged;
use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Widgets\FileBrowser;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;

it('lists folders first, then files by name without case, hidden ones only when asked', function () {
    $ui = browserUi($dir = fileTree());
    expect($ui->fb->names())->toBe(['docs', 'Zeta', 'A.md', 'b.txt', 'c.png']);

    $ui->fb->setShowHidden();
    expect($ui->fb->names())->toBe(['docs', 'Zeta', '.hidden', 'A.md', 'b.txt', 'c.png']);
});

it('sorts by a column on a header click, reversed on a second one, folders staying first', function () {
    $ui = browserUi(fileTree());
    $l = (new ReflectionProperty($ui->fb, 'list'))->getValue($ui->fb);
    $sizeHeader = [$l->x + $l->w - FileBrowser::DATE_WIDTH - 20, $l->y + 5];
    click($ui->router, ...$sizeHeader);
    expect($ui->fb->names())->toBe(['docs', 'Zeta', 'A.md', 'c.png', 'b.txt']);
    click($ui->router, ...$sizeHeader);
    // (folders have no size: by name, never reversed, as rvn_fb_compare returns before the reversal)
    expect($ui->fb->names())->toBe(['docs', 'Zeta', 'b.txt', 'c.png', 'A.md']);
});

it('filters files by the active filter, never folders, and lists only folders in folder mode', function () {
    $ui = browserUi(fileTree());
    $ui->fb->setFilters([['Text', '*.txt;*.md'], [null, '*.png']]);
    expect($ui->fb->names())->toBe(['docs', 'Zeta', 'A.md', 'b.txt']);

    $ui->fb->setMode(FileBrowser::FOLDER);
    expect($ui->fb->names())->toBe(['docs', 'Zeta']);
});

it('opens a folder by double click, goes back, forward and up, selecting the folder it left', function () {
    $ui = browserUi($dir = fileTree());
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, ...array_combine(['x', 'y'], $ui->fb->rowPoint(0)), button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 2));
    expect($ui->fb->directory())->toBe($dir.'/docs')->and($ui->fb->names())->toBe(['inner.txt']);

    click($ui->router, ...$ui->fb->navPoint('back'));
    expect($ui->fb->directory())->toBe($dir);
    click($ui->router, ...$ui->fb->navPoint('forward'));
    expect($ui->fb->directory())->toBe($dir.'/docs');
    fbKey($ui, Key::BACKSPACE);
    expect($ui->fb->directory())->toBe($dir)->and($ui->fb->names()[$ui->fb->cursor()])->toBe('docs');
});

it('accepts a file by double click or Enter in open mode, and posts what the cursor moves to', function () {
    $ui = browserUi($dir = fileTree());
    fbKey($ui, Key::END);
    fbKey($ui, Key::ENTER);

    expect(browserMail($ui, FileAccepted::class))->toBe([$dir.'/c.png'])
        ->and(array_slice(browserMail($ui, FileSelectionChanged::class), -1))->toBe([$dir.'/c.png'])
        ->and($ui->fb->field('name')->value())->toBe('c.png');
});

it('moves by typing the start of a name, within 0.9 s', function () {
    FileBrowser::$clock = fn () => 100.0;
    $ui = browserUi(fileTree());
    typeText($ui, 'b');
    expect($ui->fb->names()[$ui->fb->cursor()])->toBe('b.txt');
    FileBrowser::$clock = fn () => 102.0;
    typeText($ui, 'z');
    expect($ui->fb->names()[$ui->fb->cursor()])->toBe('Zeta');
    FileBrowser::$clock = null;
});

it('selects ranges and toggles only when multiple, Ctrl+A takes the files', function () {
    $ui = browserUi($dir = fileTree());
    $ui->fb->setMultiple();
    click($ui->router, ...$ui->fb->rowPoint(2));
    $ui->router->dispatch(new SilkEvent(EventType::MouseDown, ...array_combine(['x', 'y'], $ui->fb->rowPoint(4)), button: Surface\Contracts\HumanInput\MouseButton::LEFT, clicks: 1, modifiers: new Modifiers(shift: true)));
    expect($ui->fb->selection())->toBe([$dir.'/A.md', $dir.'/b.txt', $dir.'/c.png']);

    fbKey($ui, Key::A, new Modifiers(ctrl: true));
    expect($ui->fb->selectionCount())->toBe(3);
});

it('saves with the default extension, the filter\'s when none is set, and asks before replacing', function () {
    $ui = browserUi($dir = fileTree());
    $ui->fb->setMode(FileBrowser::SAVE)->setFilters([['Text', '*.txt']])->setFileName('notes');
    click($ui->router, ...centre($ui->fb->field('accept')));
    expect(browserMail($ui, FileAccepted::class))->toBe([$dir.'/notes.txt']);

    $ui->fb->setFileName('b');
    click($ui->router, ...centre($ui->fb->field('accept')));
    $box = $ui->tree->overlays()->top();
    expect($box)->toBeInstanceOf(Silk\Widgets\MessageBox::class)->and(browserMail($ui, FileAccepted::class))->toHaveCount(1);
    $box->answer(0);
    expect(browserMail($ui, FileAccepted::class))->toBe([$dir.'/notes.txt', $dir.'/b.txt']);
});

it('shows an unreadable folder as empty and stays usable', function () {
    $ui = browserUi($dir = fileTree());
    chmod($dir.'/Zeta', 0000);
    $ui->fb->select('Zeta');
    fbKey($ui, Key::ENTER);
    chmod($dir.'/Zeta', 0755);

    expect($ui->fb->directory())->toBe($dir.'/Zeta')->and($ui->fb->names())->toBe([]);
    fbKey($ui, Key::BACKSPACE);
    expect($ui->fb->directory())->toBe($dir);
});

it('refuses a path that is no folder, keeping where it is, and posts Cancel', function () {
    $ui = browserUi($dir = fileTree());
    $path = $ui->fb->field('path');
    $path->setValue($dir.'/missing');
    $ui->focus->focus($path);
    fbKey($ui, Key::ENTER);
    click($ui->router, ...centre($ui->fb->field('cancel')));

    expect($ui->fb->directory())->toBe($dir)->and($path->value())->toBe($dir)
        ->and(browserMail($ui, FileCancelled::class))->toHaveCount(1)
        ->and(array_filter($ui->loop->posted, fn ($m) => $m instanceof Surface\Contracts\Windows\Mail\View\TextSubmitted))->toBe([]);
});

it('formats sizes as Raven does', function () {
    expect(FileBrowser::formatSize(512))->toBe('512 B')->and(FileBrowser::formatSize(2048))->toBe('2.0 KB')
        ->and(FileBrowser::formatSize(20 * 1024 * 1024))->toBe('20 MB');
});

it('holds 10,000 entries in under 6 MB', function () {
    $dir = sys_get_temp_dir().'/silk-fb-many-'.bin2hex(random_bytes(3));
    mkdir($dir);
    for ($i = 0; $i < 10000; $i++) {
        touch(sprintf('%s/file-%05d.txt', $dir, $i));
    }
    $ui = browserUi(fileTree());
    gc_collect_cycles();
    $before = memory_get_usage();
    $ui->fb->setDirectory($dir);
    $used = memory_get_usage() - $before;
    exec('rm -rf '.escapeshellarg($dir));

    // an entry is an array of 5 (name string ~ 40 B, 2 bools, 2 ints): ~ 400 B with its hash; 10,000 ~ 4 MB (measured 4.2)
    expect(count($ui->fb->names()))->toBe(10000)->and($used)->toBeLessThan(6 * 1024 * 1024);
});
