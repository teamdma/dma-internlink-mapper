# Broken Links and Redirects

Broken links are easy to ignore until enough of them pile up and turn normal navigation into a scavenger hunt.

DMA InternLink Mapper includes tools for reviewing broken destinations and, when appropriate, repairing or redirecting them.

## Broken-link checking

The plugin can inspect destinations found in the latest scan and record their HTTP status.

External destination checking is optional and disabled by default.

When external checking is enabled, your WordPress server connects directly to those URLs. The remote server can therefore receive normal connection information such as your server IP address and request headers.

## Bounded monitoring

The optional broken-link monitor runs through WP-Cron and checks only a small number of unique destinations per run.

This is intentional. Link monitoring should be useful without quietly turning WordPress into a crawler that spends the day hammering other servers and itself.

## Reviewing a broken destination

Before changing anything, confirm that the destination is actually wrong.

A failed request can sometimes be temporary, blocked, rate-limited or affected by the remote server. For important links, open the destination manually and confirm what a visitor would experience.

## Repair options

Depending on the case, you may choose to:

- Replace the destination with a valid same-site URL.
- Unlink the broken destination while keeping the surrounding text.
- Create a reviewed 301 or 302 redirect for a same-site path.

Replacement and redirect actions should be used carefully. One broken URL should not cause unrelated destinations to be rewritten together.

## Redirect safety

Managed redirects are limited to same-site URLs and use protected-path checks.

The plugin avoids creating redirects for sensitive WordPress routes such as admin, login, REST and XML-RPC endpoints. It also checks for loops and excessive redirect chains.

### 301 or 302?

Use **301 Permanent** when the old URL has genuinely moved and the change is intended to remain.

Use **302 Temporary** when the change is temporary and the old destination may return later.

When in doubt, do not create a redirect simply because the button exists. Redirects become architecture, and architecture has a habit of outliving the person who created it.

## Deleting a managed redirect

Managed redirects can be removed from the plugin interface by an authorized administrator.

Deleting a redirect restores the old URL behavior immediately.
