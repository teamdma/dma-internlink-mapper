=== DMA InternLink Mapper ===
Contributors: DMAdventure
Tags: internal links, seo, link audit, orphan pages, link suggestions
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find internal linking problems, discover link opportunities, review broken links, and understand how pages on your WordPress site connect.

== Description ==

DMA InternLink Mapper helps you understand and improve your site's internal linking.

Run a scan when you need one, then review incoming and outgoing links, orphan pages, weak anchors, broken links, SEO issues, and internal-link opportunities.

Interactive maps make it easier to see how content is connected across your site.

When you decide to add or repair a link, the plugin uses previews, permission checks, content validation, and optional WordPress revisions to help protect existing content.

= Features =

* Internal incoming and outgoing link reports.
* Orphan-page and weak-anchor detection.
* Internal-link opportunities based on your existing content.
* Safe link previews, insertion history, and undo support.
* Broken-link review and repair tools.
* 301 and 302 redirect management with loop protection.
* On-page SEO checks.
* Interactive Visual Map, Page Architecture, Site Architecture, and Knowledge Graph.
* Gutenberg and Classic Editor support.
* Safe Elementor text-content support.
* CSV and local PDF reports.
* Optional Google Search Console CSV/ZIP import.
* Optional internal and external HTTP status checks.
* Light and Dark admin appearance.
* No telemetry, advertising, remote AI, or cloud content analysis.

The SEO score provided by DMA InternLink Mapper is a local diagnostic score. It is not a Google ranking score and does not guarantee higher search rankings.

== Privacy and External Requests ==

Scan results and plugin data are stored in your WordPress database.

DMA InternLink Mapper does not send your site content or scan results to the plugin author, an AI service, advertising network, or remote analytics service.

During a scan, WordPress can request public pages on your own site so the plugin can analyze the HTML visitors and search engines receive.

Optional external link checking is disabled by default. If enabled by an administrator, the WordPress server connects directly to external URLs found in site content to check their HTTP status and redirects. Those destination servers may receive normal connection information such as the server IP address and request headers.

Google Search Console data can be imported manually from a CSV or ZIP export. The import is processed locally and does not connect to Google or use Google Site Kit credentials.

== Server Requirements ==

Safe HTML analysis and verified link insertion require the PHP DOM/XML extension.

== Installation ==

1. Install and activate DMA InternLink Mapper.
2. Open DMA InternLink Mapper > Settings and choose the public post types you want to scan.
3. Start a scan from the Dashboard.
4. Review your link reports, SEO issues, visual maps, orphan pages, and link opportunities.

== Frequently Asked Questions ==

= Does DMA InternLink Mapper send my content to an AI service? =

No. Content analysis and link matching are performed locally on your WordPress site.

= Does it scan automatically? =

Normal site scans start only when an authorized administrator requests one.

An optional bounded broken-link monitor can run through WP-Cron. It is disabled by default and processes only a small number of destinations per run.

= Does it work with Elementor? =

Yes. Supported saved Elementor text and WYSIWYG controls can be analyzed.

Automatic insertion is limited to supported body-text controls. Headings, buttons, URLs, code fields, Dynamic Tags, headers, heroes, CTAs, and footers are not automatically modified.

= Does it work with Yoast SEO or Rank Math? =

Yes. When available, supported focus-keyphrase metadata can be used as an additional relevance signal. DMA InternLink Mapper also works without either SEO plugin.

= Can it handle large websites? =

Scans are processed in batches to reduce server load.

Visualizations also use practical display limits to keep large graphs responsive. Large rendered scans can still require significant server resources, so conservative batch sizes are recommended.

= Can I delete the plugin's stored data? =

Yes. Administrators can delete stored history and indexed data. Full database cleanup on uninstall can also be enabled in the plugin settings.

== Documentation ==

Complete documentation:
https://desertmoroccoadventure.com/files/internal-link-seo-mapper/

Support:
https://wordpress.org/plugins/dma-internlink-mapper/

== Screenshots ==

1. Dashboard and scan controls.
2. Visual Map and Knowledge Graph.
3. Internal-link opportunities and insertion preview.
4. SEO issues and orphan-page reports.
5. Health Audit and database tools.

== Changelog ==

= 1.0.1 =

* Hardened internal URL classification for HTTP(S) links and explicit non-standard ports.
* Added regression tests and repository security/compatibility checks for development.
* Added GitHub CI, dependency update configuration, and consistent editor settings.
* Corrected release metadata and development hygiene without changing scan or insertion behavior.

= 1.0.0 =

* Initial release.
