<?php

namespace Silk\Looks\Styles;

use Silk\Contracts\Look\State;

/** RavenDark styles for the editing widgets, as [widget, class, part, state, props]. Task 13 merges them into RavenDark. */
final class EditingStyles
{
    /** @return list<array{?string, ?string, string, ?State, array<string, mixed>}> */
    public static function list(): array
    {
        return [
            // Raven text_input.c: Input fill, Border outline mixed to Focus as the field takes focus
            ['TextInput', null, 'Body', null, ['background' => 'Input', 'border' => 'Border']],
            ['TextInput', null, 'Body', State::Focused, ['border' => 'Focus']],
            ['TextInput', null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5]],
            // Raven spin_box.c: the same field, Focus border while focused
            // Raven's spin box: no buttons, it scrubs; half the fill when disabled (spin_box.c:73-79, 189)
            ['SpinBox', null, 'Body', null, ['background' => 'Input', 'border' => 'Border', 'steppers' => 'drag']],
            ['SpinBox', null, 'Body', State::Disabled, ['backgroundAlpha' => 0.5]],
            ['SpinBox', null, 'Body', State::Focused, ['border' => 'Focus']],
        ];
    }
}
