<?php

use Silk\Contracts\Input\EventType;
use Silk\Contracts\Input\SilkEvent;
use Silk\Contracts\Look\Role;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Text\Markdown\MarkdownLayout;
use Venusian\Silk\Tests\Support\SpyPainter;

it('measures to its laid-out size and shows the text without its marks', function () {
    $ui = markdownUi('# Title'."\n\n".'Some **bold** text');
    $laid = $ui->label->markdownLayout();

    expect($ui->label->bounds()->w)->toBe($laid->size->w)->and($ui->label->bounds()->h)->toBe($laid->size->h)
        ->and(array_column(mdTexts($ui), 0))->toBe(['Title', 'Some ', 'bold', ' text'])
        ->and($ui->label->plainText())->toBe("Title\nSome bold text");
});

it('makes titles bold and larger (1.9 for a level 1) and underlines the two largest', function () {
    $ui = markdownUi("# Big\n\n### Small");
    $runs = $ui->label->markdownLayout()->runs;
    $size = $ui->look->font()->size;
    $rules = array_filter($ui->label->markdownLayout()->boxes, fn ($b) => $b['kind'] === MarkdownLayout::BOX_RULE);

    expect($runs[0]['font']->size)->toBe(floor($size * 1.9 + 0.5))->and($runs[0]['font']->weight)->toBe(700)
        ->and($runs[1]['font']->size)->toBe(floor($size * 1.26 + 0.5))->and($rules)->toHaveCount(1);
});

it('wraps to the width it is given', function () {
    $ui = markdownUi(str_repeat('word ', 40), 100);
    $lines = array_unique(array_column($ui->label->markdownLayout()->runs, 'lineY'));

    expect(count($lines))->toBeGreaterThan(1)->and($ui->label->bounds()->w)->toBeLessThanOrEqual(300.0);
});

it('paints links in Link, quotes dimmed, code on Code tiles, and code tokens in the theme syntax colours', function () {
    $ui = markdownUi("[go](u) `x`\n\n> quoted\n\n```c\nint a;\n```");
    $p = new SpyPainter;
    $ui->label->render($p);
    $texts = array_column(array_map(fn ($c) => [$c[1][0], $c[1][3]], array_filter($p->calls, fn ($c) => $c[0] === 'text')), 1, 0);
    $text = $ui->look->color(Role::Text);
    $fills = array_map(fn ($c) => $c[1][1], array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($texts['go'])->toEqual($ui->look->color(Role::Link))
        ->and($texts['quoted']->alpha)->toBe($text->alpha * 0.72)
        ->and($texts['int'])->toEqual($ui->look->syntax(SyntaxToken::Type))
        ->and($texts[' a;'])->toEqual($text)
        ->and($fills)->toContainEqual($ui->look->color(Role::Code));
});

it('lets the pointer through without links, and takes it with them', function () {
    expect(markdownUi('plain')->label->hits(1, 1))->toBeFalse()
        ->and(markdownUi('[a](b)')->label->hits(1, 1))->toBeTrue();
});

it('underlines the hot link and posts LinkActivated on a press and release on it', function () {
    $ui = markdownUi('see [the docs](https://x.y/docs) now');
    $run = array_values(array_filter($ui->label->markdownLayout()->runs, fn ($r) => $r['link'] === 0))[0];
    $b = $ui->label->bounds();
    [$x, $y] = [$b->x + $run['x'] + 4, $b->y + $run['lineY'] + 2];
    $ui->router->dispatch(new SilkEvent(EventType::MouseMove, x: $x, y: $y));
    $hot = $ui->label->hotLink();
    click($ui->router, $x, $y);
    click($ui->router, $x, $y, $b->x + 1, $y);

    expect($hot)->toBe(0)->and(linkMail($ui))->toBe(['https://x.y/docs']);
});

it('draws list markers: a disc, a ring for a nested list, a task box, and numbers', function () {
    $ui = markdownUi("- a\n  - b\n- [x] c\n\n1. d");
    $kinds = array_column($ui->label->markdownLayout()->boxes, 'kind');

    expect($kinds)->toBe([MarkdownLayout::BOX_BULLET, MarkdownLayout::BOX_BULLET, MarkdownLayout::BOX_TASK_DONE])
        ->and(array_column(mdTexts($ui), 0))->toContain('1.');
});

it('selects, copies and finds paragraphs in the text shown, without list numbers', function () {
    $ui = markdownUi("**ab** cd\n\n1. ef");
    $laid = $ui->label->markdownLayout();
    $first = $laid->runs[0];

    expect($laid->offsetAt(-5, $first['lineY'] + 1))->toBe($first['start'])
        ->and($laid->selectedText(0, $laid->parsedLength))->toBe("ab cd\nef")
        ->and($laid->paragraphAt($first['start'] + 1))->toBe([$first['start'], $first['start'] + 5]);
});

it('lays a table out with a header row and lines around and between', function () {
    $ui = markdownUi("| a | b |\n|---|---|\n| 1 | 2 |");
    $kinds = array_count_values(array_column($ui->label->markdownLayout()->boxes, 'kind'));

    expect($kinds[MarkdownLayout::BOX_TABLE_HEADER])->toBe(1)->and($kinds[MarkdownLayout::BOX_TABLE_LINE])->toBe(2 + 1 + 3);
});
