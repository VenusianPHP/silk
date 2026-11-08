<?php

use Silk\Text\Syntax\Language;
use Silk\Text\Syntax\Syntax;

it('colours C: directive, header, keywords, types, functions, numbers, comments', function () {
    expect(syntaxSpans('C', ['#include <stdio.h>', 'static int main(void) { return 0x1F + 1e-3; } // done']))->toBe([
        ['#include:Directive', '<stdio.h>:String'],
        ['static:Keyword', 'int:Type', 'main:Function', 'void:Type', 'return:Keyword', '0x1F:Number', '1e-3:Number', '// done:Comment'],
    ]);
});

it('carries a block comment across lines and nests in Rust', function () {
    expect(syntaxSpans('c', ['a /* one', 'two */ b']))->toBe([['/* one:Comment'], ['two */:Comment']])
        ->and(syntaxSpans('rs', ['/* a /* b */', 'still */ fn x() {}']))->toBe([['/* a /* b */:Comment'], ['still */:Comment', 'fn:Keyword', 'x:Function']]);
});

it('reads multi-line texts: escaped, raw with their own close, and char literals', function () {
    expect(syntaxSpans('Python', ['x = """a', 'b""" + 1']))->toBe([['"""a:String'], ['b""":String', '1:Number']])
        ->and(syntaxSpans('C++', ['R"(a\\', ')" "q\\"x"']))->toBe([['R"(a\\:String'], [')":String', '"q\\"x":String']])
        ->and(syntaxSpans('Rust', ["fn f<'a>(c: char) { 'x' }"]))->toBe([['fn:Keyword', 'char:Type', "'x':String"]]);
});

it('marks annotations', function () {
    expect(syntaxSpans('Java', ['@Override void f()']))->toBe([['@Override:Directive', 'void:Type', 'f:Function']])
        ->and(syntaxSpans('Rust', ['#[derive(Debug)] struct S;']))->toBe([['#[derive(Debug)]:Directive', 'struct:Keyword']]);
});

it('colours CSS selectors, properties and values', function () {
    expect(syntaxSpans('css', ['a.b:hover { color: #fff !important; width: calc(10px); display: none }']))->toBe([[
        'a:Tag', '.b:Attribute', ':hover:Keyword', 'color:Attribute', '#fff:Number', '!important:Keyword',
        'width:Attribute', 'calc:Function', '10px:Number', 'display:Attribute', 'none:Keyword',
    ]]);
});

it('colours HTML with script and style inside, carried across lines', function () {
    expect(syntaxSpans('HTML', ['<p class="x">&amp;</p><script>', 'let a = 1;</script>', '<!-- c']))->toBe([
        ['<:Punctuation', 'p:Tag', 'class:Attribute', '=:Punctuation', '"x":String', '>:Punctuation', '&amp;:Number',
            '</:Punctuation', 'p:Tag', '>:Punctuation', '<:Punctuation', 'script:Tag', '>:Punctuation'],
        ['let:Keyword', '1:Number', '</:Punctuation', 'script:Tag', '>:Punctuation'],
        ['<!-- c:Comment'],
    ]);
});

it('colours Markdown as written', function () {
    expect(syntaxSpans('md', ['# Title', '- [x] **bold** and _em_ `code` [l](u) https://a.b', '```php', 'echo 1;', '```']))->toBe([
        ['# Title:Heading'],
        ['-:Keyword', '[x]:Keyword', '**bold**:Strong', '_em_:Emphasis', '`code`:String', '[l](u):Link', 'https://a.b:Link'],
        ['```:Punctuation', 'php:Keyword'],
        ['echo 1;:String'],
        ['```:Punctuation'],
    ]);
});

it('finds languages by name, alias and extension, and replaces on register', function () {
    expect(Syntax::find('JS')->name)->toBe('JavaScript')
        ->and(Syntax::find('none'))->toBeNull()
        ->and(Syntax::forFile('src/a.test.TSX'))->toBe('TypeScript')
        ->and(Syntax::forFile('Makefile'))->toBeNull()
        ->and(Syntax::find('GLSL')->directive)->toBe('#');

    Syntax::register(new Language('Toy', 'toy', 'yes'));
    expect(syntaxSpans('toy', ['yes no']))->toBe([['yes:Keyword']]);
    Syntax::register(new Language('toy', 'toy', 'no'));
    expect(syntaxSpans('Toy', ['yes no']))->toBe([['no:Keyword']]);
});
