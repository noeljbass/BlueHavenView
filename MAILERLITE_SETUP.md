# MailerLite Setup

Website form submissions can now create or update MailerLite subscribers when the visitor leaves the promotional list checkbox enabled.

## Add the API token

Use one of these two options:

1. Set an environment variable named `MAILERLITE_API_KEY` on the web server.
2. Copy `data/mailerlite-config.example.php` to `data/mailerlite-config.php` and paste the token there.

`data/mailerlite-config.php` is ignored by git so the live API token is not committed.

## Optional groups

To segment subscribers, create groups in MailerLite and add their IDs as either environment variables or values in `data/mailerlite-config.php`:

- `MAILERLITE_GUIDE_GROUP_ID` for free guide requests.
- `MAILERLITE_CONTACT_GROUP_ID` for quote/contact forms.
- `MAILERLITE_GROUP_ID` as an optional fallback group for all website subscribers.

If no group IDs are set, the site still creates or updates subscribers in MailerLite.

## Import existing CSV leads

Run this from the site root after adding the API token:

```bash
php scripts/import-mailerlite-leads.php
```

By default, older CSV rows without a `marketing_consent` column are skipped. If you have confirmed those older leads gave permission to receive promotional emails, temporarily set `MAILERLITE_IMPORT_ASSUME_CONSENT=yes` before running the import.
