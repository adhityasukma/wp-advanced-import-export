<?php
/**
 * Plugin Name: WP Advanced Import Export
 * Plugin URI:  https://adhityasukma.com
 * Description: Export and Import Posts, Pages, Custom Post Type, Comments, Custom Fields, Taxonomies, and Media with advanced settings.
 * Version:     1.1.4
 * Author:      Adhitya Sukma
 * Text Domain: wp-advanced-import-export
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIE_VERSION', '1.0.0' );
define( 'WPAIE_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPAIE_URL', plugin_dir_url( __FILE__ ) );

class WP_Advanced_Import_Export {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	private function includes() {
		require_once WPAIE_PATH . 'admin/class-wpaie-admin.php';
		require_once WPAIE_PATH . 'includes/class-wpaie-export.php';
		require_once WPAIE_PATH . 'includes/class-wpaie-import.php';
        // require_once WPAIE_PATH . 'includes/class-wpaie-helper.php';
	}

	private function init_hooks() {
		add_action( 'plugins_loaded', array( 'WPAIE_Admin', 'init' ) );
        new WPAIE_Export();
        new WPAIE_Import();
	}
}

WP_Advanced_Import_Export::get_instance();
