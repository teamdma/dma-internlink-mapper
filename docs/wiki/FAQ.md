# Frequently Asked Questions

## Does DMA InternLink Mapper send my content to AI?

No. Content analysis and link matching are performed locally on your WordPress site.

The plugin does not send your site content or scan results to a remote AI service, advertising network or analytics platform.

## Does it scan automatically?

Normal site scans start only when an authorized administrator requests one.

There is an optional bounded broken-link monitor that can run through WP-Cron. It is disabled by default and checks only a small number of destinations per run.

## Does it work with Elementor?

Yes, with limits that are there for a reason.

Supported saved Elementor text and WYSIWYG controls can be analyzed. Automatic insertion is limited to supported body-text controls.

Headings, buttons, URL fields, code fields, Dynamic Tags, headers, hero areas, calls to action and footers are not automatically modified.

## Does it work with Gutenberg and the Classic Editor?

Yes. Both are supported for analysis, and supported body content can be used for reviewed link insertion.

## Does it work with Yoast SEO or Rank Math?

Yes. When available, supported focus-keyphrase metadata can be used as an additional relevance signal.

The plugin also works without either SEO plugin.

## Can it handle large websites?

The scanner works in batches to reduce server load, and visualizations use practical display limits to stay responsive.

A very large rendered site can still require significant CPU, memory and database work. Conservative batch sizes are sensible on busy or shared hosting.

## What does the SEO score mean?

The SEO score is a local diagnostic score from DMA InternLink Mapper.

It is not a Google ranking score and it does not guarantee that a page will rank higher. Use it as one signal among the reports, not as a scoreboard that must reach 100 at any cost.

## Can I check external links?

Yes, but external HTTP checking is optional and disabled by default.

If enabled, your WordPress server connects directly to those destinations to inspect their HTTP status and redirects.

## Does Search Console import connect to my Google account?

No. You manually import a Search Console CSV or ZIP export, and the data is processed locally.

The plugin does not use Google Site Kit credentials for this feature.

## Can I undo inserted links?

The plugin keeps insertion history and supports undo for supported changes. Optional WordPress revisions can provide another recovery layer.

A normal site backup is still recommended before large batches of content edits.

## Can I remove all plugin data?

Yes. Administrators can remove stored history and indexed data.

Full database cleanup on uninstall can also be enabled in the plugin settings.

## Why does a suggested link still need human review?

Because relevance is not just a number.

The plugin can check technical suitability, existing links, anchors and confidence, but only a person reading the page can decide whether the link genuinely helps the visitor.
