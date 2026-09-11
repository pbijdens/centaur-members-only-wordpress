<?php
if (!defined('ABSPATH')) exit;

/**
 * Page-level ("Alleen voor leden" checkbox) and shortcode-level
 * ([club_members_only]) content gating.
 */
class CMCL_Page_Protection {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post_page', [$this, 'save_meta_box']);
        add_shortcode('club_members_only', [$this, 'members_only_shortcode']);
        add_action('template_redirect', [$this, 'maybe_redirect_to_signin'], 1);
    }

    public function register_meta_box() {
        add_meta_box(
            'cmcl_page_protection',
            'Ledenpagina',
            [$this, 'render_meta_box'],
            'page',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('cmcl_save_page_protection', 'cmcl_page_protection_nonce');
        $is_protected = (bool) get_post_meta($post->ID, CMCL_Core::META_PROTECT, true);
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

    public function save_meta_box($post_id) {
        if (!isset($_POST['cmcl_page_protection_nonce'])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cmcl_page_protection_nonce'])), 'cmcl_save_page_protection')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_page', $post_id)) return;

        if (isset($_POST['cmcl_members_only'])) {
            update_post_meta($post_id, CMCL_Core::META_PROTECT, '1');
        } else {
            delete_post_meta($post_id, CMCL_Core::META_PROTECT);
        }
    }

    public function members_only_shortcode($atts, $inner = '') {
        if (CMCL_Core::current_user_is_club_member()) {
            return do_shortcode($inner);
        }
        $url = CMCL_Signin_Page::url_with_return();
        return '<p><a href="'.esc_url($url).'">Deze inhoud is alleen beschikbaar voor leden. Meld je aan om verder te gaan.</a></p>';
    }

    public function maybe_redirect_to_signin() {
        if (is_admin() || !is_singular('page')) return;
        if (CMCL_Core::current_user_is_club_member()) return;

        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
        $current_id = get_queried_object_id();
        if ($page_id && $current_id === $page_id) return;

        if (!get_post_meta($current_id, CMCL_Core::META_PROTECT, true)) return;

        wp_safe_redirect(CMCL_Signin_Page::url_with_return());
        exit;
    }
}
