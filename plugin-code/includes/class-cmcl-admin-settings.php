<?php
if (!defined('ABSPATH')) exit;

/**
 * Settings > "Aanmelden voor leden" admin screen: whitelist upload form and
 * the usage instructions.
 */
class CMCL_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
    }

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

        $count = CMCL_Whitelist::count();
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
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
}
