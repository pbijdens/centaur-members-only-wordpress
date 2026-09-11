<?php
if (!defined('ABSPATH')) exit;

/**
 * Shared constants and small cross-cutting helpers used by multiple
 * components (role/shared-user provisioning, membership check).
 */
class CMCL_Core {
    const ROLE                    = 'club_members';
    const SHARED_USER             = 'club_member';
    const META_PROTECT            = '_cmcl_members_only';
    const META_ATTACHMENT_PROTECT = '_cmcl_protected_attachment';
    const META_PUBLIC_FILE        = '_cmcl_public_attached_file';
    const OPT_WHITELIST           = 'cmcl_whitelist_emails';     // array of normalized emails
    const OPT_CODES               = 'cmcl_active_codes';         // array: [email => ['hash'=>..., 'exp'=>ts]]
    const OPT_PAGE_ID             = 'cmcl_signin_page_id';
    const OPT_LOGIN_TS            = 'cmcl_login_ts';             // user meta on shared user
    const CODE_TTL                = 600;                         // 10 minutes
    const SESSION_TTL             = 86400;                       // 24 hours
    const COOKIE_NAME             = 'cmcl_session_start';        // tracks start time (client-side) for hard expiry too
    const DOWNLOAD_QUERY          = 'cmcl_download';
    const PROTECTED_DIR           = 'cmcl-protected';

    public static function ensure_role() {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Club Members', [
                'read' => true,
            ]);
        }
    }

    public static function ensure_shared_user() {
        $user = get_user_by('login', self::SHARED_USER);
        if (!$user) {
            $email = 'club_member@invalid.local';
            $pass  = wp_generate_password(32, true, true);
            $uid = wp_create_user(self::SHARED_USER, $pass, $email);
            if (!is_wp_error($uid)) {
                $user = get_user_by('id', $uid);
            }
        }
        if ($user && !is_wp_error($user)) {
            $user->add_role(self::ROLE);
            $user->remove_role('subscriber');
            $user->remove_role('contributor');
            $user->remove_role('author');
            $user->remove_role('editor');
            $user->remove_role('administrator');
        }
    }

    /**
     * Legacy helper name: access is allowed for the shared club member user
     * and for any other logged-in user with at least Reader-level access.
     */
    public static function current_user_is_club_member() {
        $u = wp_get_current_user();
        return $u && $u->exists() && user_can($u, 'read');
    }
}
