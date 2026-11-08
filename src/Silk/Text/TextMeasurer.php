<?php

namespace Silk\Text;

use IntlBreakIterator;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\FontSpec;
use Surface\Contracts\Fonts\FontDriver;
use Surface\Contracts\Fonts\ShapedRun;
use Surface\Contracts\Fonts\TrueTypeFont;
use Surface\Fonts\ClassicFont;

/**
 * Logical text sizes and line metrics from runs shaped at scale 1 through Surface's FontDriver (the painter
 * shapes at its own scale through the same driver, so both share its caches). Without a driver (the font exts
 * missing) it measures the classic GFXFont the painter then draws.
 */
class TextMeasurer
{
    /** fontconfig's weight names by CSS weight. */
    private const array WEIGHTS = [100 => 'thin', 200 => 'extralight', 300 => 'light', 400 => 'regular', 500 => 'medium', 600 => 'demibold', 700 => 'bold', 800 => 'extrabold', 900 => 'black'];

    private static ?self $default = null;

    private ?ClassicFont $classic = null;

    /** @var array<string, TrueTypeFont> one per FontSpec */
    private array $fonts = [];

    public function __construct(private readonly ?FontDriver $driver) {}

    /** The container's TrueType driver, else the GFXFont fallback. */
    public static function default(): self
    {
        return self::$default ??= new self(self::containerDriver());
    }

    private static function containerDriver(): ?FontDriver
    {
        try {
            return function_exists('app') ? app('truetype')->driver() : null;
        } catch (\Throwable) {
            return null; // the font exts are missing (FontException), or no app is booted (unit tests, scripts)
        }
    }

    /**
     * The fontconfig pattern for a look's font: 'Inter:weight=demibold:slant=italic'. A CSS weight between two
     * stops takes the nearer one; a tie goes to the heavier one.
     */
    public static function pattern(FontSpec $font): string
    {
        $weight = self::WEIGHTS[(int) round(min(900, max(100, $font->weight)) / 100) * 100];

        return "{$font->family}:weight={$weight}".($font->italic ? ':slant=italic' : '');
    }

    public function driver(): ?FontDriver
    {
        return $this->driver;
    }

    /** The TrueType font for a look's font, at its logical size; null without a driver. */
    public function font(FontSpec $font): ?TrueTypeFont
    {
        if ($this->driver === null) {
            return null;
        }
        $key = "{$font->family}\0{$font->size}\0{$font->weight}\0".($font->italic ? 1 : 0);

        return $this->fonts[$key] ??= new TrueTypeFont(self::pattern($font), $font->size, $this->driver);
    }

    /** Width of the run and one line's height, in logical units. */
    public function measure(string $text, FontSpec $font): Size
    {
        $run = $this->run($text, $font);
        if ($run === null) {
            $classic = $this->classic();

            return new Size(mb_strlen($text) * ($classic->glyph(ord('a'))?->x_advance ?? 6), $classic->lineHeight());
        }

        return new Size($run->advance, $run->lineHeight);
    }

    /** The shaped run at scale 1 (logical units); null without a driver. */
    public function run(string $text, FontSpec $font): ?ShapedRun
    {
        $true = $this->font($font);

        return $true?->shape($true->size, $text);
    }

    /** @return array{ascent: float, descent: float, lineHeight: float} logical, descent positive */
    public function metrics(FontSpec $font): array
    {
        $run = $this->run('', $font);
        if ($run === null) {
            $h = (float) $this->classic()->lineHeight();

            return ['ascent' => $h - 1, 'descent' => 1.0, 'lineHeight' => $h];
        }

        return ['ascent' => $run->ascent, 'descent' => $run->descent, 'lineHeight' => $run->lineHeight];
    }

    /**
     * Lines no wider than $maxWidth, broken at line-break opportunities (UAX #14 through intl); a word wider than
     * a line breaks between graphemes. Spaces at a break are dropped. Empty text is no lines.
     *
     * @return list<string>
     */
    public function wrap(string $text, FontSpec $font, float $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (self::pieces($text, IntlBreakIterator::createLineInstance()) as $piece) {
            if ($this->fits($line.$piece, $font, $maxWidth)) {
                $line .= $piece;

                continue;
            }
            if (rtrim($line) !== '') {
                $lines[] = rtrim($line);
            }
            $line = '';
            // a piece that alone is too wide breaks between graphemes
            foreach (self::pieces($piece, IntlBreakIterator::createCharacterInstance()) as $grapheme) {
                if ($line !== '' && ! $this->fits($line.$grapheme, $font, $maxWidth)) {
                    $lines[] = rtrim($line);
                    $line = '';
                }
                $line .= $grapheme;
            }
        }
        if (rtrim($line) !== '') {
            $lines[] = rtrim($line);
        }

        return $lines;
    }

    /** The character boundary of $text nearest $x (Raven rvn_text_offset_at). */
    public function offsetAt(string $text, FontSpec $font, float $x): int
    {
        $best = 0;
        $bestDistance = abs($x);
        $offset = 0;
        foreach (mb_str_split($text) as $char) {
            $offset += strlen($char);
            $distance = abs($this->measure(substr($text, 0, $offset), $font)->w - $x);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $offset;
            }
        }

        return $best;
    }

    /** Trailing spaces hang past the edge, as in every text layout. */
    private function fits(string $line, FontSpec $font, float $maxWidth): bool
    {
        return $this->measure(rtrim($line), $font)->w <= $maxWidth;
    }

    /** @return list<string> $text cut at the iterator's boundaries */
    private static function pieces(string $text, IntlBreakIterator $it): array
    {
        $it->setText($text);
        $out = [];
        $start = $it->first();
        for ($end = $it->next(); $end !== IntlBreakIterator::DONE; $start = $end, $end = $it->next()) {
            $out[] = substr($text, $start, $end - $start);
        }

        return $out;
    }

    private function classic(): ClassicFont
    {
        return $this->classic ??= new ClassicFont;
    }
}
