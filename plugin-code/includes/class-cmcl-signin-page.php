<?php
if (!defined('ABSPATH')) exit;

/**
 * Creation, self-healing and URL resolution for the sign-in page that hosts
 * the [club_members_signin] shortcode.
 */
class CMCL_Signin_Page {

    public static function ensure() {
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
        if ($page_id) {
            $page = get_post($page_id);
            if (self::is_valid($page)) {
                return;
            }

            // Stored ID is stale or no longer points to the sign-in page.
            delete_option(CMCL_Core::OPT_PAGE_ID);
        }

        $existing = get_page_by_path('club-members-signin');
        if ($existing) {
            update_option(CMCL_Core::OPT_PAGE_ID, (int) $existing->ID);
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
            update_option(CMCL_Core::OPT_PAGE_ID, (int) $pid);
        }
    }

    public static function is_valid($page) {
        if (!$page || is_wp_error($page)) return false;
        if ($page->post_type !== 'page') return false;

        $content = isset($page->post_content) ? (string) $page->post_content : '';
        return has_shortcode($content, 'club_members_signin');
    }

    /**
     * Self-healing resolver: re-creates the sign-in page if the stored ID is
     * stale. Used for the message/error redirects during the code flow.
     */
    public static function url() {
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
        if ($page_id) {
            $page = get_post($page_id);
            if (self::is_valid($page)) {
                return get_permalink($page_id);
            }

            delete_option(CMCL_Core::OPT_PAGE_ID);
        }

        self::ensure();
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);

        return $page_id ? get_permalink($page_id) : site_url('/club-members-signin/');
    }

    /**
     * Plain lookup (no self-heal) used to build "come back here after
     * signing in" links from arbitrary protected pages/content/downloads.
     */
    public static function url_with_return() {
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
        $url = $page_id ? get_permalink($page_id) : site_url('/club-members-signin/');
        $return = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        return add_query_arg('cmcl_return', rawurlencode($return), $url);
    }
}
