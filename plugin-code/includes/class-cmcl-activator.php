<?php
if (!defined('ABSPATH')) exit;

/**
 * One-shot setup/teardown run on plugin activation and deactivation.
 */
class CMCL_Activator {

    public static function activate() {
        CMCL_Core::ensure_role();
        CMCL_Core::ensure_shared_user();
        CMCL_Signin_Page::ensure();
        CMCL_Attachment_Protection::ensure_protected_uploads_dir();

        if (get_option(CMCL_Core::OPT_WHITELIST, null) === null) {
            update_option(CMCL_Core::OPT_WHITELIST, []);
        }
        if (get_option(CMCL_Core::OPT_CODES, null) === null) {
            update_option(CMCL_Core::OPT_CODES, []);
        }
    }

    public static function deactivate() {
        // Clear the cached sign-in page ID so stale references never survive reactivation.
        delete_option(CMCL_Core::OPT_PAGE_ID);
    }
}
