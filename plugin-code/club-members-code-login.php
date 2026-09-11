<?php
/**
 * Plugin Name: Club Members Code Login (Whitelist + Shared User)
 * Description: Inloggen voor leden via een e-mail code. Beperkt toegang tot inhoud én beschermde uploads voor gebruikers met een geverifieerd e-mailadres (whitelist), plus alle andere ingelogde gebruikers met minstens leesrechten.
 * Version: 1.1.1
 * Author: (Pieter-Bas IJdens)
 */

if (!defined('ABSPATH')) exit;

class Club_Members_Code_Login {
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

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('add_meta_boxes', [$this, 'register_page_protection_meta_box']);
        add_action('save_post_page', [$this, 'save_page_protection_meta_box']);
        add_action('admin_post_cmcl_upload_whitelist', [$this, 'handle_upload']);

        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields_to_edit'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'attachment_fields_to_save'], 10, 2);

        add_shortcode('club_members_only', [$this, 'club_members_only_shortcode']);

        add_action('template_redirect', [$this, 'maybe_serve_protected_file'], 0);
        add_action('template_redirect', [$this, 'maybe_redirect_to_signin'], 1);
        add_action('init', [$this, 'handle_signin_post'], 1);

        add_action('init', [$this, 'enforce_hard_session_timeout'], 2);
        add_action('admin_init', [$this, 'block_shared_user_admin_access'], 1);

        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        add_filter('image_downsize', [$this, 'filter_protected_image_downsize'], 10, 3);

        add_shortcode('club_members_signin', [$this, 'signin_shortcode']);
    }

    /* ------------------------- Activation / setup ------------------------- */

    public function on_activate() {
        $this->ensure_role();
        $this->ensure_shared_user();
        $this->ensure_signin_page();
        $this->ensure_protected_uploads_dir();
        if (get_option(self::OPT_WHITELIST, null) === null) {
            update_option(self::OPT_WHITELIST, []);
        }
        if (get_option(self::OPT_CODES, null) === null) {
            update_option(self::OPT_CODES, []);
        }
    }

    public function on_deactivate() {
        // Clear the cached sign-in page ID so stale references never survive reactivation.
        delete_option(self::OPT_PAGE_ID);
    }

    private function ensure_role() {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Club Members', [
                'read' => true,
            ]);
        }
    }

    private function ensure_shared_user() {
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

    private function ensure_signin_page() {
        $page_id = (int) get_option(self::OPT_PAGE_ID);
        if ($page_id) {
            $page = get_post($page_id);
            if ($this->is_valid_signin_page($page)) {
                return;
            }

            // Stored ID is stale or no longer points to the sign-in page.
            delete_option(self::OPT_PAGE_ID);
        }

        $existing = get_page_by_path('club-members-signin');
        if ($existing) {
            update_option(self::OPT_PAGE_ID, (int)$existing->ID);
            return;
        }

        $content = "[club_members_signin]";
        $pid = wp_insert_post([
            'post_title'   => 'Aanmelden voor leden',
            'post_name'    => 'club-members-signin',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => $content,
        ]);

        if (!is_wp_error($pid)) {
            update_option(self::OPT_PAGE_ID, (int)$pid);
        }
    }

    private function is_valid_signin_page($page) {
        if (!$page || is_wp_error($page)) return false;
        if ($page->post_type !== 'page') return false;

        $content = isset($page->post_content) ? (string) $page->post_content : '';
        return has_shortcode($content, 'club_members_signin');
    }

    /* ------------------------------ Admin UI ----------------------------- */

    public function admin_menu() {
        add_options_page(
            'Aanmelden voor leden',
            'Aanmelden voor leden',
            'manage_options',
            'cmcl',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;

        $count = count((array)get_option(self::OPT_WHITELIST, []));
        $page_id = (int)get_option(self::OPT_PAGE_ID);
        $page_url = $page_id ? get_permalink($page_id) : '';

        ?>
        <div class="wrap">
            <h1>Aanmelden voor leden (via e-mail)</h1>

            <h2>Upload ledenlijst</h2>
            <p><strong>Huidige grootte van de ledenlijst:</strong> <?php echo esc_html($count); ?> e-mail adressen</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('cmcl_upload_whitelist'); ?>
                <input type="hidden" name="action" value="cmcl_upload_whitelist" />
                <input type="file" name="whitelist_file" accept=".txt,.csv,text/plain,text/csv" required />
                <?php submit_button('Vervang ledenlijst'); ?>
                <p style="color:#666;">Formaat: één e-mail adres per regel (lege regels en regels die beginnen met # worden genegeerd).</p>
            </form>

            <h2>Aanmeldpagina</h2>
            <?php if ($page_url): ?>
                <p><strong>URL:</strong> <a href="<?php echo esc_url($page_url); ?>"><?php echo esc_html($page_url); ?></a></p>
            <?php else: ?>
                <p>Sign-in page not found. Deactivate/reactivate the plugin to recreate it.</p>
            <?php endif; ?>

            <h2>Hoe toegang beperken</h2>
            <p>Om toegang te beperken zet je deze shortcode om de inhoud heen die beperkt moet worden. Niet aangemelde gebruikers zien dan een aanmeldlink:</p>
            <pre><code>[club_members_only]
Inhoud die beschermd moet worden hier.
[/club_members_only]</code></pre>
            <p>Je kunt ook op een pagina de checkbox <strong>Alleen voor leden</strong> aanvinken om de volledige pagina af te schermen.</p>

            <h2>Beschermde downloads</h2>
            <p>Je kunt nu ook bestanden uit de mediabibliotheek afschermen. Open het bestand in de mediabibliotheek en vink <strong>Alleen voor leden (beveiligde download)</strong> aan.</p>
            <p>Het bestand wordt dan naar een afgeschermde map verplaatst en alleen nog via een leden-link aangeboden.</p>
        </div>
        <?php
    }

    public function register_page_protection_meta_box() {
        add_meta_box(
            'cmcl_page_protection',
            'Ledenpagina',
            [$this, 'render_page_protection_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    public function render_page_protection_meta_box($post) {
        wp_nonce_field('cmcl_save_page_protection', 'cmcl_page_protection_nonce');
        $is_protected = (bool) get_post_meta($post->ID, self::META_PROTECT, true);
        ?>
        <label for="cmcl_members_only">
            <input
                id="cmcl_members_only"
                name="cmcl_members_only"
                type="checkbox"
                value="1"
                <?php checked($is_protected); ?>
            />
            Alleen voor leden
        </label>
        <p style="color:#666;margin-top:8px;">Niet-aangemelde bezoekers worden doorgestuurd naar de aanmeldpagina.</p>
        <?php
    }

    public function save_page_protection_meta_box($post_id) {
        if (!isset($_POST['cmcl_page_protection_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cmcl_page_protection_nonce'])), 'cmcl_save_page_protection')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_page', $post_id)) return;

        if (isset($_POST['cmcl_members_only'])) {
            update_post_meta($post_id, self::META_PROTECT, '1');
        } else {
            delete_post_meta($post_id, self::META_PROTECT);
        }
    }

    /* ------------------------- Protected uploads ------------------------- */

    public function attachment_fields_to_edit($fields, $post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return $fields;
        }

        $is_protected = $this->attachment_is_protected($post->ID);
        $checked = $is_protected ? 'checked="checked"' : '';
        $help = 'Vink dit aan om het bestand alleen via de ledenlogin beschikbaar te maken.';

        if ($is_protected) {
            $help .= '<br/><code>' . esc_html($this->protected_download_url($post->ID)) . '</code>';
        }

        $fields['cmcl_members_only_file'] = [
            'label'        => 'Leden-download',
            'input'        => 'html',
            'html'         => '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][cmcl_members_only_file]" value="1" ' . $checked . ' /> Alleen voor leden (beveiligde download)</label>',
            'helps'        => $help,
            'show_in_edit' => true,
        ];

        return $fields;
    }

    public function attachment_fields_to_save($post, $attachment) {
        $attachment_id = isset($post['ID']) ? (int) $post['ID'] : 0;
        if (!$attachment_id || !current_user_can('edit_post', $attachment_id)) {
            return $post;
        }

        $should_protect = !empty($attachment['cmcl_members_only_file']);
        $this->set_attachment_protection($attachment_id, $should_protect);

        return $post;
    }

    private function attachment_is_protected($attachment_id) {
        return (bool) get_post_meta($attachment_id, self::META_ATTACHMENT_PROTECT, true);
    }

    private function set_attachment_protection($attachment_id, $should_protect) {
        $current_relative = ltrim((string) get_post_meta($attachment_id, '_wp_attached_file', true), '/');
        if ($current_relative === '') {
            if ($should_protect) {
                update_post_meta($attachment_id, self::META_ATTACHMENT_PROTECT, '1');
            } else {
                delete_post_meta($attachment_id, self::META_ATTACHMENT_PROTECT);
                delete_post_meta($attachment_id, self::META_PUBLIC_FILE);
            }
            return true;
        }

        if ($should_protect) {
            if (!$this->path_is_protected_relative($current_relative)) {
                update_post_meta($attachment_id, self::META_PUBLIC_FILE, $current_relative);
                $target_relative = $this->protected_relative_path($current_relative);
                if (!$this->relocate_attachment_files($attachment_id, $current_relative, $target_relative)) {
                    return false;
                }
            }

            update_post_meta($attachment_id, self::META_ATTACHMENT_PROTECT, '1');
            return true;
        }

        $target_relative = (string) get_post_meta($attachment_id, self::META_PUBLIC_FILE, true);
        if ($target_relative === '') {
            $target_relative = preg_replace('#^' . preg_quote(self::PROTECTED_DIR, '#') . '/#', '', $current_relative, 1);
        }

        if ($this->path_is_protected_relative($current_relative) && !$this->relocate_attachment_files($attachment_id, $current_relative, $target_relative)) {
            return false;
        }

        delete_post_meta($attachment_id, self::META_ATTACHMENT_PROTECT);
        delete_post_meta($attachment_id, self::META_PUBLIC_FILE);
        return true;
    }

    private function ensure_protected_uploads_dir() {
        $upload_dir = wp_get_upload_dir();
        if (empty($upload_dir['basedir'])) return;

        $dir = trailingslashit($upload_dir['basedir']) . self::PROTECTED_DIR;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $index_file = trailingslashit($dir) . 'index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
        }

        $htaccess_file = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
            @file_put_contents($htaccess_file, $rules);
        }
    }

    private function path_is_protected_relative($relative_path) {
        return strpos(ltrim((string) $relative_path, '/'), self::PROTECTED_DIR . '/') === 0;
    }

    private function protected_relative_path($relative_path) {
        $relative_path = ltrim((string) $relative_path, '/');
        if ($this->path_is_protected_relative($relative_path)) {
            return $relative_path;
        }
        return self::PROTECTED_DIR . '/' . $relative_path;
    }

    private function relocate_attachment_files($attachment_id, $from_relative, $to_relative) {
        $from_relative = ltrim((string) $from_relative, '/');
        $to_relative   = ltrim((string) $to_relative, '/');

        if ($from_relative === '' || $to_relative === '' || $from_relative === $to_relative) {
            return true;
        }

        $upload_dir = wp_get_upload_dir();
        if (empty($upload_dir['basedir'])) return false;

        $from_abs = trailingslashit($upload_dir['basedir']) . $from_relative;
        $to_abs   = trailingslashit($upload_dir['basedir']) . $to_relative;

        if (!file_exists($from_abs)) {
            return false;
        }

        $this->ensure_protected_uploads_dir();
        $this->ensure_directory(dirname($to_abs));

        if (!$this->move_single_file($from_abs, $to_abs)) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (is_array($metadata)) {
            $from_dir = dirname($from_abs);
            $to_dir   = dirname($to_abs);

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size) {
                    if (empty($size['file'])) continue;

                    $size_from = trailingslashit($from_dir) . $size['file'];
                    $size_to   = trailingslashit($to_dir) . $size['file'];

                    if (file_exists($size_from)) {
                        $this->ensure_directory(dirname($size_to));
                        $this->move_single_file($size_from, $size_to);
                    }
                }
            }

            $metadata['file'] = $to_relative;
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $backup_sizes = get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backup_sizes)) {
            $from_dir = dirname($from_abs);
            $to_dir   = dirname($to_abs);

            foreach ($backup_sizes as $backup) {
                if (empty($backup['file'])) continue;

                $backup_from = trailingslashit($from_dir) . $backup['file'];
                $backup_to   = trailingslashit($to_dir) . $backup['file'];

                if (file_exists($backup_from)) {
                    $this->ensure_directory(dirname($backup_to));
                    $this->move_single_file($backup_from, $backup_to);
                }
            }
        }

        update_attached_file($attachment_id, $to_abs);
        return true;
    }

    private function move_single_file($from, $to) {
        if ($from === $to) return true;
        if (!file_exists($from)) return false;

        $this->ensure_directory(dirname($to));

        if (@rename($from, $to)) {
            return true;
        }

        if (@copy($from, $to)) {
            return @unlink($from);
        }

        return false;
    }

    private function ensure_directory($dir) {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    public function filter_attachment_url($url, $attachment_id) {
        if ($this->attachment_is_protected($attachment_id)) {
            return $this->protected_download_url($attachment_id);
        }

        return $url;
    }

    public function filter_protected_image_downsize($downsize, $attachment_id, $size) {
        if (!$this->attachment_is_protected($attachment_id)) {
            return $downsize;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        $width = isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = isset($meta['height']) ? (int) $meta['height'] : 0;

        return [$this->protected_download_url($attachment_id), $width, $height, false];
    }

    private function protected_download_url($attachment_id) {
        return add_query_arg(self::DOWNLOAD_QUERY, (int) $attachment_id, home_url('/'));
    }

    public function maybe_serve_protected_file() {
        if (!isset($_GET[self::DOWNLOAD_QUERY])) return;

        $attachment_id = absint(wp_unslash($_GET[self::DOWNLOAD_QUERY]));
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            $this->file_not_found();
        }

        if (!$this->attachment_is_protected($attachment_id)) {
            remove_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10);
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                wp_safe_redirect($url);
                exit;
            }
            $this->file_not_found();
        }

        if (!$this->current_user_is_club_member()) {
            wp_safe_redirect($this->signin_url_with_return());
            exit;
        }

        $file_path = get_attached_file($attachment_id, true);
        if (!$file_path || !is_readable($file_path) || !file_exists($file_path)) {
            $this->file_not_found();
        }

        $this->stream_protected_file($file_path, $attachment_id);
    }

    private function stream_protected_file($file_path, $attachment_id) {
        $mime_type = get_post_mime_type($attachment_id);
        $filename = wp_basename($file_path);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: ' . ($mime_type ?: 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($file_path));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Transfer-Encoding: binary');

        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            $this->file_not_found();
        }

        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }

        fclose($handle);
        exit;
    }

    private function file_not_found() {
        status_header(404);
        nocache_headers();
        exit('Bestand niet gevonden.');
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
            $email = $this->normalize_email($line);
            if ($email && is_email($email)) $emails[$email] = true;
        }

        update_option(self::OPT_WHITELIST, array_keys($emails), false);

        // Also prune expired codes now
        $this->prune_expired_codes();

        wp_safe_redirect(remove_query_arg(['cmcl'], wp_get_referer()));
        exit;
    }

    /* -------------------------- Content restriction ---------------------- */

    public function club_members_only_shortcode($atts, $inner = '') {
        if ($this->current_user_is_club_member()) {
            return do_shortcode($inner);
        }
        $url = $this->signin_url_with_return();
        return '<p><a href="'.esc_url($url).'">Deze inhoud is alleen beschikbaar voor leden. Meld je aan om verder te gaan.</a></p>';
    }

    public function maybe_redirect_to_signin() {
        if (is_admin() || !is_singular('page')) return;
        if ($this->current_user_is_club_member()) return;

        $page_id = (int) get_option(self::OPT_PAGE_ID);
        $current_id = get_queried_object_id();
        if ($page_id && $current_id === $page_id) return;

        if (!get_post_meta($current_id, self::META_PROTECT, true)) return;

        wp_safe_redirect($this->signin_url_with_return());
        exit;
    }

    private function current_user_is_club_member() {
        $u = wp_get_current_user();

        // Legacy helper name: access is allowed for the shared club member user
        // and for any other logged-in user with at least Reader-level access.
        return $u && $u->exists() && user_can($u, 'read');
    }

    private function signin_url_with_return() {
        $page_id = (int) get_option(self::OPT_PAGE_ID);
        $url = $page_id ? get_permalink($page_id) : site_url('/club-members-signin/');
        $return = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        return add_query_arg('cmcl_return', rawurlencode($return), $url);
    }

    /* ----------------------------- Sign-in flow -------------------------- */

    public function signin_shortcode() {
        $state = [
            'step' => 'email',
            'email' => '',
            'msg' => '',
            'err' => '',
            'expires_in' => 0,
        ];

        // Get the return URL from query string or cookie
        $return_url = '';
        if (isset($_GET['cmcl_return'])) {
            $return_url = sanitize_text_field(wp_unslash($_GET['cmcl_return']));
        } elseif (isset($_COOKIE['cmcl_return_url'])) {
            $return_url = sanitize_text_field(wp_unslash($_COOKIE['cmcl_return_url']));
        }

        // Use transient-like state via POST/redirect? Here we render from query args or postback.
        if (isset($_GET['cmcl_msg'])) $state['msg'] = sanitize_text_field(wp_unslash($_GET['cmcl_msg']));
        if (isset($_GET['cmcl_err'])) $state['err'] = sanitize_text_field(wp_unslash($_GET['cmcl_err']));

        // If we have a pending email stored in a short cookie (for UX), show code step.
        $pending_email = isset($_COOKIE['cmcl_pending_email']) ? $this->normalize_email(wp_unslash($_COOKIE['cmcl_pending_email'])) : '';
        $pending_exp   = isset($_COOKIE['cmcl_pending_exp']) ? (int)$_COOKIE['cmcl_pending_exp'] : 0;

        if ($pending_email && $pending_exp > time()) {
            $state['step'] = 'code';
            $state['email'] = $pending_email;
            $state['expires_in'] = max(0, $pending_exp - time());
        } else {
            $this->clear_pending_cookies();
        }

        ob_start();
        ?>
        <div class="cmcl-wrap" style="max-width:420px;">
            <?php if ($state['msg']): ?>
                <div class="cmcl-msg" style="padding:10px;background:#e7f7e7;border:1px solid #b7e0b7;margin-bottom:10px;">
                    <?php echo esc_html($state['msg']); ?>
                </div>
            <?php endif; ?>
            <?php if ($state['err']): ?>
                <div class="cmcl-err" style="padding:10px;background:#fdeaea;border:1px solid #f0b4b4;margin-bottom:10px;">
                    <?php echo esc_html($state['err']); ?>
                </div>
            <?php endif; ?>

            <?php if ($state['step'] === 'email'): ?>
                <form method="post">
                    <?php wp_nonce_field('cmcl_send_code'); ?>
                    <input type="hidden" name="cmcl_action" value="send_code" />
                    <?php if ($return_url): ?>
                        <input type="hidden" name="cmcl_return" value="<?php echo esc_attr($return_url); ?>" />
                    <?php endif; ?>
                    <p>
                        <label for="cmcl_email"><strong>Email adres</strong></label><br/>
                        <input id="cmcl_email" name="cmcl_email" type="email" required style="width:100%;padding:8px;" autocomplete="email"/>
                    </p>
                    <p>
                        <button type="submit" style="padding:10px 12px;">Stuur me een code</button>
                    </p>
                </form>

            <?php else: ?>
                <form method="post" id="cmcl_code_form">
                    <?php wp_nonce_field('cmcl_verify_code'); ?>
                    <input type="hidden" name="cmcl_action" value="verify_code" />

                    <p>
                        <label><strong>Email adres</strong></label><br/>
                        <input type="email" value="<?php echo esc_attr($state['email']); ?>" disabled style="width:100%;padding:8px;background:#f3f3f3;"/>
                        <input type="hidden" name="cmcl_email" value="<?php echo esc_attr($state['email']); ?>"/>
                    </p>

                    <p>
                        <label for="cmcl_code"><strong>6-cijferige code</strong></label><br/>
                        <input id="cmcl_code" name="cmcl_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required style="width:100%;padding:8px;" autocomplete="one-time-code"/>
                    </p>

                    <p>
                        <button type="submit" style="padding:10px 12px;">Aanmelden</button>
                        <button type="button" id="cmcl_restart" style="padding:10px 12px;margin-left:8px;">Opnieuw starten</button>
                    </p>

                    <?php if ($return_url): ?>
                        <input type="hidden" name="cmcl_return" value="<?php echo esc_attr($return_url); ?>" />
                    <?php endif; ?>

                    <p style="color:#666;">
                        Code verloopt over <span id="cmcl_timer"><?php echo (int)$state['expires_in']; ?></span> seconden.
                    </p>
                </form>

                <script>
                (function(){
                    var remaining = <?php echo (int)$state['expires_in']; ?>;
                    var timerEl = document.getElementById('cmcl_timer');
                    var restartBtn = document.getElementById('cmcl_restart');

                    function tick(){
                        remaining = Math.max(0, remaining - 1);
                        if (timerEl) timerEl.textContent = remaining;
                        if (remaining <= 0) {
                            // expire UX: clear cookies server-side via a hit
                            window.location.href = window.location.href.split('?')[0] + '?cmcl_restart=1';
                            return;
                        }
                        setTimeout(tick, 1000);
                    }
                    setTimeout(tick, 1000);

                    if (restartBtn) {
                        restartBtn.addEventListener('click', function(){
                            window.location.href = window.location.href.split('?')[0] + '?cmcl_restart=1';
                        });
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_signin_post() {
        // Restart requested
        if (isset($_GET['cmcl_restart'])) {
            $this->clear_pending_cookies();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (empty($_POST['cmcl_action'])) return;

        $action = sanitize_text_field(wp_unslash($_POST['cmcl_action']));

        if ($action === 'send_code') {
            $this->handle_send_code();
            exit;
        }
        if ($action === 'verify_code') {
            $this->handle_verify_code();
            exit;
        }
    }

    private function handle_send_code() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cmcl_send_code')) {
            $this->redirect_with_err('Invalid request.');
        }

        $email = isset($_POST['cmcl_email']) ? $this->normalize_email(wp_unslash($_POST['cmcl_email'])) : '';
        if (!$email || !is_email($email)) {
            $this->redirect_with_err('Voer een valide e-mail adres in.');
        }

        if (!$this->email_is_whitelisted($email)) {
            $this->redirect_with_err('Je hebt geen toegang met dit adres.');
        }

        // Preserve the return URL in a cookie
        $return = isset($_REQUEST['cmcl_return']) ? sanitize_text_field(wp_unslash($_REQUEST['cmcl_return'])) : '';
        if ($return) {
            setcookie('cmcl_return_url', $return, [
                'expires'  => time() + 3600,
                'path'     => COOKIEPATH ?: '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        // Prune timeouts before adding new code
        $this->prune_expired_codes();

        // With 900,000 possible 6-digit codes and at most ~200 active codes at a
        // time, collision probability is < 0.03%. The previous approach called
        // wp_check_password() (bcrypt, ~100ms) for every stored code per candidate,
        // causing multi-second delays and 500 gateway timeouts under normal load.
        $codes = (array) get_option(self::OPT_CODES, []);
        $code = (string) random_int(100000, 999999);

        // Store code for email
        $exp = time() + self::CODE_TTL;
        $codes[$email] = [
            'hash' => wp_hash_password($code),
            'exp'  => $exp,
        ];
        update_option(self::OPT_CODES, $codes, false);

        // Email it
        $subject = 'Jouw aanmeldcode voor AHV Centaur';
        $message = "Jouw aanmeldcode is: {$code}\n\nDeze code verloopt over 10 minuten.";
        wp_mail($email, $subject, $message);

        // Set pending cookies for UX (10 minutes)
        setcookie('cmcl_pending_email', $email, [
            'expires'  => $exp,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        setcookie('cmcl_pending_exp', (string)$exp, [
            'expires'  => $exp,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        $this->redirect_with_msg('Code is verstuurd. Controleer je e-mail.');
    }

    private function handle_verify_code() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'cmcl_verify_code')) {
            $this->redirect_with_err('Ongeldig verzoek.');
        }

        $email = isset($_POST['cmcl_email']) ? $this->normalize_email(wp_unslash($_POST['cmcl_email'])) : '';
        $code  = isset($_POST['cmcl_code']) ? preg_replace('/\D+/', '', wp_unslash($_POST['cmcl_code'])) : '';

        if (!$email || !is_email($email) || strlen($code) !== 6) {
            $this->redirect_with_err('Ongeldig e-mail adres of code.');
        }

        if (!$this->email_is_whitelisted($email)) {
            $this->redirect_with_err('Je hebt geen toegang met dit adres.');
        }

        $this->prune_expired_codes();
        $codes = (array) get_option(self::OPT_CODES, []);
        if (empty($codes[$email]['hash']) || empty($codes[$email]['exp']) || (int)$codes[$email]['exp'] <= time()) {
            $this->redirect_with_err('Deze code is verlopen, probeer het nog een keer.');
        }

        if (!wp_check_password($code, $codes[$email]['hash'])) {
            $this->redirect_with_err('Ongeldige code.');
        }

        // Success: consume code
        unset($codes[$email]);
        update_option(self::OPT_CODES, $codes, false);
        $this->clear_pending_cookies();

        // Log in as the shared user
        $this->ensure_role();
        $this->ensure_shared_user();

        $user = get_user_by('login', self::SHARED_USER);
        if (!$user) {
            $this->redirect_with_err('Gedeelde gebruiker ontbreekt. Neem contact op met de sitebeheerder.');
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());

        // Mark session start (server + client) to enforce hard 24h timeout.
        $now = time();
        update_user_meta($user->ID, self::OPT_LOGIN_TS, $now);

        setcookie(self::COOKIE_NAME, (string)$now, [
            'expires'  => $now + self::SESSION_TTL,
            'path'     => COOKIEPATH ?: '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $return = '';
        if (isset($_GET['cmcl_return'])) {
            $return = rawurldecode(sanitize_text_field(wp_unslash($_GET['cmcl_return'])));
        } elseif (isset($_REQUEST['cmcl_return'])) {
            $return = rawurldecode(sanitize_text_field(wp_unslash($_REQUEST['cmcl_return'])));
        }

        if ($return && wp_http_validate_url($return)) {
            wp_safe_redirect($return);
        } else {
            wp_safe_redirect(home_url('/'));
        }
        exit;
    }

    private function redirect_with_msg($msg) {
        $url = $this->signin_page_url();
        wp_safe_redirect(add_query_arg('cmcl_msg', rawurlencode($msg), $url));
        exit;
    }

    private function redirect_with_err($err) {
        $url = $this->signin_page_url();
        wp_safe_redirect(add_query_arg('cmcl_err', rawurlencode($err), $url));
        exit;
    }

    private function signin_page_url() {
        $page_id = (int) get_option(self::OPT_PAGE_ID);
        if ($page_id) {
            $page = get_post($page_id);
            if ($this->is_valid_signin_page($page)) {
                return get_permalink($page_id);
            }

            delete_option(self::OPT_PAGE_ID);
        }

        $this->ensure_signin_page();
        $page_id = (int) get_option(self::OPT_PAGE_ID);

        return $page_id ? get_permalink($page_id) : site_url('/club-members-signin/');
    }

    private function clear_pending_cookies() {
        foreach (['cmcl_pending_email','cmcl_pending_exp','cmcl_return_url'] as $c) {
            if (isset($_COOKIE[$c])) {
                setcookie($c, '', [
                    'expires'  => time() - 3600,
                    'path'     => COOKIEPATH ?: '/',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                unset($_COOKIE[$c]);
            }
        }
    }

    /* ----------------------- Code storage housekeeping -------------------- */

    private function prune_expired_codes() {
        $codes = (array) get_option(self::OPT_CODES, []);
        $now = time();
        $changed = false;
        foreach ($codes as $email => $rec) {
            $exp = isset($rec['exp']) ? (int)$rec['exp'] : 0;
            if ($exp <= $now) {
                unset($codes[$email]);
                $changed = true;
            }
        }
        if ($changed) update_option(self::OPT_CODES, $codes, false);
    }

    /* --------------------------- Whitelist logic -------------------------- */

    private function email_is_whitelisted($email) {
        $wl = (array) get_option(self::OPT_WHITELIST, []);
        $email = $this->normalize_email($email);
        return in_array($email, array_map([$this,'normalize_email'], $wl), true);
    }

    private function normalize_email($email) {
        return strtolower(trim($email));
    }

    /* ----------------------- 24h hard session timeout --------------------- */

    public function enforce_hard_session_timeout() {
        if (!is_user_logged_in()) return;

        $u = wp_get_current_user();
        if (!$u || !$u->exists()) return;
        if ($u->user_login !== self::SHARED_USER) return;

        $start = isset($_COOKIE[self::COOKIE_NAME]) ? (int)$_COOKIE[self::COOKIE_NAME] : 0;
        if ($start <= 0) {
            // No start cookie means we can't guarantee hard expiry; force re-login.
            wp_logout();
            return;
        }

        if (time() > ($start + self::SESSION_TTL)) {
            wp_logout();
            // Clear cookie
            setcookie(self::COOKIE_NAME, '', [
                'expires'  => time() - 3600,
                'path'     => COOKIEPATH ?: '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[self::COOKIE_NAME]);
        }
    }

    /* -------------------- Block shared user from wp-admin -------------- */

    public function block_shared_user_admin_access() {
        $u = wp_get_current_user();
        if (!$u || !$u->exists()) return;

        // If current user is the shared club_member user, redirect them away from admin
        if ($u->user_login === self::SHARED_USER) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
    }
}

new Club_Members_Code_Login();
