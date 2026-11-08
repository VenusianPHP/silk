<?php

use Silk\Files\FileSystem;

it('reads a folder with its kinds, sizes and hidden entries, and null for one that cannot be read', function () {
    $dir = fileTree();
    $entries = array_column(FileSystem::read($dir), null, 'name');

    expect(array_keys($entries))->toEqualCanonicalizing(['b.txt', 'A.md', 'c.png', '.hidden', 'docs', 'Zeta'])
        ->and($entries['docs']['isDir'])->toBeTrue()->and($entries['b.txt']['size'])->toBe(2048)
        ->and($entries['.hidden']['hidden'])->toBeTrue()->and($entries['A.md']['modified'])->toBe(1_000_000_000)
        ->and(FileSystem::read($dir.'/nothing'))->toBeNull();
});

it('walks paths as fs.c does: parents to the root, absolute with ~ and dots', function () {
    expect(FileSystem::parent('/a/b/'))->toBe('/a')->and(FileSystem::parent('/a'))->toBe('/')->and(FileSystem::parent('/'))->toBeNull()
        ->and(FileSystem::absolute('~/x/../y'))->toBe(rtrim(FileSystem::home(), '/').'/y')
        ->and(FileSystem::absolute('/a//b/./c/..'))->toBe('/a/b')
        ->and(FileSystem::child('/a/', 'b'))->toBe('/a/b');
});

it('matches patterns without case: *.ext lists, *, *.*, exact names; none matches all', function () {
    expect(FileSystem::matches('*.png;*.JPG', 'x.jpg'))->toBeTrue()->and(FileSystem::matches('*.png', 'x.jpg'))->toBeFalse()
        ->and(FileSystem::matches('*.*', 'Makefile'))->toBeTrue()->and(FileSystem::matches('makefile', 'Makefile'))->toBeTrue()
        ->and(FileSystem::matches(null, 'x'))->toBeTrue();
});

it('lists the usual places that exist, Home first and Computer last', function () {
    $places = FileSystem::places();

    expect($places[0])->toBe(['label' => 'Home', 'path' => FileSystem::home()])
        ->and(end($places))->toBe(['label' => 'Computer', 'path' => '/']);
});
