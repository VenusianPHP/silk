<?php

return [
    // The look every tree starts with.
    'look' => 'raven-dark',

    // The accent colour of every look:
    //   auto   - the system's, when the toolkit reports one; otherwise the look's own
    //   system - the system's; an error when the toolkit reports none
    //   theme  - the look's own, the system is not asked
    //   custom - accent_color
    'accent' => 'auto',

    // '#RRGGBB', used when accent is 'custom'.
    'accent_color' => null,

    // UI scale, multiplied by the window scale (0.25..4).
    'scale' => 1.0,

    // Snap every spring to its target.
    'reduced_motion' => false,

    // fontconfig families for the UI and monospace text.
    'font' => [
        'family' => 'sans-serif',
        'mono' => 'monospace',
    ],
];
