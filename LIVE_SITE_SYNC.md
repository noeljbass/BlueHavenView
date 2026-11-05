# Live site snapshot

Synced from the IONOS `BlueHavenView-PreLaunch` document root for
https://bluehavenview.com/ on November 4, 2026.

The 128 included hosted files were copied without content changes and their
SHA-256 hashes were verified against the server before committing. All 14
included PHP files passed PHP 8.4 syntax checks on IONOS. This records the
deployed source; it does not certify every live feature's behavior.

Includes deployed pages, assets, contact/CRM integration code, consent code,
and the hosted cookie-consent preview source. Existing repository documentation
and the MailerLite config example are preserved.

Intentionally excluded from this public repository:

- SSH authorization files and private CRM, MailerLite, security, delivery-test,
  and preview configuration.
- Customer lead CSVs, consent receipts, preview session storage, and logs.
- Dated editor backups and temporary deployment upload copies.

Private configuration and runtime records remain on the host. Deployments must
preserve those files; do not use a destructive mirror/delete operation against
the document root. The `.gitignore` protects these exclusions in future work.

This synchronization only reads IONOS files; it makes no hosting changes.

## November 5, 2026 copy deployment

Published updates to `index.html`, `window-replacement.html`,
`window-installation.html`, and `sitemap.xml` on IONOS before committing
this repository update. The homepage now introduces the family-owned business
and directs replacement enquiries to the dedicated Franklin replacement page.
The replacement page includes residential, home, and whole-house replacement
guidance; the installation page focuses on process, preparation, and workmanship.
Page metadata, WebPage structured data, and the three sitemap modification dates
were updated to match.

All 128 previously mirrored hosted files matched the repository baseline before
publication. After publication, SHA-256 checks confirmed all 128 hosted files
matched the updated local repository. Public HTTPS responses for all four
published files returned HTTP 200 and matched their local bytes. Original copies
of the four changed hosted files were backed up locally. Private configuration
and runtime files were preserved.

Validation covered HTML tag nesting, one H1 per page, JSON-LD parsing, internal
HTML links and fragments, unchanged forms and canonical URLs, and git whitespace
checks. No form submissions or search ranking measurements were performed.
