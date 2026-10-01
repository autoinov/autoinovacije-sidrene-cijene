<?php
/**
 * Plugin Name: Autoinovacije – Sidrene cijene i cjenici
 * Plugin URI: https://autoinovacije.hr/sidrene-i-bazne-cijene-woocommerce-plugin-csv-xml-cjenici/
 * Description: Sidrene cijene, javni digitalni cjenici i arhiva za WooCommerce.
 * Version: 1.1.2
 * Author: Autoinovacije AI
 * Author URI: https://autoinovacije.hr/
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.5
 * Text Domain: autoinovacije-sidrene-cijene
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_SIDRENE_VERZIJA', '1.1.2');
define('AI_SIDRENE_DATOTEKA', __FILE__);
define('AI_SIDRENE_MAPA', plugin_dir_path(__FILE__));

require_once AI_SIDRENE_MAPA . 'includes/class-ai-sidrene-cijene.php';
require_once AI_SIDRENE_MAPA . 'includes/class-ai-sidrene-xlsx.php';

register_activation_hook(__FILE__, array('AI_Sidrene_Cijene', 'aktiviraj'));
register_deactivation_hook(__FILE__, array('AI_Sidrene_Cijene', 'deaktiviraj'));
AI_Sidrene_Cijene::pokreni();
