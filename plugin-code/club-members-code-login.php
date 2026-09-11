<?php
/**
 * Plugin Name: Club Members Code Login (Whitelist + Shared User)
 * Description: Inloggen voor leden via een e-mail code. Beperkt toegang tot inhoud én beschermde uploads voor gebruikers met een geverifieerd e-mailadres (whitelist), plus alle andere ingelogde gebruikers met minstens leesrechten.
 * Version: 1.2.1
 * Author: (Pieter-Bas IJdens)
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 6.9.4
 */

if (!defined('ABSPATH')) exit;

define('CMCL_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-core.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-signin-page.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-whitelist.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-whitelist-import.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-auth.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-session.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-page-protection.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-attachment-protection.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-admin-settings.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-activator.php';
require_once CMCL_PLUGIN_DIR . 'includes/class-cmcl-plugin.php';

register_activation_hook(__FILE__, ['CMCL_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['CMCL_Activator', 'deactivate']);

new CMCL_Plugin();
