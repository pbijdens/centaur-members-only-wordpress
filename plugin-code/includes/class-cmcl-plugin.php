<?php
if (!defined('ABSPATH')) exit;

/**
 * Wires up all runtime components. Activation/deactivation are registered
 * separately in the bootstrap file, since WordPress needs those callbacks
 * bound to the main plugin file itself.
 */
class CMCL_Plugin {

    public function __construct() {
        new CMCL_Whitelist();
        new CMCL_Auth();
        new CMCL_Session();
        new CMCL_Page_Protection();
        new CMCL_Attachment_Protection();
        new CMCL_Admin_Settings();
    }
}
