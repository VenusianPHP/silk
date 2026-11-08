<?php

namespace Silk\Core\Tree;

use Silk\Contracts\Look\Look;

/** A widget that gives itself and what it holds a look of their own (Raven rvn_widget_set_theme, spread to the children). */
interface LookScope
{
    /** The look of this subtree, given the tree's; null keeps the tree's. */
    public function scopedLook(Look $treeLook): ?Look;
}
