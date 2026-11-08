<?php

namespace Silk\Text\Syntax;

use Silk\Contracts\Look\SyntaxToken as T;

/**
 * Raven's lexers (widgets/syntax.c): code, style sheets, markup and Markdown as written.
 * A lexer reads one line and cuts it into spans [start, end, SyntaxToken] (byte offsets); what no
 * span covers is plain text. What a line starts in is one small int handed from each line to the
 * next (Raven's state byte), so a line is coloured without reading the lines before it again.
 */
final class Syntax
{
    /** Code state: 1..15 inside a block comment (its depth), 16 + k inside the k-th multi-line text. */
    private const int CODE_MULTILINE = 16;

    private const int CSS_COMMENT = 8;

    private const int XML_CONTENT = 0;

    private const int XML_COMMENT = 1;

    private const int XML_TAG = 2;

    private const int XML_DOUBLE_QUOTED = 3;

    private const int XML_SINGLE_QUOTED = 4;

    private const int XML_CDATA = 5;

    private const int XML_DECLARATION = 6;

    private const int XML_SCRIPT = 0x40;

    private const int XML_STYLE = 0x80;

    private const int MD_IN_CODE = 1;

    private const int MD_TILDE = 2;

    /** @var array<int, Language>|null registered languages, built-in ones first */
    private static ?array $languages = null;

    /** @var list<array{int, int, T}> */
    private array $spans = [];

    private function __construct(private readonly string $text, private readonly int $length) {}

    /**
     * Cuts one line into spans (Raven rvn_syntax_line, syntax.c:869-885).
     *
     * @param  list<array{int, int, T}>  $spans  filled with the spans of the line
     * @return int the state the next line starts in
     */
    public static function line(?Language $language, string $text, int $state, ?array &$spans = null): int
    {
        $lexer = new self($text, strlen($text));
        if ($language === null) {
            $state = 0;
        } elseif ($language->is(Language::FORMATTED_TEXT)) {
            $state = $lexer->markdown($state);
        } elseif ($language->is(Language::MARKUP)) {
            $state = $lexer->markup($language, $state, 0);
        } elseif ($language->is(Language::STYLE_SHEET)) {
            $state = $lexer->css($language, $state, 0, $lexer->length);
        } else {
            $state = $lexer->code($language, $state, 0, $lexer->length);
        }
        $spans = $lexer->spans;

        return $state;
    }

    /** By name, alias or file extension; null for none or "None" (syntax.c:1282-1301). */
    public static function find(?string $name): ?Language
    {
        static $aliases = ['cpp' => 'C++', 'csharp' => 'C#', 'js' => 'JavaScript', 'ts' => 'TypeScript', 'shader' => 'GLSL', 'hlsl' => 'GLSL'];
        if ($name === null || $name === '' || strcasecmp($name, 'None') === 0) {
            return null;
        }
        $name = $aliases[strtolower($name)] ?? $name;
        foreach (self::all() as $language) {
            if (strcasecmp($language->name, $name) === 0) {
                return $language;
            }
        }
        foreach (self::all() as $language) {
            if ($language->hasExtension($name)) {
                return $language;
            }
        }

        return null;
    }

    /** The name of the language of a file, from its extension (Rlanguage_for_file, syntax.c:1342-1358). */
    public static function forFile(string $path): ?string
    {
        $base = basename(str_replace('\\', '/', $path));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === strlen($base) - 1) {
            return null;
        }
        $extension = substr($base, $dot + 1);
        foreach (self::all() as $language) {
            if ($language->hasExtension($extension)) {
                return $language->name;
            }
        }

        return null;
    }

    /** Declares a language; one with the same name is replaced (syntax.c:1054-1068). */
    public static function register(Language $language): void
    {
        foreach (self::all() as $i => $known) {
            if (strcasecmp($known->name, $language->name) === 0) {
                self::$languages[$i] = $language;

                return;
            }
        }
        self::$languages[] = $language;
    }

    /** @return array<int, Language> */
    public static function all(): array
    {
        return self::$languages ??= self::builtIn();
    }

    // -------------------------------------------------------------- helpers

    private function emit(int $start, int $end, T $token): void
    {
        if ($end > $start) {
            $this->spans[] = [$start, $end, $token];
        }
    }

    private function starts(int $at, int $to, ?string $word): bool
    {
        $length = $word === null ? 0 : strlen($word);

        return $length > 0 && $at + $length <= $to && substr_compare($this->text, $word, $at, $length) === 0;
    }

    /** First position of $word in [$at, $to), or -1. */
    private function find_(int $at, int $to, string $word, bool $noCase = false): int
    {
        $length = strlen($word);
        if ($length === 0 || $at + $length > $to) {
            return -1;
        }
        $found = $noCase ? stripos(substr($this->text, $at, $to - $at), $word) : strpos(substr($this->text, $at, $to - $at), $word);

        return $found === false ? -1 : $at + $found;
    }

    private static function isSpace(string $c): bool
    {
        return $c === ' ' || $c === "\t";
    }

    private static function isWord(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
    }

    private static function isDigit(string $c): bool
    {
        return ctype_digit($c);
    }

    // ----------------------------------------------------------------- code

    /** End of a quoted text that starts at $at (on the quote): after its closing quote, or $to. */
    private function stringEnd(int $at, int $to, string $quote): int
    {
        $end = $at + 1;
        while ($end < $to && $this->text[$end] !== $quote) {
            if ($this->text[$end] === '\\' && $end + 1 < $to) {
                $end++;
            }
            $end++;
        }

        return $end < $to ? $end + 1 : $to;
    }

    /** End of the k-th multi-line text from $at: after what closes it, or -1 (syntax.c:156-169). */
    private function multilineEnd(Language $language, int $k, int $at, int $to): int
    {
        [, $close, $escapes] = $language->multiline[$k];
        $length = strlen($close);
        if (! $escapes) {
            $end = $this->find_($at, $to, $close);

            return $end < 0 ? -1 : $end + $length;
        }
        while ($at < $to) {
            if ($this->text[$at] === '\\') {
                $at += 2;
            } elseif ($this->starts($at, $to, $close)) {
                return $at + $length;
            } else {
                $at++;
            }
        }

        return -1;
    }

    /** Raven rvn_lex_code (syntax.c:171-358). */
    private function code(Language $language, int $state, int $from, int $to): int
    {
        $text = $this->text;
        $hasBlock = ($language->blockCommentStart ?? '') !== '' && ($language->blockCommentEnd ?? '') !== '';
        $nested = $language->is(Language::NESTED_COMMENTS);
        // "--[[" opens a block where "--" would start a line comment: the longer mark is looked for first
        $lineFirst = $language->lineComment !== null && (! $hasBlock || strlen($language->lineComment) > strlen($language->blockCommentStart));
        $open = $hasBlock ? strlen($language->blockCommentStart) : 0;
        $close = $hasBlock ? strlen($language->blockCommentEnd) : 0;
        $i = $from;
        $first = true;

        while ($i < $to) {
            $c = $text[$i];

            if ($state >= 1 && $state < self::CODE_MULTILINE) {
                $start = $i;
                while ($i < $to && $state >= 1) {
                    if ($this->starts($i, $to, $language->blockCommentEnd)) {
                        $i += $close;
                        $state--;
                    } elseif ($nested && $state < self::CODE_MULTILINE - 1 && $this->starts($i, $to, $language->blockCommentStart)) {
                        $i += $open;
                        $state++;
                    } else {
                        $i++;
                    }
                }
                $this->emit($start, $i, T::Comment);

                continue;
            }
            if ($state >= self::CODE_MULTILINE) {
                $k = $state - self::CODE_MULTILINE;
                $end = $k < count($language->multiline) ? $this->multilineEnd($language, $k, $i, $to) : $i;
                if ($end < 0) {
                    $this->emit($i, $to, T::String);

                    return $state;
                }
                $this->emit($i, $end, T::String);
                $i = $end;
                $state = 0;

                continue;
            }
            if (self::isSpace($c)) {
                $i++;

                continue;
            }
            if ($lineFirst && $this->starts($i, $to, $language->lineComment)) {
                $this->emit($i, $to, T::Comment);

                return $state;
            }
            if ($hasBlock && $this->starts($i, $to, $language->blockCommentStart)) {
                $start = $i;
                $state = 1;
                $i += $open;
                while ($i < $to && $state >= 1) {
                    if ($this->starts($i, $to, $language->blockCommentEnd)) {
                        $i += $close;
                        $state--;
                    } elseif ($nested && $state < self::CODE_MULTILINE - 1 && $this->starts($i, $to, $language->blockCommentStart)) {
                        $i += $open;
                        $state++;
                    } else {
                        $i++;
                    }
                }
                $this->emit($start, $i, T::Comment);
                $first = false;

                continue;
            }
            if (! $lineFirst && $language->lineComment !== null && $this->starts($i, $to, $language->lineComment)) {
                $this->emit($i, $to, T::Comment);

                return $state;
            }
            if ($first && $language->directive !== null && $this->starts($i, $to, $language->directive)) {
                // a line for the preprocessor: #include <file>, #pragma once
                $end = $i + strlen($language->directive);
                while ($end < $to && self::isSpace($text[$end])) {
                    $end++;
                }
                while ($end < $to && self::isWord($text[$end])) {
                    $end++;
                }
                $this->emit($i, $end, T::Directive);
                while ($end < $to && self::isSpace($text[$end])) {
                    $end++;
                }
                if ($end < $to && $text[$end] === '<') {
                    $angle = $end;
                    while ($angle < $to && $text[$angle] !== '>') {
                        $angle++;
                    }
                    if ($angle < $to) {
                        $this->emit($end, $angle + 1, T::String);
                        $end = $angle + 1;
                    }
                }
                $i = $end;
                $first = false;

                continue;
            }
            $first = false;
            if ($language->annotation !== null && $this->starts($i, $to, $language->annotation)) {
                $end = $i + strlen($language->annotation);
                if (str_ends_with($language->annotation, '[')) {
                    // #[derive(Debug)]: to the bracket that closes it
                    $depth = 1;
                    while ($end < $to && $depth > 0) {
                        if ($text[$end] === '[') {
                            $depth++;
                        } elseif ($text[$end] === ']') {
                            $depth--;
                        }
                        $end++;
                    }
                    $this->emit($i, $end, T::Directive);
                    $i = $end;

                    continue;
                }
                if ($end < $to && self::isWord($text[$end]) && ! self::isDigit($text[$end])) {
                    // @Override, @app.route
                    while ($end < $to && (self::isWord($text[$end]) || $text[$end] === '.')) {
                        $end++;
                    }
                    $this->emit($i, $end, T::Directive);
                    $i = $end;

                    continue;
                }
            }
            foreach ($language->multiline as $k => [$opens]) {
                if ($this->starts($i, $to, $opens)) {
                    $end = $this->multilineEnd($language, $k, $i + strlen($opens), $to);
                    if ($end < 0) {
                        $this->emit($i, $to, T::String);

                        return self::CODE_MULTILINE + $k;
                    }
                    $this->emit($i, $end, T::String);
                    $i = $end;

                    continue 2;
                }
            }
            if ($c !== "\0" && str_contains($language->stringQuotes, $c)) {
                if ($c === "'" && $language->is(Language::CHAR_LITERALS)) {
                    // one character between the quotes, or it is not a text: a lifetime, an apostrophe
                    $end = $i + 1;
                    if ($end < $to && $text[$end] === '\\') {
                        $end += 2;
                        while ($end < $to && $end - $i < 12 && $text[$end] !== "'") {
                            $end++;
                        }
                    } elseif ($end < $to) {
                        $end = self::utf8Next($text, $to, $end);
                    }
                    if ($end < $to && $text[$end] === "'" && $end > $i + 1) {
                        $this->emit($i, $end + 1, T::String);
                        $i = $end + 1;
                    } else {
                        $i++;
                    }

                    continue;
                }
                $end = $this->stringEnd($i, $to, $c);
                $this->emit($i, $end, T::String);
                $i = $end;

                continue;
            }
            if (self::isDigit($c) || ($c === '.' && $i + 1 < $to && self::isDigit($text[$i + 1]))) {
                $hex = $text[$i] === '0' && $i + 1 < $to && ($text[$i + 1] === 'x' || $text[$i + 1] === 'X');
                $end = $i + 1;
                while ($end < $to && (self::isWord($text[$end]) || $text[$end] === '.'
                    || (($text[$end] === '+' || $text[$end] === '-') && ($text[$end - 1] === 'e' || $text[$end - 1] === 'E') && ! $hex))) {
                    $end++;
                }
                $this->emit($i, $end, T::Number);
                $i = $end;

                continue;
            }
            if (self::isWord($c)) {
                $end = $i;
                while ($end < $to && self::isWord($text[$end])) {
                    $end++;
                }
                $word = substr($text, $i, $end - $i);
                if ($language->hasWord(0, $word)) {
                    $this->emit($i, $end, T::Keyword);
                } elseif ($language->hasWord(1, $word)) {
                    $this->emit($i, $end, T::Type);
                } elseif ($language->hasWord(2, $word)) {
                    $this->emit($i, $end, T::Number);
                } else {
                    // a name followed by a parenthesis is the name of a function (println! in Rust)
                    $after = $end;
                    if ($after < $to && $text[$after] === '!') {
                        $after++;
                    }
                    while ($after < $to && self::isSpace($text[$after])) {
                        $after++;
                    }
                    if ($after < $to && $text[$after] === '(') {
                        $this->emit($i, $end, T::Function);
                    }
                }
                $i = $end;

                continue;
            }
            $i++;
        }

        return $state;
    }

    private static function utf8Next(string $text, int $to, int $at): int
    {
        $at++;
        while ($at < $to && (ord($text[$at]) & 0xC0) === 0x80) {
            $at++;
        }

        return $at;
    }

    // ------------------------------------------------------------------ css

    private static function isCssName(string $c): bool
    {
        return self::isWord($c) || $c === '-';
    }

    private function cssName(int $end, int $to): int
    {
        while ($end < $to && self::isCssName($this->text[$end])) {
            $end++;
        }

        return $end;
    }

    /** Raven rvn_lex_css (syntax.c:372-476). State: bits 0..2 open braces, bit 3 inside a comment. */
    private function css(Language $language, int $state, int $from, int $to): int
    {
        $text = $this->text;
        $depth = $state & 7;
        $i = $from;
        $inValue = false;

        if ($state & self::CSS_COMMENT) {
            $end = $this->find_($i, $to, '*/');
            if ($end < 0) {
                $this->emit($i, $to, T::Comment);

                return $state;
            }
            $this->emit($i, $end + 2, T::Comment);
            $i = $end + 2;
        }
        while ($i < $to) {
            $c = $text[$i];
            // inside braces, what comes before a ':' is a property, unless a '{' comes first (a nested rule)
            $selector = $depth === 0;
            if (! $selector && ! $inValue) {
                $look = $i;
                while ($look < $to && $text[$look] !== ';' && $text[$look] !== '{' && $text[$look] !== '}') {
                    $look++;
                }
                if ($look < $to && $text[$look] === '{') {
                    $selector = true;
                }
            }
            if (self::isSpace($c)) {
                $i++;
            } elseif ($this->starts($i, $to, '/*')) {
                $end = $this->find_($i + 2, $to, '*/');
                if ($end < 0) {
                    $this->emit($i, $to, T::Comment);

                    return $depth | self::CSS_COMMENT;
                }
                $this->emit($i, $end + 2, T::Comment);
                $i = $end + 2;
            } elseif ($c === '"' || $c === "'") {
                $end = $this->stringEnd($i, $to, $c);
                $this->emit($i, $end, T::String);
                $i = $end;
            } elseif ($c === '{') {
                if ($depth < 7) {
                    $depth++;
                }
                $inValue = false;
                $i++;
            } elseif ($c === '}') {
                if ($depth > 0) {
                    $depth--;
                }
                $inValue = false;
                $i++;
            } elseif ($c === ';') {
                $inValue = false;
                $i++;
            } elseif ($c === '@') {
                $end = $this->cssName($i + 1, $to);
                $this->emit($i, $end, T::Directive);
                $i = $end;
            } elseif ($selector) {
                if ($c === '.' || $c === '#') {
                    $end = $this->cssName($i + 1, $to);
                    $this->emit($i, $end, T::Attribute);
                    $i = $end;
                } elseif ($c === ':') {
                    $end = $i + 1;
                    while ($end < $to && (self::isCssName($text[$end]) || $text[$end] === ':')) {
                        $end++;
                    }
                    $this->emit($i, $end, T::Keyword);
                    $i = $end;
                } elseif (self::isCssName($c)) {
                    $end = $this->cssName($i, $to);
                    $this->emit($i, $end, T::Tag);
                    $i = $end;
                } else {
                    $i++;
                }
            } elseif (! $inValue) {
                if (self::isCssName($c)) {
                    $end = $this->cssName($i, $to);
                    $this->emit($i, $end, T::Attribute);
                    $i = $end;
                } else {
                    if ($c === ':') {
                        $inValue = true;
                    }
                    $i++;
                }
            } elseif ($c === '#' || self::isDigit($c) || (($c === '.' || $c === '-') && $i + 1 < $to && self::isDigit($text[$i + 1]))) {
                // a colour, a number with its unit
                $end = $i + 1;
                while ($end < $to && (self::isWord($text[$end]) || $text[$end] === '.' || $text[$end] === '%')) {
                    $end++;
                }
                $this->emit($i, $end, T::Number);
                $i = $end;
            } elseif ($c === '!') {
                $end = $this->cssName($i + 1, $to);
                $this->emit($i, $end, T::Keyword);
                $i = $end;
            } elseif (self::isCssName($c)) {
                $end = $this->cssName($i, $to);
                if ($end < $to && $text[$end] === '(') {
                    $this->emit($i, $end, T::Function);
                } elseif ($language->hasWord(0, substr($text, $i, $end - $i))) {
                    $this->emit($i, $end, T::Keyword);
                }
                $i = $end;
            } else {
                $i++;
            }
        }

        return $depth;
    }

    // --------------------------------------------------------------- markup

    private static function isXmlName(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '-' || $c === '.' || $c === ':' || ord($c) >= 0x80);
    }

    /**
     * Raven rvn_lex_markup (syntax.c:504-663), from $from to the end of the line. State: bits 0..2
     * where in the markup, bits 3..4 the tag being read opens a script (1) or styles (2); or
     * 0x40 | the code lexer's state inside <script>, or 0x80 | the style lexer's inside <style>.
     */
    private function markup(Language $language, int $fullState, int $from): int
    {
        $text = $this->text;
        $length = $this->length;
        $i = $from;
        $embeds = $language->is(Language::EMBEDS);
        $state = $fullState & 7;
        $pending = ($fullState >> 3) & 3;

        // inside <script> or <style>: another language, until the closing tag
        if ($fullState & (self::XML_SCRIPT | self::XML_STYLE)) {
            $script = ($fullState & self::XML_SCRIPT) !== 0;
            $inner = self::find($script ? ($language->scriptLanguage ?? 'javascript') : 'css');
            $close = $this->find_($from, $length, $script ? '</script' : '</style', true);
            $sub = $fullState & 0x3F;
            $to = $close < 0 ? $length : $close;
            // (a code lexer for the code: a language of markup there would not know what to do with it)
            if ($inner !== null && $script && $inner->is(Language::MARKUP | Language::STYLE_SHEET | Language::FORMATTED_TEXT)) {
                $inner = null;
            }
            if ($inner !== null) {
                $sub = $script ? $this->code($inner, $sub, $from, $to) : $this->css($inner, $sub, $from, $to);
            }
            if ($close < 0) {
                return ($script ? self::XML_SCRIPT : self::XML_STYLE) | ($sub & 0x3F);
            }
            $i = $close;
            $state = self::XML_CONTENT;
            $pending = 0;
        }

        while ($i < $length) {
            switch ($state) {
                case self::XML_COMMENT:
                    $end = $this->find_($i, $length, '-->');
                    if ($end < 0) {
                        $this->emit($i, $length, T::Comment);

                        return $state;
                    }
                    $this->emit($i, $end + 3, T::Comment);
                    $i = $end + 3;
                    $state = self::XML_CONTENT;
                    break;
                case self::XML_CDATA:
                    $end = $this->find_($i, $length, ']]>');
                    if ($end < 0) {
                        return $state;
                    }
                    $this->emit($end, $end + 3, T::Punctuation);
                    $i = $end + 3;
                    $state = self::XML_CONTENT;
                    break;
                case self::XML_DECLARATION:
                    $end = $this->find_($i, $length, '>');
                    if ($end < 0) {
                        $this->emit($i, $length, T::Directive);

                        return $state;
                    }
                    $this->emit($i, $end + 1, T::Directive);
                    $i = $end + 1;
                    $state = self::XML_CONTENT;
                    break;
                case self::XML_DOUBLE_QUOTED:
                case self::XML_SINGLE_QUOTED:
                    $end = $this->find_($i, $length, $state === self::XML_DOUBLE_QUOTED ? '"' : "'");
                    if ($end < 0) {
                        $this->emit($i, $length, T::String);

                        return $state | ($pending << 3);
                    }
                    $this->emit($i, $end + 1, T::String);
                    $i = $end + 1;
                    $state = self::XML_TAG;
                    break;
                case self::XML_TAG:
                    $c = $text[$i];
                    if (self::isSpace($c)) {
                        $i++;
                    } elseif ($c === '>') {
                        $this->emit($i, $i + 1, T::Punctuation);
                        $i++;
                        if ($pending) {
                            // the rest of the line, and the lines after it, are in the other language
                            return $this->markup($language, $pending === 1 ? self::XML_SCRIPT : self::XML_STYLE, $i);
                        }
                        $state = self::XML_CONTENT;
                    } elseif ($this->starts($i, $length, '/>')) {
                        $this->emit($i, $i + 2, T::Punctuation);
                        $i += 2;
                        $state = self::XML_CONTENT;
                        $pending = 0;
                    } elseif ($c === '"' || $c === "'") {
                        $end = $i + 1;
                        while ($end < $length && $text[$end] !== $c) {
                            $end++;
                        }
                        if ($end < $length) {
                            $this->emit($i, $end + 1, T::String);
                            $i = $end + 1;
                        } else {
                            $this->emit($i, $length, T::String);

                            return ($c === '"' ? self::XML_DOUBLE_QUOTED : self::XML_SINGLE_QUOTED) | ($pending << 3);
                        }
                    } elseif ($c === '=') {
                        $this->emit($i, $i + 1, T::Punctuation);
                        $i++;
                    } elseif (self::isXmlName($c)) {
                        $end = $i;
                        while ($end < $length && self::isXmlName($text[$end])) {
                            $end++;
                        }
                        $this->emit($i, $end, T::Attribute);
                        $i = $end;
                    } else {
                        $i++;
                    }
                    break;
                default:
                    if ($this->starts($i, $length, '<!--')) {
                        $state = self::XML_COMMENT;
                    } elseif ($this->starts($i, $length, '<![CDATA[')) {
                        $this->emit($i, $i + 9, T::Punctuation);
                        $i += 9;
                        $state = self::XML_CDATA;
                    } elseif ($this->starts($i, $length, '<?') || $this->starts($i, $length, '<!')) {
                        $state = self::XML_DECLARATION;
                    } elseif ($text[$i] === '<') {
                        $name = $i + 1;
                        $closing = $name < $length && $text[$name] === '/';
                        if ($closing) {
                            $name++;
                        }
                        $this->emit($i, $name, T::Punctuation);
                        $end = $name;
                        while ($end < $length && self::isXmlName($text[$end])) {
                            $end++;
                        }
                        $this->emit($name, $end, T::Tag);
                        $pending = 0;
                        if ($embeds && ! $closing) {
                            $tag = strtolower(substr($text, $name, $end - $name));
                            $pending = $tag === 'script' ? 1 : ($tag === 'style' ? 2 : 0);
                        }
                        $i = $end;
                        $state = self::XML_TAG;
                    } elseif ($text[$i] === '&') {
                        // an entity: &amp; &#x20AC;
                        $end = $i + 1;
                        while ($end < $length && $end - $i < 12 && $text[$end] !== ';' && ! self::isSpace($text[$end]) && $text[$end] !== '<') {
                            $end++;
                        }
                        if ($end < $length && $text[$end] === ';') {
                            $this->emit($i, $end + 1, T::Number);
                            $i = $end + 1;
                        } else {
                            $i++;
                        }
                    } else {
                        $i++;
                    }
                    break;
            }
        }

        return $state | ($state === self::XML_TAG ? $pending << 3 : 0);
    }

    // ------------------------------------------------------------- markdown

    /** Where the mark that closes what opened before $from is, or -1 (syntax.c:680-690). */
    private function mdCloser(int $from, string $mark): int
    {
        $markLength = strlen($mark);
        for ($i = $from; $i + $markLength <= $this->length; $i++) {
            if ($this->text[$i] === '\\' && $i + 1 < $this->length) {
                $i++;

                continue;
            }
            if (substr_compare($this->text, $mark, $i, $markLength) === 0 && $i > $from && $this->text[$i - 1] !== ' ') {
                return $i;
            }
        }

        return -1;
    }

    /** Raven rvn_lex_markdown (syntax.c:692-867): Markdown as it is written. State: in a fence, and its mark. */
    private function markdown(int $state): int
    {
        $text = $this->text;
        $length = $this->length;
        $i = 0;
        while ($i < $length && self::isSpace($text[$i])) {
            $i++;
        }

        if ($state & self::MD_IN_CODE) {
            $mark = ($state & self::MD_TILDE) ? '~' : '`';
            $run = strspn($text, $mark, $i);
            if ($run >= 3 && $i + $run === $length) {
                $this->emit($i, $length, T::Punctuation);

                return 0;
            }
            $this->emit(0, $length, T::String);

            return $state;
        }

        // quotes
        while ($i < $length && $text[$i] === '>') {
            $this->emit($i, $i + 1, T::Punctuation);
            $i++;
            while ($i < $length && $text[$i] === ' ') {
                $i++;
            }
        }
        if ($i >= $length) {
            return 0;
        }

        // a fence
        if ($text[$i] === '`' || $text[$i] === '~') {
            $run = strspn($text, $text[$i], $i);
            if ($run >= 3 && ($text[$i] === '~' || ! str_contains(substr($text, $i + $run), '`'))) {
                $this->emit($i, $i + $run, T::Punctuation);
                $this->emit($i + $run, $length, T::Keyword);

                return self::MD_IN_CODE | ($text[$i] === '~' ? self::MD_TILDE : 0);
            }
        }
        // a title
        if ($text[$i] === '#') {
            $run = strspn($text, '#', $i);
            if ($run <= 6 && ($i + $run === $length || $text[$i + $run] === ' ')) {
                $this->emit($i, $length, T::Heading);

                return 0;
            }
        }
        // a rule, or the line under a title
        $marks = 0;
        $other = 0;
        for ($k = $i; $k < $length; $k++) {
            if (str_contains('-*_=', $text[$k])) {
                $marks += $text[$k] === $text[$i] ? 1 : 0;
            } elseif ($text[$k] !== ' ') {
                $other++;
            }
        }
        if (! $other && $marks >= 3) {
            $this->emit($i, $length, T::Punctuation);

            return 0;
        }
        // the mark of a list item, and the box of a task
        if (($text[$i] === '-' || $text[$i] === '*' || $text[$i] === '+') && $i + 1 < $length && $text[$i + 1] === ' ') {
            $this->emit($i, $i + 1, T::Keyword);
            $i += 2;
            if ($i + 2 < $length && $text[$i] === '[' && $text[$i + 2] === ']' && str_contains(' xX', $text[$i + 1])) {
                $this->emit($i, $i + 3, T::Keyword);
                $i += 3;
            }
        } elseif (self::isDigit($text[$i])) {
            $k = $i + strspn($text, '0123456789', $i);
            if ($k + 1 < $length && ($text[$k] === '.' || $text[$k] === ')') && $text[$k + 1] === ' ') {
                $this->emit($i, $k + 1, T::Keyword);
                $i = $k + 2;
            }
        }

        while ($i < $length) {
            $c = $text[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $this->emit($i, $i + 1, T::Punctuation);
                $i += 2;

                continue;
            }
            if ($c === '`') {
                $run = strspn($text, '`', $i);
                $end = $i + $run;
                while ($end < $length) {
                    $close = strspn($text, '`', $end);
                    if ($close === $run) {
                        break;
                    }
                    $end += $close ?: 1;
                }
                if ($end < $length) {
                    $this->emit($i, $end + $run, T::String);
                    $i = $end + $run;

                    continue;
                }
                $i += $run;

                continue;
            }
            if (($c === '*' || $c === '_') && $i + 1 < $length) {
                $strong = $text[$i + 1] === $c;
                $markLength = $strong ? 2 : 1;
                $insideWord = $c === '_' && $i > 0 && ctype_alnum($text[$i - 1]);
                if (! $insideWord && $i + $markLength < $length && $text[$i + $markLength] !== ' ') {
                    $end = $this->mdCloser($i + $markLength, str_repeat($c, $markLength));
                    if ($end >= 0) {
                        // ("***both***": the third star belongs to what is inside)
                        $stop = $end + $markLength;
                        while ($stop < $length && $text[$stop] === $c && $stop - $end < 3) {
                            $stop++;
                        }
                        $this->emit($i, $stop, $strong ? T::Strong : T::Emphasis);
                        $i = $stop;

                        continue;
                    }
                }
                $i += $markLength;

                continue;
            }
            if ($c === '~' && $i + 1 < $length && $text[$i + 1] === '~') {
                $end = $this->mdCloser($i + 2, '~~');
                if ($end >= 0) {
                    $this->emit($i, $end + 2, T::Comment);
                    $i = $end + 2;

                    continue;
                }
                $i += 2;

                continue;
            }
            if ($c === '[' || ($c === '!' && $i + 1 < $length && $text[$i + 1] === '[')) {
                $close = $i + 1;
                $nest = 0;
                while ($close < $length) {
                    if ($text[$close] === '[') {
                        $nest++;
                    } elseif ($text[$close] === ']' && --$nest <= 0) {
                        break;
                    }
                    $close++;
                }
                if ($close + 1 < $length && $text[$close + 1] === '(') {
                    $end = strpos($text, ')', $close + 2);
                    if ($end !== false) {
                        $this->emit($i, $end + 1, T::Link);
                        $i = $end + 1;

                        continue;
                    }
                }
            }
            if ($c === '<') {
                if ($this->starts($i, $length, '<!--')) {
                    $end = $this->find_($i + 4, $length, '-->');
                    $end = $end < 0 ? $length : $end + 3;
                    $this->emit($i, $end, T::Comment);
                    $i = $end;

                    continue;
                }
                $end = $i + 1;
                while ($end < $length && $text[$end] !== '>' && $text[$end] !== ' ') {
                    $end++;
                }
                if ($end < $length && $text[$end] === '>' && $end > $i + 1) {
                    $inside = substr($text, $i, $end - $i);
                    $this->emit($i, $end + 1, str_contains($inside, ':') || str_contains($inside, '@') ? T::Link : T::Tag);
                    $i = $end + 1;

                    continue;
                }
            }
            if ($c === 'h' && ($this->starts($i, $length, 'http://') || $this->starts($i, $length, 'https://'))) {
                $end = $i + strcspn($text, " \t)>", $i);
                $this->emit($i, $end, T::Link);
                $i = $end;

                continue;
            }
            if ($c === '|') {
                $this->emit($i, $i + 1, T::Punctuation);
            }
            $i++;
        }

        return 0;
    }

    // ------------------------------------------------------- built-in languages

    /** @return list<Language> Raven's built-in languages (syntax.c:1081-1258), in its order. */
    private static function builtIn(): array
    {
        $cKeywords = 'auto break case const continue default do else enum extern for goto if inline register restrict return sizeof '
            .'static struct switch typedef union volatile while _Alignas _Alignof _Atomic _Generic _Noreturn _Static_assert _Thread_local';
        $cTypes = 'void char short int long float double signed unsigned bool _Bool size_t ssize_t ptrdiff_t intptr_t uintptr_t '
            .'int8_t int16_t int32_t int64_t uint8_t uint16_t uint32_t uint64_t wchar_t FILE va_list';
        $javaScript = 'async await break case catch class const continue debugger default delete do else export extends finally for from '
            .'function get if import in instanceof let new of return set static super switch this throw try typeof var void while with yield';
        $javaScriptTypes = 'Array Boolean Date Error Function JSON Map Math Number Object Promise Proxy Reflect RegExp Set String Symbol WeakMap '
            .'WeakSet BigInt console document window globalThis';
        $c = ['lineComment' => '//', 'blockCommentStart' => '/*', 'blockCommentEnd' => '*/'];

        return [
            new Language('C', 'c;h', $cKeywords, $cTypes, 'NULL true false', ...$c, stringQuotes: "\"'",
                directive: '#', flags: Language::CHAR_LITERALS),
            new Language('C++', 'cpp;cc;cxx;hpp;hh;hxx;inl;ixx',
                $cKeywords.' alignas alignof and and_eq asm bitand bitor catch class co_await co_return co_yield compl concept '
                .'const_cast consteval constexpr constinit decltype delete dynamic_cast explicit export final friend mutable namespace new '
                .'noexcept not not_eq operator or or_eq override private protected public reinterpret_cast requires static_assert static_cast '
                .'template this thread_local throw try typeid typename using virtual xor xor_eq',
                $cTypes.' char8_t char16_t char32_t string string_view vector array map unordered_map set unordered_set optional '
                .'variant tuple pair unique_ptr shared_ptr weak_ptr function span',
                'NULL nullptr true false', ...$c, stringQuotes: "\"'", multilineStrings: 'R"(...)"', directive: '#',
                flags: Language::CHAR_LITERALS),
            // (Raven builds GLSL from the C++ description, so it keeps C++'s comments, texts and directive)
            new Language('GLSL', 'glsl;vert;frag;geom;comp;tesc;tese;hlsl',
                'attribute break case const continue default discard do else flat for if in inout invariant layout lowp mediump highp '
                .'noperspective out precision return smooth struct subroutine switch uniform varying while buffer shared centroid sample patch',
                'void bool int uint float double vec2 vec3 vec4 ivec2 ivec3 ivec4 uvec2 uvec3 uvec4 bvec2 bvec3 bvec4 dvec2 dvec3 dvec4 '
                .'mat2 mat3 mat4 mat2x3 mat2x4 mat3x2 mat3x4 mat4x2 mat4x3 sampler1D sampler2D sampler3D samplerCube sampler2DArray '
                .'sampler2DShadow samplerCubeShadow image2D float2 float3 float4 float4x4 Texture2D SamplerState',
                'true false gl_Position gl_FragCoord gl_FragDepth gl_VertexIndex gl_InstanceIndex gl_PointSize',
                ...$c, stringQuotes: "\"'", multilineStrings: 'R"(...)"', directive: '#', flags: Language::CHAR_LITERALS),
            new Language('C#', 'cs',
                'abstract as async await base break case catch checked class const continue default delegate do else enum event '
                .'explicit extern finally fixed for foreach get goto if implicit in init interface internal is lock namespace new operator out '
                .'override params partial private protected public readonly record ref required return sealed set sizeof stackalloc static '
                .'struct switch this throw try typeof unchecked unsafe using virtual volatile when where while with yield global nameof',
                'bool byte char decimal double dynamic float int long nint nuint object sbyte short string uint ulong ushort var void '
                .'String Object Int32 Int64 Double Boolean List Dictionary IEnumerable Task Action Func Span Guid DateTime',
                'true false null', ...$c, stringQuotes: "\"'", multilineStrings: '""" @"..."', directive: '#',
                flags: Language::CHAR_LITERALS),
            new Language('Java', 'java',
                'abstract assert break case catch class const continue default do else enum exports extends final finally for goto '
                .'if implements import instanceof interface module native new non-sealed package permits private protected public record '
                .'requires return sealed static strictfp super switch synchronized this throw throws transient try volatile while yield',
                'boolean byte char double float int long short var void String Object Integer Long Double Boolean Character List '
                .'ArrayList Map HashMap Set HashSet Optional Stream',
                'true false null', ...$c, stringQuotes: "\"'", multilineStrings: '"""', annotation: '@',
                flags: Language::CHAR_LITERALS),
            new Language('JavaScript', 'js;mjs;cjs;jsx', $javaScript, $javaScriptTypes, 'true false null undefined NaN Infinity',
                ...$c, stringQuotes: "\"'", multilineStrings: '`'),
            new Language('TypeScript', 'ts;tsx;mts',
                'abstract as asserts async await break case catch class const continue debugger declare default delete do else enum '
                .'export extends finally for from function get if implements import in infer instanceof interface is keyof let module namespace '
                .'new of override private protected public readonly return satisfies set static super switch this throw try type typeof var '
                .'void while with yield',
                'any bigint boolean never number object string symbol unknown Array Date Error Map Promise Record Partial Readonly '
                .'Set Pick Omit console',
                'true false null undefined NaN Infinity', ...$c, stringQuotes: "\"'", multilineStrings: '`'),
            new Language('Python', 'py;pyw;pyi',
                'and as assert async await break case class continue def del elif else except finally for from global if import in is '
                .'lambda match nonlocal not or pass raise return try while with yield',
                'bool bytearray bytes complex dict float frozenset int list object set str tuple type range print len open super '
                .'isinstance enumerate zip map filter sorted min max sum abs any all self cls',
                'True False None NotImplemented Ellipsis __name__ __main__', lineComment: '#', stringQuotes: "\"'",
                multilineStrings: "\"\"\" '''", annotation: '@', flags: Language::INDENT_ON_COLON),
            new Language('Rust', 'rs',
                'as async await break const continue crate dyn else enum extern fn for if impl in let loop match mod move mut pub ref '
                .'return self Self static struct super trait type unsafe use where while macro_rules union',
                'bool char f32 f64 i8 i16 i32 i64 i128 isize str u8 u16 u32 u64 u128 usize String Vec Option Result Box Rc Arc Cell '
                .'RefCell HashMap HashSet BTreeMap Path PathBuf',
                'true false Some None Ok Err', ...$c, stringQuotes: "\"'",
                // (an ordinary text may span lines too)
                multilineStrings: 'r#"..."# r"..." "', annotation: '#[',
                flags: Language::CHAR_LITERALS | Language::NESTED_COMMENTS),
            new Language('JSON', 'json;jsonc', '', '', 'true false null', ...$c, stringQuotes: '"'),
            new Language('CSS', 'css;scss;less',
                'inherit initial unset revert auto none normal bold italic solid dashed dotted block inline flex grid absolute '
                .'relative fixed sticky hidden visible center left right top bottom transparent currentColor',
                blockCommentStart: '/*', blockCommentEnd: '*/', stringQuotes: "\"'", flags: Language::STYLE_SHEET),
            new Language('XML', 'xml;xaml;svg;ui;xsd;xsl;plist;csproj;vcxproj', blockCommentStart: '<!--', blockCommentEnd: '-->',
                flags: Language::MARKUP),
            new Language('HTML', 'html;htm;xhtml;vue', blockCommentStart: '<!--', blockCommentEnd: '-->',
                flags: Language::MARKUP | Language::EMBEDS | Language::IGNORE_CASE),
            new Language('Markdown', 'md;markdown;mdown;mkd', blockCommentStart: '<!--', blockCommentEnd: '-->',
                flags: Language::FORMATTED_TEXT),
        ];
    }
}
