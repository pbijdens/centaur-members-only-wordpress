# Club Members Code Login

A WordPress plugin that gives an email-whitelisted group of people passwordless access to member-only pages and downloads — without creating a real WordPress account for each of them.

Everyone who signs in shares one read-only WordPress account behind the scenes; access is controlled purely by whether their email address is on the whitelist. The plugin's admin screen is in Dutch ("Aanmelden voor leden"), matching the site it's built for.

## Getting started (site admin)

1. **Install the plugin.** Copy the `plugin-code/` folder into `wp-content/plugins/`, rename it if you like, and activate **Club Members Code Login (Whitelist + Shared User)** from the Plugins screen.
   - On activation, the plugin automatically creates a sign-in page (slug `club-members-signin`) containing the `[club_members_signin]` shortcode. You don't need to create this yourself.
2. **Add members to the whitelist.** Go to **Settings → Aanmelden voor leden**. You have three ways to manage the list:
   - **Add one address** using the small form above the table.
   - **Bulk upload** a `.txt`, `.csv`, `.xls`, or `.xlsx` file. Every email-shaped string anywhere in the file is picked up, however it's separated (one per line, comma-separated, multiple per cell, etc.) — you don't need to format it precisely. This replaces the whitelist, but any address marked **Beschermd** (protected) is always kept, even if it's missing from the new file.
   - **Edit or delete** individual rows directly in the table. A protected address must be unprotected first before it can be deleted — this is a safety net against accidentally wiping an address you want to keep.
3. **Restrict a page.** Open any Page in the block editor and tick the **Alleen voor leden** checkbox to gate the whole page behind sign-in.
4. **Restrict part of a page or post.** Wrap the content with the shortcode:
   ```
   [club_members_only]
   Content that only members should see.
   [/club_members_only]
   ```
   Visitors who aren't signed in see a sign-in link instead of the wrapped content.
5. **Restrict a file download.** Open a file in the Media Library and tick **Alleen voor leden (beveiligde download)**. The file is moved out of the public uploads folder and only served to signed-in members, via a gated URL.
6. **Share the sign-in link** (or just link to a members-only page — visitors are redirected to sign-in automatically and returned afterward). The sign-in page URL is also shown on the settings screen.

That's it — no further configuration is needed for typical use.

## How it works for members (end-user flow)

1. A member visits the sign-in page (or a members-only page/download and gets redirected there) and enters their email address.
2. If that address is on the whitelist, a 6-digit code is emailed to them. It's valid for **10 minutes**.
3. They enter the code on the same page to complete sign-in.
4. Once signed in, they can browse any members-only pages and downloads on the site. Their session automatically expires after **24 hours**, after which they need to request a new code to sign in again.

There's no password to remember or account to manage — just an email address and a fresh code each time a session expires.

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer
- The PHP `zip` extension, only needed if you plan to bulk-upload a genuine `.xlsx` whitelist file (plain text/CSV/HTML-flavored uploads work without it)
- Outgoing email (`wp_mail()`) configured and working on the site, since sign-in codes are delivered by email

## Good to know / current limitations

- Everyone shares one WordPress account behind the scenes, so WordPress itself keeps no record of which member did what.
- There's no rate limit on requesting sign-in codes — repeatedly submitting the same whitelisted address will repeatedly email it.
- Page-level protection (the checkbox) only works on Pages, not on Posts or other content types. Use the `[club_members_only]` shortcode for those.

See [CLAUDE.md](CLAUDE.md) for full technical/architecture documentation.
