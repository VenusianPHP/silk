<?php

use Silk\Text\Markdown\Markdown;

it('reads ATX and setext titles, closing hashes off', function () {
    expect(mdShape("# One\n### Three ###\nTwo\n---\nAlso one\n===\n####### seven"))->toBe([
        'H1 0.0.0: One', 'H3 0.0.0: Three', 'H2 0.0.0: Two', 'H1 0.0.0: Also one', 'P 0.0.0: ####### seven',
    ]);
});

it('reads strong, emphasis, both, code, strike, escapes and entities', function () {
    expect(mdShape('a **b** *i* ***both*** `c` ~~s~~ \\*no\\* &amp; &#x41; snake_case_word'))->toBe([
        'P 0.0.0: a |b:B| |i:I| |both:BI| |c:C| |s:S| *no* & A snake_case_word',
    ]);
});

it('keeps unclosed and spaced marks as text', function () {
    expect(mdShape('**open and * alone'))->toBe(['P 0.0.0: **open and * alone']);
});

it('reads links, autolinks and bare addresses, without the closing punctuation', function () {
    $md = Markdown::parse('[t **x**](u) <http://a.b> see https://c.d/e.');

    expect(mdShape('[t **x**](u) <http://a.b> see https://c.d/e.'))->toBe(['P 0.0.0: t @0|x:B@0| |http://a.b@1| see |https://c.d/e@2|.'])
        ->and($md->links)->toBe(['u', 'http://a.b', 'https://c.d/e']);
});

it('joins lines with a space; two spaces or a backslash break the line', function () {
    expect(mdShape("one\ntwo  \nthree\\\nfour"))->toBe(['P 0.0.0: one two⏎|three⏎|four']);
});

it('reads nested lists, numbers and tasks', function () {
    expect(mdShape("- a\n  - b\n- [x] done\n- [ ] todo\n\n1. one\n2. two"))->toBe([
        'P 0.1.1: a', 'P 0.2.1: b', 'P 0.1.4: done', 'P 0.1.3: todo', 'P 0.1.2: one', 'P 0.1.2: two',
    ]);
});

it('does not start a list with a number other than 1 inside a paragraph', function () {
    expect(mdShape("Year\n2024. was good"))->toBe(['P 0.0.0: Year 2024. was good']);
});

it('reads fenced code with its language, raw, and a ~~~ fence that ``` does not close', function () {
    expect(mdShape("```php {x}\n  \$a = '**';\n```\n~~~\n```\n~~~"))->toBe([
        "Code 0.0.0 [php]   \$a = '**';", 'Code 0.0.0 [] ```',
    ]);
});

it('reads rules and quotes, nested', function () {
    expect(mdShape("---\n> q\n> > deeper\n\npara"))->toBe(['Rule 0.0.0: ', 'P 1.0.0: q', 'P 2.0.0: deeper', 'P 0.0.0: para']);
});

it('reads tables with alignment and missing cells', function () {
    expect(mdShape("| a | b | c |\n|:--|:-:|--:|\n| 1 | **2** |\nafter"))->toBe([
        'Table 0.0.0 3x2: a,b~1,c~2,1,2:B~1,~2', 'P 0.0.0: after',
    ]);
});

it('reads a lone image as an image block, an inline one as its alt text', function () {
    $md = Markdown::parse("![logo](a.png)\n\ntext ![alt](b.png)");

    expect(mdShape("![logo](a.png)\n\ntext ![alt](b.png)"))->toBe(['Image 0.0.0: logo', 'P 0.0.0: text |alt@1'])
        ->and($md->links)->toBe(['a.png', 'b.png']);
});

it('drops the indentation the lines share past the first', function () {
    expect(mdShape("# T\n    - a\n    - b"))->toBe(['H1 0.0.0: T', 'P 0.1.1: a', 'P 0.1.1: b']);
});

it('gives the plain text, cells by tabs', function () {
    expect(Markdown::parse("# T\n**b** `c`\n\n| x | y |\n|---|---|\n| 1 | 2 |\n```\ncode\n```")->plainText())
        ->toBe("T\nb c\nx\ty\n1\t2\ncode");
});
