<?php
if (!defined('ABSPATH')) exit;

/**
 * Protected media library downloads: moving files into a denied uploads
 * subfolder and serving them only to club members via a gated URL.
 */
class CMCL_Attachment_Protection {

    public function __construct() {
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields_to_edit'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'attachment_fields_to_save'], 10, 2);
        add_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10, 2);
        add_filter('image_downsize', [$this, 'filter_protected_image_downsize'], 10, 3);
        add_action('template_redirect', [$this, 'maybe_serve_protected_file'], 0);
    }

    public function attachment_fields_to_edit($fields, $post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return $fields;
        }

        $is_protected = $this->is_protected($post->ID);
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
        $this->set_protection($attachment_id, $should_protect);

        return $post;
    }

    private function is_protected($attachment_id) {
        return (bool) get_post_meta($attachment_id, CMCL_Core::META_ATTACHMENT_PROTECT, true);
    }

    private function set_protection($attachment_id, $should_protect) {
        $current_relative = ltrim((string) get_post_meta($attachment_id, '_wp_attached_file', true), '/');
        if ($current_relative === '') {
            if ($should_protect) {
                update_post_meta($attachment_id, CMCL_Core::META_ATTACHMENT_PROTECT, '1');
            } else {
                delete_post_meta($attachment_id, CMCL_Core::META_ATTACHMENT_PROTECT);
                delete_post_meta($attachment_id, CMCL_Core::META_PUBLIC_FILE);
            }
            return true;
        }

        if ($should_protect) {
            if (!$this->path_is_protected_relative($current_relative)) {
                update_post_meta($attachment_id, CMCL_Core::META_PUBLIC_FILE, $current_relative);
                $target_relative = $this->protected_relative_path($current_relative);
                if (!$this->relocate_attachment_files($attachment_id, $current_relative, $target_relative)) {
                    return false;
                }
            }

            update_post_meta($attachment_id, CMCL_Core::META_ATTACHMENT_PROTECT, '1');
            return true;
        }

        $target_relative = (string) get_post_meta($attachment_id, CMCL_Core::META_PUBLIC_FILE, true);
        if ($target_relative === '') {
            $target_relative = preg_replace('#^' . preg_quote(CMCL_Core::PROTECTED_DIR, '#') . '/#', '', $current_relative, 1);
        }

        if ($this->path_is_protected_relative($current_relative) && !$this->relocate_attachment_files($attachment_id, $current_relative, $target_relative)) {
            return false;
        }

        delete_post_meta($attachment_id, CMCL_Core::META_ATTACHMENT_PROTECT);
        delete_post_meta($attachment_id, CMCL_Core::META_PUBLIC_FILE);
        return true;
    }

    public static function ensure_protected_uploads_dir() {
        $upload_dir = wp_get_upload_dir();
        if (empty($upload_dir['basedir'])) return;

        $dir = trailingslashit($upload_dir['basedir']) . CMCL_Core::PROTECTED_DIR;
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
        return strpos(ltrim((string) $relative_path, '/'), CMCL_Core::PROTECTED_DIR . '/') === 0;
    }

    private function protected_relative_path($relative_path) {
        $relative_path = ltrim((string) $relative_path, '/');
        if ($this->path_is_protected_relative($relative_path)) {
            return $relative_path;
        }
        return CMCL_Core::PROTECTED_DIR . '/' . $relative_path;
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

        self::ensure_protected_uploads_dir();
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
        if ($this->is_protected($attachment_id)) {
            return $this->protected_download_url($attachment_id);
        }

        return $url;
    }

    public function filter_protected_image_downsize($downsize, $attachment_id, $size) {
        if (!$this->is_protected($attachment_id)) {
            return $downsize;
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        $width = isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = isset($meta['height']) ? (int) $meta['height'] : 0;

        return [$this->protected_download_url($attachment_id), $width, $height, false];
    }

    private function protected_download_url($attachment_id) {
        return add_query_arg(CMCL_Core::DOWNLOAD_QUERY, (int) $attachment_id, home_url('/'));
    }

    public function maybe_serve_protected_file() {
        if (!isset($_GET[CMCL_Core::DOWNLOAD_QUERY])) return;

        $attachment_id = absint(wp_unslash($_GET[CMCL_Core::DOWNLOAD_QUERY]));
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            $this->file_not_found();
        }

        if (!$this->is_protected($attachment_id)) {
            remove_filter('wp_get_attachment_url', [$this, 'filter_attachment_url'], 10);
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                wp_safe_redirect($url);
                exit;
            }
            $this->file_not_found();
        }

        if (!CMCL_Core::current_user_is_club_member()) {
            wp_safe_redirect(CMCL_Signin_Page::url_with_return());
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
}
