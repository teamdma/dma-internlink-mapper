# DMA InternLink Mapper

[![Quality and security](https://github.com/teamdma/dma-internlink-mapper/actions/workflows/quality.yml/badge.svg)](https://github.com/teamdma/dma-internlink-mapper/actions/workflows/quality.yml)

DMA InternLink Mapper is a WordPress plugin for understanding and improving a site's internal link structure.

It scans links, finds orphan pages and link opportunities, checks anchors and broken links, and includes visual 2D/3D maps to make larger sites easier to understand.

## Main features

* Internal link scanner and reports
* Link opportunities with preview, validation and undo
* Orphan page and anchor analysis
* Broken and external link tools
* Visual maps and Knowledge Graph
* On-page SEO checks
* Search Console CSV/ZIP import
* Classic Editor, Gutenberg and Elementor support

Core analysis runs inside WordPress. External link checking is optional.

## Requirements

* WordPress 6.5+
* PHP 7.4+

## Documentation

Full documentation:

https://desertmoroccoadventure.com/files/internal-link-seo-mapper/

## Development

This repository keeps release history, tests and quality checks alongside the plugin source.

Useful commands after `composer install`:

```bash
composer lint:php
composer audit:security
composer test
composer phpcs:security
composer phpcs:compat
```

The WordPress.org plugin information lives in `readme.txt`.

## License

GPL-2.0-or-later
