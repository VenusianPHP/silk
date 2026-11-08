<?php

namespace Silk\Text\Syntax;

/**
 * A language the lexers read (Raven RLanguageDesc, raven_text_editor.h:45-111; built by
 * syntax.c:1005-1047): its words, how its comments and texts are written, a few traits.
 */
final class Language
{
    public const int MARKUP = 1;

    public const int STYLE_SHEET = 2;

    public const int NESTED_COMMENTS = 4;

    public const int CHAR_LITERALS = 8;

    public const int INDENT_ON_COLON = 16;

    public const int IGNORE_CASE = 32;

    /** Markup that holds a script and styles between its tags (HTML). */
    public const int EMBEDS = 64;

    /** Markdown. */
    public const int FORMATTED_TEXT = 128;

    /** Raven RVN_LANGUAGE_MAX_MULTILINE (syntax.c:23). */
    private const int MAX_MULTILINE = 8;

    public readonly ?string $lineComment;

    public readonly ?string $directive;

    public readonly ?string $annotation;

    public readonly ?string $scriptLanguage;

    /** @var list<array{string, string, bool}> texts that span lines: what opens, what closes, whether a backslash escapes */
    public readonly array $multiline;

    /** @var array<int, array<string, string>> keywords (0), types (1), constants (2) */
    private array $words = [];

    public function __construct(
        public readonly string $name,
        public readonly string $extensions = '',
        string $keywords = '',
        string $types = '',
        string $constants = '',
        ?string $lineComment = null,
        public readonly ?string $blockCommentStart = null,
        public readonly ?string $blockCommentEnd = null,
        public readonly string $stringQuotes = '',
        string $multilineStrings = '',
        ?string $directive = null,
        ?string $annotation = null,
        public readonly int $flags = 0,
        ?string $scriptLanguage = null,
    ) {
        $this->lineComment = $lineComment === '' ? null : $lineComment;
        $this->directive = $directive === '' ? null : $directive;
        $this->annotation = $annotation === '' ? null : $annotation;
        $this->scriptLanguage = $scriptLanguage === '' ? null : $scriptLanguage;
        foreach ([$keywords, $types, $constants] as $list => $text) {
            $this->words[$list] = [];
            foreach (preg_split('/[ \t\n,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $this->words[$list][$this->key($word)] = $word;
            }
        }
        $multiline = [];
        foreach (preg_split('/ +/', $multilineStrings, -1, PREG_SPLIT_NO_EMPTY) as $mark) {
            if (count($multiline) === self::MAX_MULTILINE) {
                break;
            }
            // R"(...)": what opens it, three dots, what closes it; nothing is escaped in those
            $dots = strpos($mark, '...', 1);
            $multiline[] = $dots !== false && $dots + 3 < strlen($mark)
                ? [substr($mark, 0, $dots), substr($mark, $dots + 3), false]
                : [$mark, $mark, true];
        }
        $this->multiline = $multiline;
    }

    public function is(int $flag): bool
    {
        return ($this->flags & $flag) !== 0;
    }

    /** Is $word one of the words of a list (0 keywords, 1 types, 2 constants)? */
    public function hasWord(int $list, string $word): bool
    {
        return isset($this->words[$list][$this->key($word)]);
    }

    /** @return list<string> the words of a list, sorted */
    public function words(int $list): array
    {
        $words = array_values($this->words[$list] ?? []);
        $this->is(self::IGNORE_CASE) ? sort($words, SORT_STRING | SORT_FLAG_CASE) : sort($words, SORT_STRING);

        return $words;
    }

    /** Does "c;h;inl" hold $extension? (syntax.c:1270-1280) */
    public function hasExtension(string $extension): bool
    {
        if ($extension === '') {
            return false;
        }
        foreach (preg_split('/[;, ]+/', $this->extensions, -1, PREG_SPLIT_NO_EMPTY) as $item) {
            if (strcasecmp($item, $extension) === 0) {
                return true;
            }
        }

        return false;
    }

    private function key(string $word): string
    {
        return $this->is(self::IGNORE_CASE) ? strtolower($word) : $word;
    }
}
