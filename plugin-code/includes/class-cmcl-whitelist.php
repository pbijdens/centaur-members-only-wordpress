<?php
if (!defined('ABSPATH')) exit;

/**
 * Whitelist storage (which emails may request a login code) and the admin
 * upload handler that replaces it wholesale.
 */
class CMCL_Whitelist {

    public function __construct() {
        add_action('admin_post_cmcl_upload_whitelist', [$this, 'handle_upload']);
    }

    public function handle_upload() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('cmcl_upload_whitelist');

        if (empty($_FILES['whitelist_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('cmcl', 'no_file', wp_get_referer()));
            exit;
        }

        $raw = file_get_contents($_FILES['whitelist_file']['tmp_name']);
        if ($raw === false) {
            wp_safe_redirect(add_query_arg('cmcl', 'read_error', wp_get_referer()));
            exit;
        }

        $emails = [];
        foreach (preg_split("/\R/u", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#') continue;
            $email = self::normalize_email($line);
            if ($email && is_email($email)) $emails[$email] = true;
        }

        update_option(CMCL_Core::OPT_WHITELIST, array_keys($emails), false);

        // Also prune expired codes now
        CMCL_Auth::prune_expired_codes();

        wp_safe_redirect(remove_query_arg(['cmcl'], wp_get_referer()));
        exit;
    }

    public static function is_whitelisted($email) {
        $wl = (array) get_option(CMCL_Core::OPT_WHITELIST, []);
        $email = self::normalize_email($email);
        return in_array($email, array_map([__CLASS__, 'normalize_email'], $wl), true);
    }

    public static function normalize_email($email) {
        return strtolower(trim($email));
    }

    public static function count() {
        return count((array) get_option(CMCL_Core::OPT_WHITELIST, []));
    }
}
