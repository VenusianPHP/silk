<?php

namespace Silk\Looks;

use Silk\Contracts\Look\State;

/**
 * Styles as [widget, class, part, state, props]; null widget/class/state matches any.
 * Specificity: type 1, class 2, state 2; ties go to the last written.
 */
class StyleSheet
{
    /** @var list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    private array $rules = [];

    public function add(array $rules): static
    {
        foreach ($rules as $rule) {
            $this->rules[] = array_values($rule);
        }

        return $this;
    }

    /** @param list<string> $classes  @return array<string, mixed> merged props */
    public function resolve(string $widget, string $part, State $state, array $classes = []): array
    {
        $matches = [];
        foreach ($this->rules as $order => [$w, $class, $p, $s, $props]) {
            if ($p !== $part || ($w !== null && $w !== $widget) || ($s !== null && $s !== $state)
                || ($class !== null && ! in_array($class, $classes, true))) {
                continue;
            }
            $matches[] = [($w !== null ? 1 : 0) + ($class !== null ? 2 : 0) + ($s !== null ? 2 : 0), $order, $props];
        }
        usort($matches, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_merge([], ...array_column($matches, 2));
    }
}
