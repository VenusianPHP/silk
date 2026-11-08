<?php

use Silk\Contracts\Look\SyntaxToken;
use Silk\Core\Motion\LookBlend;
use Silk\Core\Motion\Spring;
use Silk\Core\Motion\SpringPreset;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Looks\ThemedLook;

$graph = json_decode(file_get_contents(__DIR__.'/../Fixtures/theme-tokens.json'), true);

it('gives each built-in theme the graph\'s code token colours, null for the text colour (syntax.c:887-903)', function (string $config, string $graphName) use ($graph) {
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use($config)->current();
    $side = $graph[$graphName]['dark'] ? 'dark' : 'light';

    expect(array_map(fn ($c) => $c->name, SyntaxToken::cases()))->toBe(array_keys($graph[$graphName]['syntax']));
    foreach (SyntaxToken::cases() as $t) {
        $want = $graph[$graphName]['syntax'][$t->name];
        expect($look->syntax($t)?->toHex())->toBe($want === null ? null : strtolower($want));
    }
    // Strong and Emphasis are the text (Raven 0); a dark theme takes the dark palette
    expect($look->syntax(SyntaxToken::Strong))->toBeNull()
        ->and($look->syntax(SyntaxToken::Comment)->toHex())->toBe($side === 'dark' ? '#7f8c98' : '#6b7885');
})->with(fn () => array_map(null, array_keys(BuiltInThemes::NAMES), array_values(BuiltInThemes::NAMES)));

it('takes a theme\'s own syntax colour first, opaque; a theme without falls back by darkness', function () {
    $base = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('raven-dark')->current();
    $look = fn (array $theme) => new ThemedLook($base->palette, $base->metrics, $base->styles, $theme);

    expect($look(['syntax' => ['Keyword' => '#112233/80'], 'dark' => true])->syntax(SyntaxToken::Keyword)->toHex())->toBe('#112233')
        ->and($look(['dark' => false])->syntax(SyntaxToken::Keyword)->toHex())->toBe('#9b2393')
        ->and($look([])->syntax(SyntaxToken::Keyword)->toHex())->toBe('#ff7ab2');
});

it('blends syntax colours in a theme switch; to or from the text colour changes half way', function () {
    $m = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace');
    [$dark, $light] = [$m->use('raven-dark')->current(), $m->use('raven-light')->current()];
    $half = new LookBlend($dark, $light, new Spring(0.5, SpringPreset::Smooth));

    expect($half->syntax(SyntaxToken::Keyword)->toHex())->toBe(LookBlend::mix($dark->syntax(SyntaxToken::Keyword), $light->syntax(SyntaxToken::Keyword), 0.5)->toHex())
        ->and($half->syntax(SyntaxToken::Strong))->toBeNull();
});
