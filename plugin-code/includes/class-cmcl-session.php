<?php
if (!defined('ABSPATH')) exit;

/**
 * Hard 24h session cap for the shared club member account, and keeping that
 * account out of wp-admin.
 */
class CMCL_Session {

    public function __construct() {
        add_action('init', [$this, 'enforce_hard_session_timeout'], 2);
        add_action('admin_init', [$this, 'block_shared_user_admin_access'], 1);
    }

    public function enforce_hard_session_timeout() {
        if (!is_user_logged_in()) return;

        $u = wp_get_current_user();
        if (!$u || !$u->exists()) return;
        if ($u->user_login !== CMCL_Core::SHARED_USER) return;

        $start = isset($_COOKIE[CMCL_Core::COOKIE_NAME]) ? (int)$_COOKIE[CMCL_Core::COOKIE_NAME] : 0;
        if ($start <= 0) {
            // No start cookie means we can't guarantee hard expiry; force re-login.
            wp_logout();
            return;
        }

        if (time() > ($start + CMCL_Core::SESSION_TTL)) {
            wp_logout();
            // Clear cookie
            setcookie(CMCL_Core::COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => COOKIEPATH ?: '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[CMCL_Core::COOKIE_NAME]);
        }
    }

    public function block_shared_user_admin_access() {
        $u = wp_get_current_user();
        if (!$u || !$u->exists()) return;

        // If current user is the shared club_member user, redirect them away from admin
        if ($u->user_login === CMCL_Core::SHARED_USER) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }
}
