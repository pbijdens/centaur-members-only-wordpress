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

        $entries = CMCL_Whitelist::get_entries();
        $page_id = (int) get_option(CMCL_Core::OPT_PAGE_ID);
        $page_url = $page_id ? get_permalink($page_id) : '';

        ?>
        <div class="wrap">
            <h1>Aanmelden voor leden (via e-mail)</h1>

            <?php $this->render_notices(); ?>

            <h2>Ledenlijst</h2>
            <p><strong>Huidige grootte van de ledenlijst:</strong> <?php echo esc_html(count($entries)); ?> e-mail adressen</p>
            <p style="color:#666;">Beschermde adressen kunnen niet per ongeluk verwijderd worden: ze blijven altijd staan bij het uploaden van een nieuwe lijst, en moeten eerst ontgrendeld worden voordat je ze kunt verwijderen.</p>

            <?php $this->render_whitelist_table($entries); ?>
            <?php $this->render_add_entry_form(); ?>

            <h2>Upload ledenlijst</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('cmcl_upload_whitelist'); ?>
                <input type="hidden" name="action" value="cmcl_upload_whitelist" />
                <input type="file" name="whitelist_file" accept=".txt,.csv,text/plain,text/csv" required />
                <?php submit_button('Vervang ledenlijst', 'secondary'); ?>
                <p style="color:#666;">Formaat: één e-mail adres per regel (lege regels en regels die beginnen met # worden genegeerd). Beschermde adressen (zie hieronder) blijven altijd behouden, ook als ze niet in het bestand staan.</p>
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

    private function render_notices() {
        if (!empty($_GET['cmcl_msg'])) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash($_GET['cmcl_msg'])))
            );
        }
        if (!empty($_GET['cmcl_err'])) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash($_GET['cmcl_err'])))
            );
        }
    }

    /**
     * Each row is its own <form> (given a unique id) rendered right before
     * the table, and the row's inputs/buttons bind to it via the HTML
     * `form="..."` attribute — a <form> can't legally wrap <tr>/<td>
     * elements, but this achieves the same "one row, one independent
     * submit" behavior while keeping a real <table> for layout.
     */
    private function render_whitelist_table(array $entries) {
        ?>
        <?php $i = 0; foreach ($entries as $email => $protected): $i++; ?>
            <form id="cmcl-row-<?php echo (int) $i; ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('cmcl_manage_whitelist_entry'); ?>
                <input type="hidden" name="action" value="cmcl_manage_whitelist_entry" />
                <input type="hidden" name="original_email" value="<?php echo esc_attr($email); ?>" />
            </form>
        <?php endforeach; ?>

        <table class="widefat striped" style="max-width:820px;">
            <thead>
                <tr>
                    <th>E-mailadres</th>
                    <th style="width:110px;">Beschermd</th>
                    <th style="width:200px;" colspan="2">Acties</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="4" style="color:#666;">De ledenlijst is nog leeg.</td></tr>
                <?php endif; ?>
                <?php $i = 0; foreach ($entries as $email => $protected): $i++; $fid = 'cmcl-row-' . $i; ?>
                    <tr>
                        <td>
                            <input type="email" name="email" value="<?php echo esc_attr($email); ?>" required form="<?php echo esc_attr($fid); ?>" style="width:100%;" />
                        </td>
                        <td>
                            <input type="checkbox" name="protected" value="1" <?php checked($protected); ?> form="<?php echo esc_attr($fid); ?>" />
                        </td>
                        <td>
                            <button type="submit" name="cmcl_row_action" value="save" class="button button-small" form="<?php echo esc_attr($fid); ?>">Opslaan</button>
                        </td>
                        <td>
                            <button
                                type="submit"
                                name="cmcl_row_action"
                                value="delete"
                                class="button button-small"
                                form="<?php echo esc_attr($fid); ?>"
                                <?php disabled($protected); ?>
                                title="<?php echo $protected ? esc_attr('Hef eerst de bescherming op om te kunnen verwijderen.') : ''; ?>"
                                onclick="return confirm('Dit e-mailadres verwijderen?');"
                            >Verwijderen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_add_entry_form() {
        ?>
        <h3>Nieuw e-mailadres toevoegen</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1.5em;">
            <?php wp_nonce_field('cmcl_add_whitelist_entry'); ?>
            <input type="hidden" name="action" value="cmcl_add_whitelist_entry" />
            <input type="email" name="email" placeholder="naam@voorbeeld.nl" required style="min-width:260px;" />
            <label style="margin-left:8px;"><input type="checkbox" name="protected" value="1" /> Beschermd</label>
            <?php submit_button('Toevoegen', 'secondary', 'submit', false, ['style' => 'margin-left:8px;']); ?>
        </form>
        <?php
    }
}
