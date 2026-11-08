<?php

namespace Silk\Text\Markdown;

use Closure;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Look\FontSpec;
use Silk\Contracts\Look\SyntaxToken;
use Silk\Contracts\Painter\Painter;
use Silk\Painter\Icons;
use Silk\Text\Syntax\Syntax;
use Silk\Text\TextMeasurer;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\NutsAndBolts\Color;

/**
 * Parsed Markdown laid out on a width into runs of text and boxes, painted and pointed at
 * (Raven widgets/markdown.c:1050-1756). Coordinates are relative to the text's top left.
 */
final class MarkdownLayout
{
    public const int COLOR_TEXT = 0;

    public const int COLOR_LINK = 1;

    public const int COLOR_QUOTE = 2;

    public const int BOX_CODE = 0;

    public const int BOX_INLINE_CODE = 1;

    public const int BOX_QUOTE = 2;

    public const int BOX_RULE = 3;

    public const int BOX_BULLET = 4;

    public const int BOX_TASK = 5;

    public const int BOX_TASK_DONE = 6;

    public const int BOX_TABLE_HEADER = 7;

    public const int BOX_TABLE_LINE = 8;

    public const int BOX_IMAGE = 9;

    /** markdown.c:1381 */
    private const array HEADING_SCALE = [1.0, 1.9, 1.52, 1.26, 1.1, 1.0, 0.92];

    /** The shown text, with the numbers of the lists added after what was parsed. */
    public string $text;

    public readonly int $parsedLength;

    /**
     * @var list<array{x: float, y: float, w: float, lineY: float, lineH: float, start: int, length: int, font: FontSpec,
     *   base: float, color: int, token: ?SyntaxToken, flags: int, link: int, clipRight: float}>
     */
    public array $runs = [];

    /** @var list<array{kind: int, x: float, y: float, w: float, h: float, depth: int, image: ?Framebuffer}> */
    public array $boxes = [];

    public readonly Size $size;

    private float $widest = 0.0;

    /** @var array<string, array{base: float, height: float}> */
    private array $fontMetrics = [];

    /**
     * @param  FontSpec  $font  the label's font; its size is the text's size
     * @param  FontSpec  $mono  the monospace family (its size is set here)
     * @param  float  $width  0: on one line
     * @param  (Closure(string): ?Framebuffer)|null  $images  an image by its address
     */
    public function __construct(
        private readonly Markdown $md,
        private readonly TextMeasurer $measurer,
        private readonly FontSpec $font,
        FontSpec $mono,
        float $width,
        private readonly ?Closure $images = null,
    ) {
        $this->text = $md->text;
        $this->parsedLength = strlen($md->text);
        $this->mono = new FontSpec($mono->family, $font->size * 0.94, $mono->weight, $mono->italic);
        $this->size = $this->layout($width);
    }

    private readonly FontSpec $mono;

    // -------------------------------------------------------------- layout

    private function variant(float $size, bool $bold, bool $italic): FontSpec
    {
        return new FontSpec($this->font->family, $size, $bold ? 700 : $this->font->weight, $italic || $this->font->italic);
    }

    /** @return array{base: float, height: float} from the top of a line box to its baseline, and its height */
    private function metrics(FontSpec $f): array
    {
        $key = "{$f->family}\0{$f->size}\0{$f->weight}\0".(int) $f->italic;
        if (! isset($this->fontMetrics[$key])) {
            $m = $this->measurer->metrics($f);
            $this->fontMetrics[$key] = ['base' => $m['ascent'] + ($m['lineHeight'] - $m['ascent'] - $m['descent']) * 0.5, 'height' => $m['lineHeight']];
        }

        return $this->fontMetrics[$key];
    }

    private function width(string $text, FontSpec $f): float
    {
        return $text === '' ? 0.0 : $this->measurer->measure($text, $f)->w;
    }

    private function addBox(int $kind, float $x, float $y, float $w, float $h, int $depth = 0, ?Framebuffer $image = null): int
    {
        $this->boxes[] = ['kind' => $kind, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'depth' => $depth, 'image' => $image];

        return count($this->boxes) - 1;
    }

    private function addRun(float $x, int $start, FontSpec $f, int $flags, int $link, int $color, ?SyntaxToken $token = null): int
    {
        $this->runs[] = [
            'x' => $x, 'y' => 0.0, 'w' => 0.0, 'lineY' => 0.0, 'lineH' => 0.0, 'start' => $start, 'length' => 0, 'font' => $f,
            'base' => $this->metrics($f)['base'], 'color' => $color, 'token' => $token, 'flags' => $flags, 'link' => $link, 'clipRight' => 0.0,
        ];

        return count($this->runs) - 1;
    }

    /** Ends a line of a flow: the runs since $first get their height and their place across (markdown.c:1105-1135). */
    private function endLine(int $first, float $x0, float $y, float $width, float $pen, int $align, float $minHeight, float $minBase): float
    {
        $height = $minHeight;
        $base = $minBase;
        $count = count($this->runs);
        for ($i = $first; $i < $count; $i++) {
            $base = max($base, $this->runs[$i]['base']);
            $height = max($height, $this->metrics($this->runs[$i]['font'])['height']);
        }
        // air between the lines
        $leading = floor($height * 0.26 + 0.5);
        $height = ceil($height) + $leading;
        $shift = $width > 0.0 && $align ? max(0.0, ($width - $pen) * ($align === 1 ? 0.5 : 1.0)) : 0.0;
        for ($i = $first; $i < $count; $i++) {
            $run = &$this->runs[$i];
            $run['x'] += $shift;
            $run['y'] = $y + $leading * 0.5 + ($base - $run['base']);
            $run['lineY'] = $y;
            $run['lineH'] = $height;
            if ($run['flags'] & Markdown::CODE) {
                // the tile behind a piece of code
                $this->addBox(self::BOX_INLINE_CODE, $run['x'] - 3.0, $y + $leading * 0.5 - 1.0, $run['w'] + 6.0, $height - $leading + 2.0);
            }
            unset($run);
        }
        $this->widest = max($this->widest, $x0 + $pen + $shift);

        return $height;
    }

    /** Lays spans out from ($x0, $y), wrapped on $width (0: one line); the height it took (markdown.c:1143-1229). */
    private function flow(int $firstSpan, int $spanCount, float $x0, float $y, float $width, float $size, bool $bold, bool $italic, int $align, int $color): float
    {
        $text = $this->md->text;
        $plain = $this->metrics($this->variant($size, $bold, $italic));
        $mono = new FontSpec($this->mono->family, $size * 0.94, $this->mono->weight, $this->mono->italic);
        $lineFirst = count($this->runs);
        $pen = 0.0;
        $top = $y;

        for ($s = $firstSpan; $s < $firstSpan + $spanCount; $s++) {
            [$at, $length, $flags, $link] = $this->md->spans[$s];
            $stop = $at + $length;
            $font = $flags & Markdown::CODE ? $mono : $this->variant($size, $bold || ($flags & Markdown::BOLD), $italic || ($flags & Markdown::ITALIC));
            $run = null;

            while ($at < $stop) {
                // a word and the spaces after it
                $wordEnd = $at + strcspn($text, ' ', $at, $stop - $at);
                $next = $wordEnd + strspn($text, ' ', $wordEnd, $stop - $wordEnd);
                $wordWidth = $this->width(substr($text, $at, $wordEnd - $at), $font);
                $fullWidth = $wordWidth + $this->width(substr($text, $wordEnd, $next - $wordEnd), $font);

                if ($width > 0.0 && $pen > 0.0 && $pen + $wordWidth > $width + 0.5) {
                    $y += $this->endLine($lineFirst, $x0, $y, $width, $pen, $align, $plain['height'], $plain['base']);
                    $lineFirst = count($this->runs);
                    $pen = 0.0;
                    $run = null;
                    // (the spaces a line was cut on do not start the next one)
                    if ($wordEnd === $at) {
                        $at = $next;

                        continue;
                    }
                }
                if ($run === null) {
                    $run = $this->addRun($x0 + $pen, $at, $font, $flags & (Markdown::CODE | Markdown::STRIKE), $link, $link >= 0 ? self::COLOR_LINK : $color);
                }
                $this->runs[$run]['length'] += $next - $at;
                $this->runs[$run]['w'] += $fullWidth;
                $pen += $fullWidth;
                $at = $next;
            }
            if ($flags & Markdown::BREAK) {
                $y += $this->endLine($lineFirst, $x0, $y, $width, $pen, $align, $plain['height'], $plain['base']);
                $lineFirst = count($this->runs);
                $pen = 0.0;
            }
        }
        if (count($this->runs) > $lineFirst || $y === $top) {
            $y += $this->endLine($lineFirst, $x0, $y, $width, $pen, $align, $plain['height'], $plain['base']);
        }

        return $y - $top;
    }

    /** Width spans take on one line (markdown.c:1232-1244). */
    private function naturalWidth(int $firstSpan, int $spanCount, float $size, bool $bold): float
    {
        $width = 0.0;
        for ($s = $firstSpan; $s < $firstSpan + $spanCount; $s++) {
            [$start, $length, $flags] = $this->md->spans[$s];
            $font = $flags & Markdown::CODE
                ? new FontSpec($this->mono->family, $size * 0.94, $this->mono->weight, $this->mono->italic)
                : $this->variant($size, $bold || ($flags & Markdown::BOLD), (bool) ($flags & Markdown::ITALIC));
            $width += $this->width(substr($this->md->text, $start, $length), $font);
        }

        return $width;
    }

    /** @param array<string, mixed> $block */
    private function codeBlock(array $block, float $x0, float $y, float $width): float
    {
        $size = $this->font->size;
        $padX = floor($size * 0.8);
        $padY = floor($size * 0.6);
        $mono = $this->metrics($this->mono);
        $lineHeight = ceil($mono['height'] * 1.18);
        $top = $y;
        $widest = 0.0;
        $firstRun = count($this->runs);
        $language = $block['language'] !== '' && strlen($block['language']) < 32 ? Syntax::find($block['language']) : null;
        $state = 0;
        $box = $this->addBox(self::BOX_CODE, $x0, $y, 0.0, 0.0);
        $y += $padY;
        $at = $block['codeStart'];
        foreach (explode("\n", substr($this->md->text, $at, $block['codeLength'])) as $line) {
            $spans = [];
            if ($language !== null) {
                $state = Syntax::line($language, $line, $state, $spans);
            }
            $pen = 0.0;
            $cursor = 0;
            $length = strlen($line);
            // the line, cut where its colour changes
            foreach ([...$spans, [$length, $length, null]] as [$pieceStart, $pieceEnd, $token]) {
                foreach ([[$cursor, $pieceStart, null], [$pieceStart, $pieceEnd, $token]] as [$a, $b, $kind]) {
                    if ($b <= $a) {
                        continue;
                    }
                    $r = $this->addRun($x0 + $padX + $pen, $at + $a, $this->mono, 0, -1, self::COLOR_TEXT, $kind);
                    $run = &$this->runs[$r];
                    $run['y'] = $y + ($lineHeight - $mono['height']) * 0.5;
                    $run['lineY'] = $y;
                    $run['lineH'] = $lineHeight;
                    $run['length'] = $b - $a;
                    $run['w'] = $this->width(substr($line, $a, $b - $a), $this->mono);
                    // (set once the width of the block is known)
                    $run['clipRight'] = -1.0;
                    $pen += $run['w'];
                    unset($run);
                }
                $cursor = $pieceEnd;
            }
            $widest = max($widest, $pen);
            $y += $lineHeight;
            $at += $length + 1;
        }
        $y += $padY;
        // as wide as the text around it; without wrapping, as wide as its longest line
        $boxWidth = $width > 0.0 ? $width : $widest + $padX * 2.0;
        $this->boxes[$box]['w'] = $boxWidth;
        $this->boxes[$box]['h'] = $y - $top;
        for ($i = $firstRun; $i < count($this->runs); $i++) {
            $this->runs[$i]['clipRight'] = $x0 + $boxWidth - $padX * 0.5;
        }
        $this->widest = max($this->widest, $x0 + $boxWidth);

        return $y - $top;
    }

    /** @param array<string, mixed> $block */
    private function table(array $block, float $x0, float $y, float $width): float
    {
        $size = $this->font->size;
        $padX = floor($size * 0.75);
        $padY = floor($size * 0.36);
        $columns = $block['columns'];
        $rows = $block['rows'];
        if ($columns <= 0 || $rows <= 0) {
            return 0.0;
        }
        $cell = fn (int $r, int $c) => $this->md->cells[$block['firstCell'] + $r * $columns + $c];
        $widths = [];
        $total = 0.0;
        for ($c = 0; $c < $columns; $c++) {
            $widths[$c] = $size * 2.0;
            for ($r = 0; $r < $rows; $r++) {
                [$first, $count] = $cell($r, $c);
                $widths[$c] = max($widths[$c], $this->naturalWidth($first, $count, $size, $r === 0) + 1.0);
            }
            $total += $widths[$c] + $padX * 2.0;
        }
        // too wide: the columns give up room in proportion, and their text wraps
        if ($width > 0.0 && $total > $width) {
            $room = max($width - $padX * 2.0 * $columns, $size * 2.0 * $columns);
            $sum = $total - $padX * 2.0 * $columns;
            $total = 0.0;
            for ($c = 0; $c < $columns; $c++) {
                $widths[$c] = max($size * 2.0, floor($widths[$c] * $room / $sum));
                $total += $widths[$c] + $padX * 2.0;
            }
        }
        $top = $y;
        for ($r = 0; $r < $rows; $r++) {
            $rowHeight = 0.0;
            $header = $r === 0 ? $this->addBox(self::BOX_TABLE_HEADER, $x0, $y, $total, 0.0) : -1;
            $x = $x0;
            for ($c = 0; $c < $columns; $c++) {
                [$first, $count, $align] = $cell($r, $c);
                $rowHeight = max($rowHeight, $this->flow($first, $count, $x + $padX, $y + $padY, $widths[$c], $size, $r === 0, false, $align, self::COLOR_TEXT));
                $x += $widths[$c] + $padX * 2.0;
            }
            $rowHeight += $padY * 2.0;
            if ($header >= 0) {
                $this->boxes[$header]['h'] = $rowHeight;
            }
            $y += $rowHeight;
            $this->addBox(self::BOX_TABLE_LINE, $x0, $y - 1.0, $total, 1.0);
        }
        $this->addBox(self::BOX_TABLE_LINE, $x0, $top, $total, 1.0);
        // the lines between the columns, and around
        $x = $x0;
        for ($c = 0; $c <= $columns; $c++) {
            $this->addBox(self::BOX_TABLE_LINE, $c === $columns ? $x - 1.0 : $x, $top, 1.0, $y - $top);
            if ($c < $columns) {
                $x += $widths[$c] + $padX * 2.0;
            }
        }
        $this->widest = max($this->widest, $x0 + $total);

        return $y - $top;
    }

    /** rvn_md_layout (markdown.c:1380-1530). */
    private function layout(float $width): Size
    {
        $size = $this->font->size;
        $y = 0.0;
        $gap = 0.0;
        $openQuote = 0;
        $quoteTop = [];
        $quoteIndent = floor($size * 1.15);
        $listIndent = floor($size * 1.7);
        $blocks = $this->md->blocks;

        for ($b = 0; $b <= count($blocks); $b++) {
            $block = $blocks[$b] ?? null;
            $quote = $block['quote'] ?? 0;
            // the bars of the quotes that end here
            while ($openQuote > $quote) {
                $this->addBox(self::BOX_QUOTE, $quoteIndent * ($openQuote - 1), $quoteTop[$openQuote], 3.0, $y - $quoteTop[$openQuote]);
                $openQuote--;
            }
            if ($block === null) {
                break;
            }
            // the space above: more before a title, less between the items of a list
            $before = $size * 0.72;
            if ($block['type'] === Markdown::HEADING) {
                $before = $size * ($block['level'] <= 2 ? 1.25 : 1.0);
            } elseif ($block['list'] > 0 && $b > 0 && $blocks[$b - 1]['list'] > 0) {
                $before = $size * ($block['marker'] ? 0.3 : 0.4);
            }
            if ($b > 0 && $blocks[$b - 1]['type'] === Markdown::HEADING) {
                $before = $size * 0.5;
            }
            if ($b === 0) {
                $before = 0.0;
            }
            $y += max($before, $gap);
            while ($openQuote < $quote) {
                $quoteTop[++$openQuote] = $y;
            }
            $x0 = $quoteIndent * $quote + $listIndent * $block['list'];
            $room = $width > 0.0 ? max($width - $x0, $size * 4.0) : 0.0;

            switch ($block['type']) {
                case Markdown::HEADING:
                    $headingSize = floor($size * self::HEADING_SCALE[max(1, min(6, $block['level']))] + 0.5);
                    $height = $this->flow($block['first'], $block['count'], $x0, $y, $room, $headingSize, true, false, 0, self::COLOR_TEXT);
                    // the two largest are underlined across
                    if ($block['level'] <= 2) {
                        $height += floor($size * 0.3);
                        $this->addBox(self::BOX_RULE, $x0, $y + $height, $room > 0.0 ? $room : -1.0, 1.0);
                        $height += 1.0;
                    }
                    break;
                case Markdown::CODE_BLOCK:
                    $height = $this->codeBlock($block, $x0, $y, $room);
                    break;
                case Markdown::RULE:
                    $this->addBox(self::BOX_RULE, $x0, $y + floor($size * 0.3), $room > 0.0 ? $room : -1.0, 1.0);
                    $height = floor($size * 0.6) + 1.0;
                    break;
                case Markdown::TABLE:
                    $height = $this->table($block, $x0, $y, $room);
                    break;
                case Markdown::IMAGE:
                    $image = $block['link'] >= 0 && $this->images !== null ? ($this->images)($this->md->links[$block['link']]) : null;
                    $w = $image ? floor($image->viewportWidth()) : 0.0;
                    $h = $image ? floor($image->viewportHeight()) : 0.0;
                    if ($w > 0 && $h > 0) {
                        if ($room > 0.0 && $w > $room) {
                            $h = floor($h * $room / $w);
                            $w = $room;
                        }
                        $this->addBox(self::BOX_IMAGE, $x0, $y, $w, $h, 0, $image);
                        $this->widest = max($this->widest, $x0 + $w);
                        $height = $h;
                    } else {
                        // an image that cannot be shown is told by its alternative text
                        $height = $this->flow($block['first'], $block['count'], $x0, $y, $room, $size, false, true, 0, self::COLOR_QUOTE);
                    }
                    break;
                default:
                    if ($block['marker'] !== Markdown::NO_MARKER) {
                        $this->marker($block, $x0, $y, $listIndent);
                    }
                    $height = $this->flow($block['first'], $block['count'], $x0, $y, $room, $size, false, false, 0, $quote > 0 ? self::COLOR_QUOTE : self::COLOR_TEXT);
                    break;
            }
            $y += $height;
        }

        // rules asked to span the text: now that its width is known
        $full = $width > 0.0 ? $width : $this->widest;
        foreach ($this->boxes as &$box) {
            if ($box['kind'] === self::BOX_RULE && $box['w'] < 0.0) {
                $box['w'] = max($full - $box['x'], 0.0);
            }
        }
        unset($box);

        return new Size(ceil($this->widest), ceil($y));
    }

    /** @param array<string, mixed> $block */
    private function marker(array $block, float $x0, float $y, float $listIndent): void
    {
        $size = $this->font->size;
        $regular = $this->metrics($this->font);
        if ($block['marker'] === Markdown::NUMBER) {
            // the number, right against the text
            $number = $block['number'].'.';
            $start = strlen($this->text);
            $this->text .= $number;
            $numberWidth = $this->width($number, $this->font);
            $r = $this->addRun($x0 - $numberWidth - floor($size * 0.4), $start, $this->font, 0, -1, self::COLOR_QUOTE);
            $this->runs[$r]['y'] = $y + floor($regular['height'] * 0.13 + 0.5);
            $this->runs[$r]['lineY'] = $y;
            $this->runs[$r]['lineH'] = $regular['height'];
            $this->runs[$r]['w'] = $numberWidth;
            $this->runs[$r]['length'] = strlen($number);

            return;
        }
        $line = ceil($regular['height']) + floor($regular['height'] * 0.26 + 0.5);
        $side = $block['marker'] === Markdown::BULLET ? floor($size * 0.36) : floor($size * 0.95);
        $kind = match ($block['marker']) {
            Markdown::BULLET => self::BOX_BULLET,
            Markdown::TASK => self::BOX_TASK,
            default => self::BOX_TASK_DONE,
        };
        // (the bullets of lists in lists are smaller rings: see the painting)
        $this->addBox($kind, $x0 - $listIndent + floor(($listIndent - $side) * 0.55), $y + floor(($line - $side) * 0.5), $side, $side, $block['list']);
    }

    // --------------------------------------------------------------- paint

    /**
     * rvn_md_paint (markdown.c:1532-1604).
     *
     * @param  array{text: Color, link: Color, separator: Color, code: Color, accent: Color, accentText: Color}  $colors
     * @param  Closure(SyntaxToken): ?Color  $syntax  a token's colour, null for the text's
     */
    public function paint(Painter $p, float $ox, float $oy, array $colors, Closure $syntax, int $hotLink = -1): void
    {
        $color = $colors['text'];
        $dim = self::fade($color, 0.72);
        $none = new Color(0, 0, 0, 0);
        foreach ($this->boxes as $box) {
            $r = new Rect($ox + $box['x'], $oy + $box['y'], $box['w'], $box['h']);
            match ($box['kind']) {
                self::BOX_CODE => $p->rect($r, $colors['code'], 7.0, self::fade($colors['separator'], 0.6), 1.0),
                self::BOX_INLINE_CODE => $p->rect($r, $colors['code'], 4.0),
                self::BOX_QUOTE => $p->rect($r, self::fade($colors['accent'], 0.75), 1.5),
                self::BOX_RULE, self::BOX_TABLE_LINE => $p->rect($r, $colors['separator']),
                self::BOX_TABLE_HEADER => $p->rect($r, self::fade($colors['code'], 0.8)),
                // a disc, then rings for the lists inside lists
                self::BOX_BULLET => $box['depth'] <= 1 ? $p->rect($r, $dim, $r->w * 0.5) : $p->rect($r, $none, $r->w * 0.5, $dim, 1.2),
                self::BOX_TASK => $p->rect($r, $none, 3.5, $dim, 1.3),
                self::BOX_TASK_DONE => $this->taskDone($p, $r, $colors),
                // shortcut: the 6-unit corner radius is blocked (roadmap Blocked list #1), drawn square until Surface image() takes a radius
                self::BOX_IMAGE => $p->image($box['image'], $r, 6.0),
                default => null,
            };
        }
        foreach ($this->runs as $run) {
            $runColor = match (true) {
                $run['color'] === self::COLOR_LINK => $colors['link'],
                $run['color'] === self::COLOR_QUOTE => $dim,
                $run['token'] !== null => self::withAlpha($syntax($run['token']) ?? $color, $color->alpha),
                default => $color,
            };
            $clipped = $run['clipRight'] > 0.0;
            if ($clipped) {
                if ($run['x'] >= $run['clipRight']) {
                    continue;
                }
                $p->pushClip(new Rect($ox + $run['x'] - 2.0, $oy + $run['lineY'], $run['clipRight'] - $run['x'] + 2.0, $run['lineH']));
            }
            $p->text(substr($this->text, $run['start'], $run['length']), $ox + $run['x'], $oy + $run['y'] + $run['base'], $runColor, $run['font']);
            $clipped && $p->popClip();
            if ($run['flags'] & Markdown::STRIKE) {
                $p->rect(new Rect($ox + $run['x'], $oy + $run['lineY'] + floor($run['lineH'] * 0.54), $run['w'], 1.0), $runColor);
            }
            // the link under the pointer is underlined
            if ($run['link'] >= 0 && $run['link'] === $hotLink) {
                $p->rect(new Rect($ox + $run['x'], $oy + $run['lineY'] + $run['lineH'] - floor($run['lineH'] * 0.2), $run['w'], 1.0), $runColor);
            }
        }
    }

    /** @param array<string, Color> $colors */
    private function taskDone(Painter $p, Rect $r, array $colors): void
    {
        $p->rect($r, $colors['accent'], 3.5);
        Icons::paint($p, Icons::CHECK, $r->x + $r->w * 0.5, $r->y + $r->h * 0.5, $r->w * 0.62, $colors['accentText']);
    }

    private static function fade(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $c->alpha * $a);
    }

    private static function withAlpha(Color $c, float $a): Color
    {
        return new Color($c->red, $c->green, $c->blue, $a);
    }

    // ------------------------------------------------------------ pointing

    /** The link under a point, or -1 (markdown.c:1606-1614). */
    public function linkAt(float $x, float $y): int
    {
        foreach ($this->runs as $run) {
            if ($run['link'] >= 0 && $x >= $run['x'] && $x < $run['x'] + $run['w'] && $y >= $run['lineY'] && $y < $run['lineY'] + $run['lineH']) {
                return $run['link'];
            }
        }

        return -1;
    }

    public function link(int $link): ?string
    {
        return $this->md->links[$link] ?? null;
    }

    public function linkCount(): int
    {
        return count($this->md->links);
    }

    // ----------------------------------------------------------- selection

    /** (the numbers of the lists are added after the text by the layout: they are not selected) */
    private function selectable(array $run): bool
    {
        return $run['length'] > 0 && $run['start'] < $this->parsedLength;
    }

    /** Where an offset of a run is across. */
    private function runX(array $run, int $offset): float
    {
        if ($offset <= $run['start']) {
            return $run['x'];
        }
        if ($offset >= $run['start'] + $run['length']) {
            return $run['x'] + $run['w'];
        }

        return $run['x'] + $this->width(substr($this->text, $run['start'], $offset - $run['start']), $run['font']);
    }

    /** The offset in the shown text nearest a point (rvn_md_offset_at, markdown.c:1653-1690). */
    public function offsetAt(float $x, float $y): int
    {
        $line = null;
        $best = INF;
        // the line under the point, else the nearest one
        foreach ($this->runs as $run) {
            if (! $this->selectable($run)) {
                continue;
            }
            $distance = $y < $run['lineY'] ? $run['lineY'] - $y : ($y >= $run['lineY'] + $run['lineH'] ? $y - $run['lineY'] - $run['lineH'] + 0.001 : 0.0);
            if ($distance < $best) {
                $best = $distance;
                $line = $run;
            }
        }
        if ($line === null) {
            return 0;
        }
        // on it, the run under the point, else the nearest one
        $pick = $line;
        $best = INF;
        foreach ($this->runs as $run) {
            if (! $this->selectable($run) || abs($run['lineY'] - $line['lineY']) > 0.01) {
                continue;
            }
            $distance = $x < $run['x'] ? $run['x'] - $x : ($x >= $run['x'] + $run['w'] ? $x - $run['x'] - $run['w'] + 0.001 : 0.0);
            if ($distance < $best) {
                $best = $distance;
                $pick = $run;
            }
        }
        if ($x <= $pick['x']) {
            return $pick['start'];
        }
        if ($x >= $pick['x'] + $pick['w']) {
            return $pick['start'] + $pick['length'];
        }

        return $pick['start'] + $this->measurer->offsetAt(substr($this->text, $pick['start'], $pick['length']), $pick['font'], $x - $pick['x']);
    }

    /**
     * What separates two runs in a copy: nothing in a line, a tab between cells, nothing where a line was
     * wrapped, a line break otherwise (markdown.c:1692-1702).
     */
    private function between(array $a, array $b): string
    {
        $end = $a['start'] + $a['length'];
        if ($b['start'] > $end && str_contains(substr($this->text, $end, $b['start'] - $end), "\n")) {
            return "\n";
        }
        if (abs($a['lineY'] - $b['lineY']) <= 0.01) {
            return $b['x'] > $a['x'] + $a['w'] + 4.0 ? "\t" : '';
        }
        if ($end > $a['start'] && $this->text[$end - 1] === ' ') {
            return '';
        }

        return "\n";
    }

    public function paintSelection(Painter $p, float $ox, float $oy, int $from, int $to, Color $color): void
    {
        if ($from >= $to) {
            return;
        }
        foreach ($this->runs as $run) {
            $start = max($from, $run['start']);
            $end = min($to, $run['start'] + $run['length']);
            if (! $this->selectable($run) || $start >= $end) {
                continue;
            }
            $x0 = $this->runX($run, $start);
            $p->rect(new Rect($ox + $x0, $oy + $run['lineY'], $this->runX($run, $end) - $x0, $run['lineH']), $color);
        }
    }

    /** The selection as copied, the marks of Markdown left out (markdown.c:1721-1736). */
    public function selectedText(int $from, int $to): string
    {
        $out = '';
        $previous = null;
        foreach ($this->runs as $run) {
            $start = max($from, $run['start']);
            $end = min($to, $run['start'] + $run['length']);
            if (! $this->selectable($run) || $start >= $end) {
                continue;
            }
            if ($previous !== null) {
                $out .= $this->between($previous, $run);
            }
            $out .= substr($this->text, $start, $end - $start);
            $previous = $run;
        }

        return $out;
    }

    /** @return array{int, int} the paragraph around an offset: the runs on both sides until a line break */
    public function paragraphAt(int $offset): array
    {
        $at = -1;
        foreach ($this->runs as $i => $run) {
            if ($this->selectable($run) && $offset >= $run['start'] && $offset <= $run['start'] + $run['length']) {
                $at = $i;
                break;
            }
        }
        if ($at < 0) {
            return [$offset, $offset];
        }
        $first = $last = $at;
        for ($i = $at - 1; $i >= 0; $i--) {
            if (! $this->selectable($this->runs[$i])) {
                continue;
            }
            if (($this->between($this->runs[$i], $this->runs[$first])[0] ?? '') === "\n") {
                break;
            }
            $first = $i;
        }
        for ($i = $at + 1; $i < count($this->runs); $i++) {
            if (! $this->selectable($this->runs[$i])) {
                continue;
            }
            if (($this->between($this->runs[$last], $this->runs[$i])[0] ?? '') === "\n") {
                break;
            }
            $last = $i;
        }

        return [$this->runs[$first]['start'], $this->runs[$last]['start'] + $this->runs[$last]['length']];
    }
}
