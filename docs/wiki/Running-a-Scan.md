# Running a Scan

A scan builds the local picture that DMA InternLink Mapper uses for reports, maps and link opportunities.

## What the scan does

The plugin reads the selected public content types and records how pages connect through links. From that data it can show incoming links, outgoing links, orphan pages, weak anchors, redirects and other SEO-related signals.

The scan is local to your WordPress installation. The plugin does not send your site content or scan results to a remote AI service or analytics platform.

## Starting a scan

Open the DMA InternLink Mapper dashboard and choose **Rescan** to begin a fresh manual scan.

The dashboard shows progress while the scanner works through content in batches. Batching is intentional. It reduces the chance that a large site turns one SEO scan into a small denial-of-service attack against its own database.

## Pause, resume and cancel

Use the dashboard controls when available to pause, resume or cancel a scan.

If you cancel a scan, review the status shown by the plugin before immediately starting another one. Completed reports remain the useful reference point until a new scan completes.

## Large sites

DMA InternLink Mapper processes scans in batches and applies practical limits to visualizations so the admin remains usable on larger sites.

Large rendered scans can still use meaningful CPU, memory and database resources. Conservative batch settings are the sensible choice on shared hosting or busy production sites.

## What to review after completion

A useful order is:

1. Orphan pages
2. Broken internal links
3. Weak anchors
4. Important pages with poor incoming-link support
5. Link opportunities
6. Visual architecture

The numbers are there to help you investigate. They are not a substitute for knowing which pages matter to the site.

## Automatic monitoring

Normal site scans run only when an authorized administrator starts them.

The plugin also has an optional bounded broken-link monitor that can run through WP-Cron. It is disabled by default and checks only a small number of destinations per run.

For details, see [Broken Links and Redirects](Broken-Links-and-Redirects.md).
