---
type: Runbook
title: CI
description: tests.yml on GitHub Actions - the matrix, the patched floor, the sibling surface checkout, what runs on a runner and what skips.
resource: .github/workflows/tests.yml
tags: [silk, ci, github actions, tests]
status: draft
generated: { by: glm/5.3, at: 2026-11-08T22:20:00Z }
sources:
  - id: workflow
    resource: .github/workflows/tests.yml
    title: The workflow
  - id: house
    resource: ../framework/.github/workflows/tests.yml
    title: The ecosystem house pattern
---

# tests.yml

Push (every branch), pull_request and workflow_call, like [framework, surface, serve and probe](../framework/.github/workflows/tests.yml). One job, `ubuntu-latest`, matrix PHP 8.4 and 8.5, `fail-fast` off. `actions/checkout@v5`, `shivammathur/setup-php@v2` with `dom, gd, intl, mbstring` and `update: true` (the newest patch of the line, never a cached older build), then the floor check, `composer update --prefer-stable --no-interaction --no-progress`, `vendor/bin/pest`. The suite needs no ZTS leg: nothing in it touches threads.

# The patched floor

composer.json's `"php": "~8.4.27||^8.5.12"` is a security floor: each line's lowest patched release. The matrix `include` carries the floor per line and a step refuses a runner PHP below it, as framework's and serve's do.

# The sibling surface checkout

`tests/Pest.php` requires Surface's `FakeFontDriver` and `tests/Support/M2Helpers.php` reads Surface's fixtures, through `SURFACE_TESTS` (its default: a sibling `../surface/tests`). The Packagist dist export-ignores Surface's tests, so the workflow clones `VenusianPHP/surface` to `../surface` and points `SURFACE_TESTS` at it. Surface's main holds the fakes silk's main is written against; when the two drift, a line branch may need the clone pinned to its tag.

# What runs on a runner

Dependencies resolve from Packagist (the path repositories are gone from composer.json; framework 0.11.0, the surface and voyager packages 0.11.x). The suite is engine-free - SpyEngine, SpyPainter, SpyLoop - so nothing needs a display. The native font and raster exts (freetype, harfbuzz, fontconfig, fb, rasterize, imgdec) are not built for GitHub runners, so the real-text rows skip there - the `hasFontExts()` guards, ButtonTest, LabelWrapTest, CaretGeometryTest (11 rows on a stock PHP 8.5); the intl rows run, the runner has intl. Locally the whole suite runs under zhp (PHP 8.4.27 ZTS with every ext): 1690 tests, nothing skipped.
