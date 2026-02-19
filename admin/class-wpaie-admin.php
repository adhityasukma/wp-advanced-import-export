<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIE_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_wpaie_refresh_report', array( __CLASS__, 'ajax_refresh_report' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_modal_html' ) );
	}

    public static function enqueue_scripts( $hook ) {
        // loose check for our page
        if ( isset( $_GET['page'] ) && 'wpaie-settings' === $_GET['page'] ) {
             // Correct page
        } else {
             return;
        }

        wp_enqueue_style( 'wpaie-admin-css', WPAIE_URL . 'assets/css/wpaie-admin.css', array(), time() );
        wp_enqueue_script( 'wpaie-admin-js', WPAIE_URL . 'assets/js/wpaie-admin.js', array( 'jquery' ), time(), true );
        
        wp_localize_script( 'wpaie-admin-js', 'wpaie_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wpaie_ajax_nonce' ) // Generic nonce or specific
        ));
    }

	public static function add_admin_menu() {
		add_menu_page(
			'WP Advanced Import Export',
			'WP Import/Export',
			'manage_options',
			'wpaie-settings',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-download',
			50
		);
	}

    public static function render_settings_page() {
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'export';
        ?>
        <div class="wrap">
            <h1>WP Advanced Import Export</h1>
            
            <h2 class="nav-tab-wrapper wpaie-nav-tabs">
                <a href="#export" class="nav-tab <?php echo $active_tab == 'export' ? 'nav-tab-active' : ''; ?>" data-tab="export">Export</a>
                <a href="#import" class="nav-tab <?php echo $active_tab == 'import' ? 'nav-tab-active' : ''; ?>" data-tab="import">Import</a>
                <a href="#report" class="nav-tab <?php echo $active_tab == 'report' ? 'nav-tab-active' : ''; ?>" data-tab="report">Report (Failed Images)</a>
            </h2>
            
            <div class="wpaie-content">
                <div id="wpaie-tab-export" class="wpaie-tab-section" style="<?php echo $active_tab == 'export' ? '' : 'display:none;'; ?>">
                    <?php include WPAIE_PATH . 'admin/views/html-export.php'; ?>
                </div>
                <div id="wpaie-tab-import" class="wpaie-tab-section" style="<?php echo $active_tab == 'import' ? '' : 'display:none;'; ?>">
                    <?php include WPAIE_PATH . 'admin/views/html-import.php'; ?>
                </div>
                <div id="wpaie-tab-report" class="wpaie-tab-section" style="<?php echo $active_tab == 'report' ? '' : 'display:none;'; ?>">
                    <?php include WPAIE_PATH . 'admin/views/html-report.php'; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_refresh_report() {
        // Security check
        check_ajax_referer( 'wpaie_ajax_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        ob_start();
        include WPAIE_PATH . 'admin/views/html-report.php';
        $html = ob_get_clean();
        
        // We only need the table content, or we can replace the whole container.
        // html-report.php contains the whole card.
        // JS will target #wpaie-tab-report and replace its html.
        
        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function render_modal_html() {
        ?>
        <div id="wpaie-modal" class="wpaie-modal">
            <div class="wpaie-modal-content">
                <div class="wpaie-modal-header">
                    <h3 id="wpaie-modal-title">Notification</h3>
                    <span class="wpaie-modal-close">&times;</span>
                </div>
                <div class="wpaie-modal-body">
                    <p id="wpaie-modal-message"></p>
                </div>
                <div class="wpaie-modal-footer">
                    <button id="wpaie-modal-cancel" class="button">Cancel</button>
                    <button id="wpaie-modal-confirm" class="button button-primary">OK</button>
                </div>
            </div>
        </div>
        <?php
    }
}
