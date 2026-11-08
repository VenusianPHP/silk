<?php

namespace Silk\Widgets\Docking;

/**
 * Raven's saved layout text (dock_area.c:1643-1843):
 *   layout := split | group
 *   split  := ('H' | 'V') '(' share ',' layout ',' layout ')'
 *   group  := 'T' '(' shown ';' name { ',' name } ')'
 * H side by side, V one above the other; share in thousandths of the first; names with anything but letters, digits,
 * '_', '-' and '.' written as %XX.
 */
final class Layout
{
    public static function save(?Node $node): string
    {
        if ($node === null) {
            return '';
        }
        if ($node->isSplit()) {
            return ($node->vertical ? 'V' : 'H').'('.(int) (min(1.0, max(0.0, $node->ratio)) * 1000 + 0.5).','.self::save($node->a).','.self::save($node->b).')';
        }
        $names = array_map(fn (string $n) => preg_replace_callback('/[^A-Za-z0-9_.\-]/', fn ($m) => sprintf('%%%02X', ord($m[0])), $n), array_values(array_filter($node->panels, fn ($n) => $n !== '')));

        return 'T('.$node->active.';'.implode(',', $names).')';
    }

    /**
     * The tree a text describes, over the panels named in $panels (each used once); a node without a panel left is
     * left out. False when the text is not a layout. @param list<string> $panels  @param array<string, true> $used
     */
    public static function load(string $text, array $panels, array &$used): Node|false|null
    {
        $at = 0;
        $t = ltrim($text, ' ');
        $ok = true;
        $root = self::node($t, $at, $panels, $used, $ok, 0);
        while ($ok && ($t[$at] ?? '') === ' ') {
            $at++;
        }

        return $ok && $at === strlen($t) ? $root : false;
    }

    private static function node(string $t, int &$at, array $panels, array &$used, bool &$ok, int $depth): ?Node
    {
        if ($depth > 64) {
            return self::fail($ok);
        }
        $head = substr($t, $at, 2);
        if ($head === 'H(' || $head === 'V(') {
            $at += 2;
            if (! ctype_digit($t[$at] ?? '')) {
                return self::fail($ok);
            }
            $share = 0;
            while (ctype_digit($t[$at] ?? '') && $share < 100000) {
                $share = $share * 10 + (int) $t[$at++];
            }
            if (($t[$at++] ?? '') !== ',') {
                return self::fail($ok);
            }
            $a = self::node($t, $at, $panels, $used, $ok, $depth + 1);
            $ok && ($t[$at++] ?? '') !== ',' && $ok = false;
            $b = $ok ? self::node($t, $at, $panels, $used, $ok, $depth + 1) : null;
            $ok && ($t[$at++] ?? '') !== ')' && $ok = false;
            if (! $ok) {
                return null;
            }
            // a side with no panel left gives its place to the other
            if ($a === null || $b === null) {
                return $a ?? $b;
            }
            $n = new Node;
            [$n->vertical, $n->ratio, $n->a, $n->b] = [$head === 'V(', min(0.98, max(0.02, $share / 1000)), $a, $b];
            $a->parent = $b->parent = $n;

            return $n;
        }
        if ($head === 'T(') {
            $at += 2;
            $shown = 0;
            while (ctype_digit($t[$at] ?? '') && $shown < 100000) {
                $shown = $shown * 10 + (int) $t[$at++];
            }
            if (($t[$at++] ?? '') !== ';') {
                return self::fail($ok);
            }
            $n = new Node;
            [$position, $shownIndex] = [0, -1];
            while ($at < strlen($t) && $t[$at] !== ')') {
                $name = '';
                while ($at < strlen($t) && $t[$at] !== ',' && $t[$at] !== ')') {
                    $c = $t[$at++];
                    if ($c === '%') {
                        $hex = substr($t, $at, 2);
                        if (strlen($hex) < 2 || ! ctype_xdigit($hex)) {
                            return self::fail($ok);
                        }
                        [$c, $at] = [chr(hexdec($hex)), $at + 2];
                    }
                    strlen($name) < 255 && $name .= $c;
                }
                ($t[$at] ?? '') === ',' && $at++;
                if (in_array($name, $panels, true) && ! isset($used[$name])) {
                    $used[$name] = true;
                    $position === $shown && $shownIndex = count($n->panels);
                    $n->panels[] = $name;
                }
                $position++;
            }
            if (($t[$at++] ?? '') !== ')') {
                return self::fail($ok);
            }
            if ($n->panels === []) {
                return null;
            }
            $n->active = max(0, $shownIndex);

            return $n;
        }

        return self::fail($ok);
    }

    private static function fail(bool &$ok): null
    {
        $ok = false;

        return null;
    }
}
