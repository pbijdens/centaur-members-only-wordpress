# Club Members Code Login

WordPress plugin (single file: [plugin-code/club-members-code-login.php](plugin-code/club-members-code-login.php), class `Club_Members_Code_Login`) that gives an email-whitelisted group of people passwordless access to member-only pages and downloads, without giving them real WordPress accounts.

No build step, no composer/npm, no tests. It's a plain classic WP plugin — edit the file directly and it takes effect on page load.

## Core idea: everyone shares one WordPress user

There is no per-person WP account. Every verified member is logged into the **same** shared account:

- Login: `club_member` (constant `SHARED_USER`)
- Role: `club_members` (constant `ROLE`) — capabilities: `read` only. All other roles are explicitly stripped from this user on every activation/login (`ensure_shared_user()`), so it can never accumulate edit/comment/admin rights even if something else tries to grant them.
- `block_shared_user_admin_access()` hard-redirects this user out of `/wp-admin` on every `admin_init`.

Because everyone is the same WP user, **there is no per-visitor audit trail inside WordPress** — actions are indistinguishable between members. This is intentional per the plugin's purpose (read-only content gating), not an oversight.

"Club member" access (`current_user_is_club_member()`) is actually defined as *any* logged-in user with at least `read` capability — not literally "is the shared user". A real WP subscriber/author/editor/admin also counts as a club member for gating purposes. This is called out in the code as a "legacy helper name".

## Passwordless login flow (email code)

1. Visitor submits email via the `[club_members_signin]` shortcode form → `handle_send_code()`.
2. Email must be present in the whitelist (`cmcl_whitelist_emails` option) or the request is rejected with a distinct error message. **Note:** because the "not whitelisted" and "code sent" responses differ, this flow lets someone enumerate whitelisted addresses by trial and error — accepted tradeoff for a small club roster, but worth remembering if the whitelist is ever sensitive.
3. A random 6-digit code is generated, bcrypt-hashed via `wp_hash_password()`, and stored in the `cmcl_active_codes` option keyed by email with a 10-minute expiry (`CODE_TTL`). Options `cmcl_whitelist_emails` / `cmcl_active_codes` are saved with `autoload = false` deliberately (they change often and shouldn't bloat the autoloaded options cache).
4. Code is emailed via `wp_mail()`. There is **no rate limiting** on code requests — a whitelisted email address can be used to trigger repeated emails (mail-bombing that inbox). No lockout/backoff exists today.
5. Visitor submits the code → `handle_verify_code()` compares via `wp_check_password()` (constant-time), consumes (deletes) the code on success, then logs them into the shared `club_member` account with `wp_set_current_user()` + `wp_set_auth_cookie()`.
6. A **separate hard 24h session cap** (`SESSION_TTL`, cookie `cmcl_session_start`) is layered on top of WP's own auth cookie expiry: `enforce_hard_session_timeout()` runs on `init` and force-logs-out the shared user once 24h have elapsed since their code verification, or if the tracking cookie is missing entirely (fail closed → forces re-login rather than trusting a cookie-less session).

Pending-login UX state (which email is mid-verification, when it expires) is tracked client-side via non-httponly cookies (`cmcl_pending_email`, `cmcl_pending_exp`, `cmcl_return_url`) — these are just UX convenience, not security boundaries; the real check happens server-side against `cmcl_active_codes`.

## Access gating

Two independent protection mechanisms, both gated on `current_user_is_club_member()`:

- **Whole page**: a "Alleen voor leden" checkbox meta box on the Page editor (`_cmcl_members_only` post meta). Enforced in `maybe_redirect_to_signin()` on `template_redirect`. **Only applies to post type `page`** (`is_singular('page')`) — posts/CPTs are not covered by this mechanism.
- **Partial content**: `[club_members_only]...[/club_members_only]` shortcode — renders inner content only for members, otherwise a login prompt link.

Both redirect to the configured sign-in page, preserving the original URL via `cmcl_return` query arg (validated with `wp_http_validate_url()` before ever redirecting back to it after login).

The sign-in page itself is auto-created on activation (slug `club-members-signin`, containing `[club_members_signin]`) and self-heals: `ensure_signin_page()` / `signin_page_url()` re-detect or recreate it if the stored `cmcl_signin_page_id` option goes stale (page deleted, or shortcode removed from it).

## Protected downloads (media library attachments)

Toggling "Alleen voor leden (beveiligde download)" on an attachment:

- Physically **moves** the file (and all registered image size variants + backup sizes) from its normal uploads path into `uploads/cmcl-protected/…`, preserving the relative subpath. `uploads/cmcl-protected/` itself is `.htaccess`-denied (`Require all denied`) so it's unreachable by direct URL even if guessed.
- Un-protecting moves it back to its original relative path (remembered in `_cmcl_public_attached_file` meta).
- `wp_get_attachment_url` and `image_downsize` are filtered so any protected attachment always resolves to a gated download URL (`?cmcl_download=<id>`) instead of its real path.
- `maybe_serve_protected_file()` (hooked at `template_redirect` priority 0, before the page/shortcode gating at priority 1) streams the file through PHP with `readfile`-style chunking after checking membership — non-members are redirected to sign-in instead of getting the file.
- File moves use `rename()` first, falling back to `copy()` + `unlink()` (needed across filesystems/wrappers where `rename()` can't cross device boundaries).

## Whitelist management

Settings → "Aanmelden voor leden" admin page (`manage_options` capability only): upload a `.txt`/`.csv` file, one email per line, blank lines and `#`-prefixed lines ignored. **This fully replaces** the existing whitelist (`update_option`, not merge) — there is no incremental add/remove UI, no dedupe-with-warning, no history of what changed.

## Key constants (all on the plugin class)

| Constant | Purpose |
|---|---|
| `ROLE` / `SHARED_USER` | `club_members` role vs `club_member` login — note the trailing "s" difference, easy to typo |
| `META_PROTECT` | page-level members-only flag |
| `META_ATTACHMENT_PROTECT` / `META_PUBLIC_FILE` | attachment protection state + remembered original path |
| `OPT_WHITELIST` / `OPT_CODES` / `OPT_PAGE_ID` | the three persisted options |
| `CODE_TTL` (600s) / `SESSION_TTL` (86400s) | code validity vs hard session cap |
| `PROTECTED_DIR` | `cmcl-protected`, subfolder under uploads |

## Known gaps / things to keep in mind before "fixing"

These appear to be accepted tradeoffs for a small-club use case rather than bugs — confirm intent with the user before "fixing" them:

- No rate limiting on code-send requests (mail-bombing a whitelisted inbox is possible).
- Whitelist membership is distinguishable from the error message on send/verify (minor enumeration).
- Page protection covers only `post_type = page`, not posts or custom post types.
- Whitelist upload always replaces the full list; no partial add/remove.
- Everyone shares one WP identity, so there's no per-member action audit trail by design.
