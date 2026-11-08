# Venusian Silk

[![Tests](https://github.com/VenusianPHP/silk/actions/workflows/tests.yml/badge.svg)](https://github.com/VenusianPHP/silk/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/venusian/silk.svg)](https://packagist.org/packages/venusian/silk)
[![Total Downloads](https://img.shields.io/packagist/dt/venusian/silk.svg)](https://packagist.org/packages/venusian/silk)
[![License](https://img.shields.io/packagist/l/venusian/silk.svg)](LICENSE)

## About Venusian Silk

> **Note:** This repository contains the widget toolkit for the Venusian framework. If you want to build an application using Venusian, visit the main [Venusian skeleton repository](https://github.com/VenusianPHP/venusian).

Venusian Silk is the Venusian widget toolkit: self-drawn, springy widgets for Venusian Surface — panels, buttons, menus, tables, editors, pickers, dialogs and toasts — that paint themselves through any Surface rendering engine, all in the Laravel-like syntax you already know.

A Surface `TKButton` stores its state and asks the toolkit to draw it; a Silk `Button` stores the same state and draws itself. Compose widgets the way you compose `TK*` primitives — `$column->button('save', 'Save')` — and where a Silk widget has a `TK*` twin it keeps the twin's creation methods, setter names and mail, so moving a window from toolkit widgets to Silk is mechanical. Each widget posts the same mail its toolkit twin posts — clicked, changed, committed — to the Venusian event loop, and only ever for user action. The look and the motion are [Raven](https://github.com/oscar-soirey/Raven)'s — near-native, prettier, springy — the reference, not a dependency.

Need a settings panel for your weather station? A node graph, a timeline, a code editor, a file browser, Scratch-style blocks? The set is whole: every widget type Raven has, and every animated value is a damped spring solved in closed form, so hover, popups and scrolling move the same at any frame rate. Twelve themes ship built in — from `raven-dark` through macOS and GNOME to glass — themable through the service container and config, with a theme switch that blends live. Silk owns a whole staged window, or lives inside a canvas beside the native widgets of a toolkit window — the same tree on either host, drawing on Velvet, the software engine that ships in the box, or on Metal, OpenGL, SDL_GPU or Vulkan plugged in as their own packages, with no engine-specific code anywhere. And an idle tree costs nothing.

> **Built with AI assistance.** This package was developed LLM-assisted — not vibe-coded. Generated code was deliberately prompted and reviewed by a professional software engineer. See [AI-assisted development](#ai-assisted-development).

## Learning Venusian Silk

Venusian Silk has documentation and guides on the [Venusian website](https://venusian.projectsaturnstudios.com/docs/silk/overview), starting with the [overview](https://venusian.projectsaturnstudios.com/docs/silk/overview). The [gallery example](examples/gallery.php) in this repository shows every widget, working, on every built-in theme.

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Contributing

Thank you for considering contributing to Venusian Silk! The contribution guide can be found in the [Venusian documentation](https://venusian.projectsaturnstudios.com/docs/contributions).

## Code of Conduct

In order to ensure that the Venusian community is welcoming to all, please review and abide by the [Code of Conduct](https://venusian.projectsaturnstudios.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/VenusianPHP/silk/security/policy) on how to report security vulnerabilities.

## License

Venusian Silk is open-sourced software licensed under the [MIT license](LICENSE).
