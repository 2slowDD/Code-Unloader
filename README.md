# Code Unloader

<p align="center">
  <a href="https://github.com/2slowDD/Code-Unloader/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/2slowDD/Code-Unloader/ci.yml?branch=main&label=CI&style=for-the-badge"></a>
  <img alt="Claude Code Skill" src="https://img.shields.io/badge/Claude%20Code-Skill-5A32A3?style=for-the-badge">
  <img alt="Codex Skill" src="https://img.shields.io/badge/Codex-Skill-111111?style=for-the-badge">
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img alt="License: GPL-2.0-or-later" src="https://img.shields.io/badge/License-GPL--2.0--or--later-green?style=for-the-badge"></a>
  <img alt="Version 1.4.11" src="https://img.shields.io/badge/Version-1.4.11-blue?style=for-the-badge">
</p>

<p align="center">
  <strong>Per-page JavaScript & CSS asset management for WordPress.</strong>
</p>

Surgically dequeue scripts and styles on any page using exact, wildcard, or regex URL rules. Rules survive cache flushes and plugin reactivations. Inline scripts and styles are detected and can be blocked without a registered handle.

## Automatic unloading with AI Assets Scanner

Code Unloader is manual by design — you pick which handles to drop on which pages. If you would rather not do that by hand, it pairs with [AI Assets Scanner](https://github.com/2slowDD/AI-Assets-Scanner), which renders each page, works out which CSS and JS that page never uses, and writes the rules into Code Unloader for you.

The two are built as a pair: the scanner is the analysis half, Code Unloader is the half that applies the rules. Scan results arrive as ordinary Code Unloader rules, grouped into **AA Scanner — Safe** and **AA Scanner — Aggressive**, so everything the scanner decides stays visible, editable, and deletable on the Rules tab like any rule you wrote yourself. Nothing becomes a black box, and Code Unloader behaves exactly the same with or without it.

AI Assets Scanner is a separate companion plugin also developed by [WPservice.pro](https://wpservice.pro).

## Plugin docs

The full end-user docs (features, installation, FAQ, changelog, screenshots) live in [`readme.txt`](readme.txt) — the standard WordPress.org plugin readme that the plugin directory renders.

For the engineering-level version history see [`CHANGELOG.md`](CHANGELOG.md).

## Requirements

- WordPress 6.2+
- PHP 8.0+

## License

GPL-2.0-or-later. See the plugin header in [`code-unloader.php`](code-unloader.php).
