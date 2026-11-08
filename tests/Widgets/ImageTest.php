<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Core\Tree\Tree;
use Silk\Widgets\Column;
use Silk\Widgets\Image;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Venusian\Silk\Tests\Support\SpyPainter;

/** A 100x50 image in a 200x200 box; the rect the painter got. */
function imageRect(ImageScaling $scaling): array
{
    $image = (new Image('img'))->setFramebuffer(new NativeFullFramebuffer(FormatSpec::rgba8(), 100, 50))->setScaling($scaling);
    $image->arrange(new Rect(0, 0, 200, 200));
    $p = new SpyPainter;
    $image->paint($p);

    return [$p->methods(), array_values(array_filter($p->calls, fn ($c) => $c[0] === 'image'))[0][1][1]];
}

it('fits inside the box, centred', function () {
    [$methods, $r] = imageRect(ImageScaling::FIT);

    expect($r)->toEqual(new Rect(0, 50, 200, 100))->and($methods)->toBe(['image']);
});

it('fills the box, centred and clipped', function () {
    [$methods, $r] = imageRect(ImageScaling::FILL);

    expect($r)->toEqual(new Rect(-100, 0, 400, 200))->and($methods)->toBe(['pushClip', 'image', 'popClip']);
});

it('centres at its own size, and stretches', function () {
    expect(imageRect(ImageScaling::CENTER)[1])->toEqual(new Rect(50, 75, 100, 50))
        ->and(imageRect(ImageScaling::STRETCH)[1])->toEqual(new Rect(0, 0, 200, 200));
});

it('wants its natural size, and nothing without an image', function () {
    $tree = new Tree($root = new Column('window'));
    $root->add($with = (new Image('a'))->setFramebuffer(new NativeFullFramebuffer(FormatSpec::rgba8(), 30, 20)));
    $root->add($without = new Image('b'));

    expect($with->measure(new Size(500, 500)))->toEqual(new Size(30, 20))
        ->and($without->measure(new Size(500, 500)))->toEqual(new Size(0, 0));
});

it('loads a file through the decoder, and clears with null', function () {
    $seen = [];
    Image::$decoder = function (string $path) use (&$seen) {
        $seen[] = $path;

        return new NativeFullFramebuffer(FormatSpec::rgba8(), 4, 3);
    };
    try {
        $image = new Image('img', '/pics/a.png');
        expect($image->file())->toBe('/pics/a.png')
            ->and($image->framebuffer()?->viewportWidth())->toBe(4)
            ->and($image->framebuffer())->toBe($image->framebuffer())
            ->and($seen)->toBe(['/pics/a.png'])
            ->and($image->setFile(null)->framebuffer())->toBeNull();
    } finally {
        Image::$decoder = null;
    }
});
