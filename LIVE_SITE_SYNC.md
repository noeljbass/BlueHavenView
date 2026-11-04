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
