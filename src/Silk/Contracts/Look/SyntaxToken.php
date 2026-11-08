<?php

namespace Silk\Contracts\Look;

/** Raven's kinds of token (common.h:320-337), without TEXT: what no span covers is plain text. */
enum SyntaxToken
{
    case Comment;
    case String;
    case Number;
    case Keyword;
    case Tag;
    case Attribute;
    case Punctuation;
    /** Raven RVN_TOKEN_PREPROCESSOR, "Directive" in theme files (theme.c:477-485). */
    case Directive;
    case Type;
    case Function;
    /* formatted text (Markdown) */
    case Heading;
    case Strong;
    case Emphasis;
    case Link;
}
