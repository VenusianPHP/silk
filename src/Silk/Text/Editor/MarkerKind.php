<?php

namespace Silk\Text\Editor;

/** What a marker says about the text it underlines (Raven RMarkerKind, raven_text_editor.h:139-143). */
enum MarkerKind
{
    case Error;
    case Warning;
    case Info;
}
