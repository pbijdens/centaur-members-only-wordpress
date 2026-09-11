# Club Members Code Login

WordPress plugin that gives an email-whitelisted group of people passwordless access to member-only pages and downloads, without giving them real WordPress accounts.

No build step, no composer/npm, no tests. It's a plain classic WP plugin (no namespaces, no autoloader) — edit files directly and it takes effect on page load.

## File layout

[plugin-code/club-members-code-login.php](plugin-code/club-members-code-login.php) is a thin bootstrap: plugin header, `require_once` for every file in `includes/`, registers the activation/deactivation hooks, then instantiates `CMCL_Plugin`. All actual logic lives in `plugin-code/includes/`, one class per concern:

| File | Class | Responsibility |
|---|---|---|
| `class-cmcl-core.php` | `CMCL_Core` | All shared constants, plus `ensure_role()`/`ensure_shared_user()`/`current_user_is_club_member()` — cross-cutting helpers several other classes call |
| `class-cmcl-activator.php` | `CMCL_Activator` | `activate()`/`deactivate()`, bound directly via `register_activation_hook`/`register_deactivation_hook` in the bootstrap (static methods, not instantiated) |
| `class-cmcl-whitelist.php` | `CMCL_Whitelist` | Whitelist option storage, email normalization, the `.txt`/`.csv` upload handler |
| `class-cmcl-signin-page.php` | `CMCL_Signin_Page` | Creates/self-heals the sign-in page, resolves its URL |
| `class-cmcl-auth.php` | `CMCL_Auth` | The `[club_members_signin]` shortcode + send-code/verify-code POST handlers + code option housekeeping |
| `class-cmcl-session.php` | `CMCL_Session` | Hard 24h session cap + blocking the shared user from `/wp-admin` |
| `class-cmcl-page-protection.php` | `CMCL_Page_Protection` | Page meta box + `[club_members_only]` shortcode + page-level redirect gating |
| `class-cmcl-attachment-protection.php` | `CMCL_Attachment_Protection` | Protected media library downloads (file relocation, URL filters, streaming) |
| `class-cmcl-admin-settings.php` | `CMCL_Admin_Settings` | Settings → "Aanmelden voor leden" screen |
| `class-cmcl-plugin.php` | `CMCL_Plugin` | Orchestrator — just `new`s up every component with a constructor that registers its own hooks |

Components talk to each other via public static methods (e.g. `CMCL_Whitelist::is_whitelisted()`, `CMCL_Signin_Page::url_with_return()`, `CMCL_Core::current_user_is_club_member()`) rather than dependency injection — consistent with the rest of the plugin's style. `CMCL_Core` is the one class almost everything else depends on; if you rename a constant, grep for `CMCL_Core::` across `includes/`.

## Core idea: everyone shares one WordPress user

There is no per-person WP account. Every verified member is logged into the **same** shared account:

- Login: `club_member` (`CMCL_Core::SHARED_USER`)
- Role: `club_members` (`CMCL_Core::ROLE`) — capabilities: `read` only. All other roles are explicitly stripped from this user on every activation/login (`CMCL_Core::ensure_shared_user()`), so it can never accumulate edit/comment/admin rights even if something else tries to grant them.
- `CMCL_Session::block_shared_user_admin_access()` hard-redirects this user out of `/wp-admin` on every `admin_init`.

Because everyone is the same WP user, **there is no per-visitor audit trail inside WordPress** — actions are indistinguishable between members. This is intentional per the plugin's purpose (read-only content gating), not an oversight.

"Club member" access (`CMCL_Core::current_user_is_club_member()`) is actually defined as *any* logged-in user with at least `read` capability — not literally "is the shared user". A real WP subscriber/author/editor/admin also counts as a club member for gating purposes. This is called out in the code as a "legacy helper name".

## Passwordless login flow (email code)

1. Visitor submits email via the `[club_members_signin]` shortcode form → `CMCL_Auth::handle_send_code()`.
2. Email must be present in the whitelist (`CMCL_Whitelist::is_whitelisted()`, `cmcl_whitelist_emails` option) or the request is rejected with a distinct error message. **Note:** because the "not whitelisted" and "code sent" responses differ, this flow lets someone enumerate whitelisted addresses by trial and error — accepted tradeoff for a small club roster, but worth remembering if the whitelist is ever sensitive.
3. A random 6-digit code is generated, bcrypt-hashed via `wp_hash_password()`, and stored in the `cmcl_active_codes` option keyed by email with a 10-minute expiry (`CMCL_Core::CODE_TTL`). Options `cmcl_whitelist_emails` / `cmcl_active_codes` are saved with `autoload = false` deliberately (they change often and shouldn't bloat the autoloaded options cache).
4. Code is emailed via `wp_mail()`. There is **no rate limiting** on code requests — a whitelisted email address can be used to trigger repeated emails (mail-bombing that inbox). No lockout/backoff exists today.
5. Visitor submits the code → `CMCL_Auth::handle_verify_code()` compares via `wp_check_password()` (constant-time), consumes (deletes) the code on success, then logs them into the shared `club_member` account with `wp_set_current_user()` + `wp_set_auth_cookie()`.
6. A **separate hard 24h session cap** (`CMCL_Core::SESSION_TTL`, cookie `cmcl_session_start`) is layered on top of WP's own auth cookie expiry: `CMCL_Session::enforce_hard_session_timeout()` runs on `init` and force-logs-out the shared user once 24h have elapsed since their code verification, or if the tracking cookie is missing entirely (fail closed → forces re-login rather than trusting a cookie-less session).

Pending-login UX state (which email is mid-verification, when it expires) is tracked client-side via non-httponly cookies (`cmcl_pending_email`, `cmcl_pending_exp`, `cmcl_return_url`) — these are just UX convenience, not security boundaries; the real check happens server-side against `cmcl_active_codes`.

## Access gating

Two independent protection mechanisms, both gated on `CMCL_Core::current_user_is_club_member()`:

- **Whole page** (`CMCL_Page_Protection`): a "Alleen voor leden" checkbox meta box on the Page editor (`_cmcl_members_only` post meta). Enforced in `maybe_redirect_to_signin()` on `template_redirect`. **Only applies to post type `page`** (`is_singular('page')`) — posts/CPTs are not covered by this mechanism.
- **Partial content** (`CMCL_Page_Protection::members_only_shortcode()`): `[club_members_only]...[/club_members_only]` shortcode — renders inner content only for members, otherwise a login prompt link.

Both redirect to the configured sign-in page, preserving the original URL via `cmcl_return` query arg (validated with `wp_http_validate_url()` before ever redirecting back to it after login).

The sign-in page itself is auto-created on activation (slug `club-members-signin`, containing `[club_members_signin]`) and self-heals via `CMCL_Signin_Page`: `ensure()` / `url()` re-detect or recreate it if the stored `cmcl_signin_page_id` option goes stale (page deleted, or shortcode removed from it). `CMCL_Signin_Page` has two URL methods that look similar but aren't interchangeable — `url()` self-heals (used for the code-flow message/error redirects), `url_with_return()` does a plain lookup with no self-heal (used everywhere a "come back here after login" link is built).

## Protected downloads (media library attachments)

Handled by `CMCL_Attachment_Protection`. Toggling "Alleen voor leden (beveiligde download)" on an attachment:

- Physically **moves** the file (and all registered image size variants + backup sizes) from its normal uploads path into `uploads/cmcl-protected/…`, preserving the relative subpath. `uploads/cmcl-protected/` itself is `.htaccess`-denied (`Require all denied`) so it's unreachable by direct URL even if guessed.
- Un-protecting moves it back to its original relative path (remembered in `_cmcl_public_attached_file` meta).
- `wp_get_attachment_url` and `image_downsize` are filtered so any protected attachment always resolves to a gated download URL (`?cmcl_download=<id>`) instead of its real path.
- `maybe_serve_protected_file()` (hooked at `template_redirect` priority 0, before the page/shortcode gating at priority 1) streams the file through PHP with `readfile`-style chunking after checking membership — non-members are redirected to sign-in instead of getting the file.
- File moves use `rename()` first, falling back to `copy()` + `unlink()` (needed across filesystems/wrappers where `rename()` can't cross device boundaries).

## Whitelist management

Settings → "Aanmelden voor leden" admin page (`manage_options` capability only): upload a `.txt`/`.csv` file, one email per line, blank lines and `#`-prefixed lines ignored. **This fully replaces** the existing whitelist (`update_option`, not merge) — there is no incremental add/remove UI, no dedupe-with-warning, no history of what changed.

## Key constants (all on `CMCL_Core`)

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
