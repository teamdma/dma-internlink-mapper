# Privacy and Security

DMA InternLink Mapper is designed to keep its core analysis inside WordPress.

That matters because an internal-link audit can reveal a surprising amount about a site: page structure, unpublished-looking paths, content relationships and editorial priorities. Sending all of that somewhere else by default would be a strange design choice.

## Local analysis

Scan results and plugin data are stored in your WordPress database.

The plugin does not send your site content or scan results to:

- The plugin author
- A remote AI service
- An advertising network
- A remote analytics platform

## Requests made during scanning

During a scan, WordPress can request public pages on your own site so the plugin can analyze the HTML that visitors and search engines receive.

This helps the scanner work from rendered public output rather than assuming stored editor content always matches the final page.

## External link checking

External HTTP status checking is optional and disabled by default.

If an administrator enables it, your WordPress server connects directly to external URLs found in site content. Those destination servers may receive normal connection information such as the server IP address and request headers.

## Google Search Console imports

Search Console data is imported manually from CSV or ZIP exports.

The import is processed locally. DMA InternLink Mapper does not connect to Google for this feature and does not use Google Site Kit credentials.

## Administrative actions

Actions that modify content or managed redirects are protected by WordPress permissions and request verification.

The plugin also uses previews, current-content validation, insertion history and optional WordPress revisions to reduce the risk of accidental changes.

## Remote URL safety

Broken-link checks use WordPress safe HTTP handling and validate destination URLs before requesting them. Requests are deliberately bounded with practical limits for redirects, response size and timeout.

## Data removal

Administrators can remove stored history and indexed data from the plugin.

Full database cleanup on uninstall can also be enabled in the plugin settings.

## Responsible security reports

If you believe you have found a security problem, avoid publishing exploit details in a public issue before the maintainers have had a reasonable chance to review it.

See the repository `SECURITY.md` file for the current reporting guidance.
