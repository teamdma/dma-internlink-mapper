# Getting Started

DMA InternLink Mapper is designed to be useful without making you babysit it all day. You choose what should be scanned, start a scan, and review the results when it finishes.

## 1. Install and activate the plugin

Install DMA InternLink Mapper through WordPress and activate it like any other plugin.

After activation, open the plugin settings before your first scan.

## 2. Choose what to scan

In **DMA InternLink Mapper → Settings**, choose the public post types you want included in scans.

For most sites this means posts and pages. If your site uses public custom post types, such as tours, products or other editorial content, you can include those too.

Only include content that actually matters to your internal-link structure. Scanning every object simply because it exists is a very human way to create more data and less clarity.

## 3. Run your first scan

Open the plugin dashboard and start a manual scan.

The scanner works in batches to reduce database and CPU load. On a larger site, completion can take some time. You can monitor the progress from the dashboard.

A new scan does not need to destroy the usefulness of previous completed results while it is running. Review the current interface messages before cancelling or restarting a long scan.

## 4. Review the reports

After the scan finishes, start with the dashboard and work outward:

- Internal links
- Incoming links
- Outgoing links
- Orphan pages
- Weak anchors
- Broken links
- SEO issues
- Link opportunities
- Visual Map and Knowledge Graph

You do not need to fix everything at once. A good first pass is usually orphan pages, obviously weak anchors, broken internal links and pages that should have stronger internal connections.

## 5. Generate link opportunities

Link opportunities are generated from the latest completed scan.

The plugin compares existing content and checks whether a suggested link is technically safe to insert. Suggestions still deserve human review. Relevance is a content decision, not merely a score.

See [Link Opportunities](Link-Opportunities.md) for the full workflow.

## 6. Back up before large changes

The plugin includes previews, validation, insertion history and optional WordPress revisions to reduce risk, but normal WordPress maintenance still applies.

Before a large batch of content changes, make sure your normal site backup process is healthy.

## Next steps

- [Running a Scan](Running-a-Scan.md)
- [Link Opportunities](Link-Opportunities.md)
- [Visual Maps](Visual-Maps.md)
