<?php
if (!defined('ABSPATH')) exit;

/**
 * Whitelist storage (which emails may request a login code), the admin
 * upload handler that replaces it wholesale, and per-entry add/edit/delete
 * management from the settings screen.
 *
 * Stored as an associative array [normalized_email => is_protected (bool)].
 * Protected entries survive a bulk "replace the list" upload even when
 * they're absent from the uploaded file; they must be explicitly
 * unprotected before they can be removed (by upload or by the delete
 * button in the admin UI).
 */
class CMCL_Whitelist {

    public function __construct() {
        add_action('admin_post_cmcl_upload_whitelist', [$this, 'handle_upload']);
        add_action('admin_post_cmcl_add_whitelist_entry', [$this, 'handle_add_entry']);
        add_action('admin_post_cmcl_manage_whitelist_entry', [$this, 'handle_manage_entry']);
    }

    /* ------------------------------ Bulk upload ---------------------------- */

    public function handle_upload() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('cmcl_upload_whitelist');

        if (empty($_FILES['whitelist_file']['tmp_name'])) {
            $this->redirect_with_err('Geen bestand geselecteerd.');
        }

        $raw = file_get_contents($_FILES['whitelist_file']['tmp_name']);
        if ($raw === false) {
            $this->redirect_with_err('Kon het bestand niet lezen.');
        }

        $emails = [];
        foreach (preg_split("/\R/u", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || substr($line, 0, 1) === '#') continue;
            $email = self::normalize_email($line);
            if ($email && is_email($email)) $emails[] = $email;
        }

        $result = self::replace_from_upload($emails);

        // Also prune expired codes now
        CMCL_Auth::prune_expired_codes();

        $this->redirect_with_msg(sprintf(
            'Ledenlijst vervangen: %d e-mailadres(sen) totaal (%d beschermde adressen behouden).',
            $result['total'],
            $result['protected_kept']
        ));
    }

    /* --------------------------- Single-entry admin ------------------------ */

    public function handle_add_entry() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('cmcl_add_whitelist_entry');

        $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
        $protected = !empty($_POST['protected']);

        $result = self::add_entry($email, $protected);
        if (is_wp_error($result)) {
            $this->redirect_with_err($result->get_error_message());
        }

        $this->redirect_with_msg('E-mailadres toegevoegd.');
    }

    public function handle_manage_entry() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('cmcl_manage_whitelist_entry');

        $original_email = isset($_POST['original_email']) ? sanitize_text_field(wp_unslash($_POST['original_email'])) : '';
        $row_action = isset($_POST['cmcl_row_action']) ? sanitize_text_field(wp_unslash($_POST['cmcl_row_action'])) : '';

        if ($row_action === 'delete') {
            $result = self::delete_entry($original_email);
            if (is_wp_error($result)) {
                $this->redirect_with_err($result->get_error_message());
            }
            $this->redirect_with_msg('E-mailadres verwijderd.');
        }

        if ($row_action === 'save') {
            $email = isset($_POST['email']) ? sanitize_text_field(wp_unslash($_POST['email'])) : '';
            $protected = !empty($_POST['protected']);

            $result = self::update_entry($original_email, $email, $protected);
            if (is_wp_error($result)) {
                $this->redirect_with_err($result->get_error_message());
            }
            $this->redirect_with_msg('E-mailadres bijgewerkt.');
        }

        $this->redirect_with_err('Onbekende actie.');
    }

    private function redirect_with_msg($msg) {
        wp_safe_redirect(add_query_arg('cmcl_msg', rawurlencode($msg), $this->settings_url()));
        exit;
    }

    private function redirect_with_err($err) {
        wp_safe_redirect(add_query_arg('cmcl_err', rawurlencode($err), $this->settings_url()));
        exit;
    }

    private function settings_url() {
        return remove_query_arg(['cmcl_msg', 'cmcl_err'], admin_url('options-general.php?page=cmcl'));
    }

    /* ------------------------------ Storage --------------------------------- */

    /**
     * Normalized [email => is_protected] map. Self-heals the legacy storage
     * format (a plain sequential list of email strings, from before
     * per-entry protection existed) into the current associative shape —
     * without writing anything back until the next actual mutation.
     */
    public static function get_entries() {
        $raw = get_option(CMCL_Core::OPT_WHITELIST, []);
        if (!is_array($raw)) return [];

        $entries = [];

        if (array_values($raw) === $raw) {
            // Legacy format: sequential array of email strings, none protected.
            foreach ($raw as $email) {
                $email = self::normalize_email($email);
                if ($email) $entries[$email] = false;
            }
            return $entries;
        }

        foreach ($raw as $email => $protected) {
            $email = self::normalize_email($email);
            if ($email) $entries[$email] = (bool) $protected;
        }
        return $entries;
    }

    private static function save_entries(array $entries) {
        ksort($entries);
        update_option(CMCL_Core::OPT_WHITELIST, $entries, false);
    }

    public static function is_whitelisted($email) {
        $entries = self::get_entries();
        return isset($entries[self::normalize_email($email)]);
    }

    public static function is_protected($email) {
        $entries = self::get_entries();
        $email = self::normalize_email($email);
        return isset($entries[$email]) && $entries[$email];
    }

    public static function normalize_email($email) {
        return strtolower(trim($email));
    }

    public static function count() {
        return count(self::get_entries());
    }

    /**
     * Replaces the whole list from an uploaded file's emails, except
     * protected entries are always kept (and never downgraded to
     * unprotected, even if re-uploaded).
     */
    public static function replace_from_upload(array $uploaded_emails) {
        $current = self::get_entries();
        $new = array_filter($current, static function ($protected) {
            return $protected;
        });
        $protected_kept = count($new);

        foreach ($uploaded_emails as $email) {
            $email = self::normalize_email($email);
            if (!$email || !is_email($email)) continue;
            if (!isset($new[$email])) {
                $new[$email] = false;
            }
        }

        self::save_entries($new);

        return [
            'total'          => count($new),
            'protected_kept' => $protected_kept,
        ];
    }

    public static function add_entry($email, $protected = false) {
        $email = self::normalize_email($email);
        if (!$email || !is_email($email)) {
            return new WP_Error('cmcl_invalid_email', 'Voer een geldig e-mailadres in.');
        }

        $entries = self::get_entries();
        if (isset($entries[$email])) {
            return new WP_Error('cmcl_duplicate_email', 'Dit e-mailadres staat al in de lijst.');
        }

        $entries[$email] = (bool) $protected;
        self::save_entries($entries);
        return true;
    }

    public static function update_entry($original_email, $new_email, $protected) {
        $original_email = self::normalize_email($original_email);
        $new_email = self::normalize_email($new_email);

        if (!$new_email || !is_email($new_email)) {
            return new WP_Error('cmcl_invalid_email', 'Voer een geldig e-mailadres in.');
        }

        $entries = self::get_entries();
        if (!isset($entries[$original_email])) {
            return new WP_Error('cmcl_not_found', 'Dit e-mailadres bestaat niet meer in de lijst.');
        }

        if ($new_email !== $original_email && isset($entries[$new_email])) {
            return new WP_Error('cmcl_duplicate_email', 'Dit e-mailadres staat al in de lijst.');
        }

        unset($entries[$original_email]);
        $entries[$new_email] = (bool) $protected;
        self::save_entries($entries);
        return true;
    }

    public static function delete_entry($email) {
        $email = self::normalize_email($email);
        $entries = self::get_entries();

        if (!isset($entries[$email])) {
            return new WP_Error('cmcl_not_found', 'Dit e-mailadres bestaat niet meer in de lijst.');
        }

        if ($entries[$email]) {
            return new WP_Error('cmcl_protected', 'Dit e-mailadres is beschermd. Hef eerst de bescherming op voordat je het verwijdert.');
        }

        unset($entries[$email]);
        self::save_entries($entries);
        return true;
    }
}
