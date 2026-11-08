<?php

use Silk\Contracts\Geometry\Size;
use Silk\Text\TextMeasurer;
use Silk\Contracts\Look\FontSpec;

beforeEach(function () {
    if (! hasFontExts()) {
        $this->markTestSkipped('needs the font exts and intl (php 8.4)');
    }
    $this->m = new TextMeasurer(freshDriver());
    $this->style = new FontSpec('sans-serif', 13.0);
});

it('breaks at spaces when two words fit a line', function () {
    $w = max($this->m->measure('the quick', $this->style)->w, $this->m->measure('brown fox', $this->style)->w) + 1;
    expect($this->m->measure('the quick brown', $this->style)->w)->toBeGreaterThan($w);

    expect($this->m->wrap('the quick brown fox', $this->style, $w))->toBe(['the quick', 'brown fox']);
});

it('breaks a word wider than the line at graphemes, no line too wide', function () {
    $w = $this->m->measure('aaaaa', $this->style)->w;

    $lines = $this->m->wrap(str_repeat('a', 40), $this->style, $w);

    expect(count($lines))->toBeGreaterThan(5)
        ->and(implode('', $lines))->toBe(str_repeat('a', 40));
    foreach ($lines as $line) {
        expect($this->m->measure($line, $this->style)->w)->toBeLessThanOrEqual($w + 0.001);
    }
});

it('keeps an empty text as no lines and a short one as one', function () {
    expect($this->m->wrap('', $this->style, 100))->toBe([])
        ->and($this->m->wrap('hi', $this->style, 100))->toBe(['hi']);
});

it('measures a wrapped label as lines times line height', function () {
    $ui = silkUi(fn ($root) => $root->label('note', 'the quick brown fox jumps over the lazy dog')->setWrap(), $this->m);
    $label = $ui->root->children()[0];
    $style = $ui->look->font();
    $width = $this->m->measure('the quick brown', $style)->w;

    $size = $label->measure(new Size($width, 1000));
    $lines = $this->m->wrap($label->text(), $style, $width);

    expect(count($lines))->toBeGreaterThan(1)
        ->and($size->h)->toEqualWithDelta(count($lines) * $this->m->metrics($style)['lineHeight'], 1e-9)
        ->and($size->w)->toBeLessThanOrEqual($width);
});

it('paints one text call per wrapped line, a line height apart', function () {
    $ui = silkUi(fn ($root) => $root->label('note', 'the quick brown fox jumps over the lazy dog')->setWrap(), $this->m);
    $ui->tree->layout(new Size(140, 300));
    $label = $ui->root->children()[0];
    $p = new Venusian\Silk\Tests\Support\SpyPainter;

    $label->paint($p);

    $ys = array_map(fn ($c) => $c[1][2], $p->calls);
    $lh = $this->m->metrics($ui->look->font())['lineHeight'];
    expect(count($ys))->toBeGreaterThan(1)->and($ys[1] - $ys[0])->toEqualWithDelta($lh, 1e-9);
});
