<?php

/**
 * The admin-panel specific functionality of the plugin.
 *
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
class LiteSpeed_Cache_Admin extends LiteSpeed{
	private $config;
	private $display;
	private $main;

	/**
	 * Initialize the class and set its properties.
	 * Run in hook `after_setup_theme` when is_admin()
	 *
	 * @since    1.0.0
	 */
	protected function __construct(){
		// Additional litespeed assets on admin display
		// Also register menu
		$this->display = LiteSpeed_Cache_Admin_Display::get_instance();

		$this->config = LiteSpeed_Cache_Config::get_instance();

		if (!function_exists('is_plugin_active_for_network')) {
			require_once(ABSPATH . '/wp-admin/includes/plugin.php');//todo: check if needed
		}

		// initialize admin actions
		add_action('admin_init', array($this, 'admin_init'));
	}

	/**
	 * Callback that initializes the admin options for LiteSpeed Cache.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function admin_init(){
		LiteSpeed_Cache::get_instance()->set_locale();

		$this->proceed_admin_action();

		// Terminate if user doesn't have the access to settings
		if(is_network_admin()){
			$capability = 'manage_network_options';
		}else{
			$capability = 'manage_options';
		}
		if (!current_user_can($capability)) {
			return;
		}

		// Save setting
		if (!is_network_admin()) {
			register_setting(LiteSpeed_Cache_Config::OPTION_NAME, LiteSpeed_Cache_Config::OPTION_NAME, 
				array(LiteSpeed_Cache_Admin_Settings::get_instance(), 'validate_plugin_settings')
			);
		}

		// step out if plugin is not enabled
		if (!$this->config->is_plugin_enabled()) {
			return;
		}

		// register purge_all actions
		$purge_all_events = array(
			'switch_theme',
			'wp_create_nav_menu', 'wp_update_nav_menu', 'wp_delete_nav_menu',
			'create_term', 'edit_terms', 'delete_term',
			'add_link', 'edit_link', 'delete_link'
		);
		foreach ( $purge_all_events as $event ) {
			add_action($event, array( LiteSpeed_Cache::get_instance(), 'purge_all' ));
		}

		// purge all on upgrade
		if ($this->config->get_option(LiteSpeed_Cache_Config::OPID_PURGE_ON_UPGRADE)) {
			add_action('upgrader_process_complete', array($this, 'purge_all'));
		}

		// check if WP_CACHE is defined and true in the wp-config.php file.
		if (!defined('WP_CACHE') || !WP_CACHE) {
			$add_var = LiteSpeed_Cache_Config::wp_cache_var_setter(true);
			if ($add_var !== true) {
				LiteSpeed_Cache_Admin_Error::add_error($add_var);
			}
		}

		// check for upgrade
		$this->config->plugin_upgrade();
		if (is_network_admin() && current_user_can('manage_network_options')) {
			$this->config->plugin_site_upgrade();
		}

		// check management action
		if (defined('WP_CACHE') && WP_CACHE) {
			$this->check_advanced_cache();
		}


		if (!is_multisite()) {
			if(!current_user_can('manage_options')){
				return;
			}
		}
		elseif (!is_network_admin()) {
			if (!current_user_can('manage_options')) {
				return;
			}
			if (get_current_blog_id() !== BLOG_ID_CURRENT_SITE) {
				$use_primary = LiteSpeed_Cache_Config::NETWORK_OPID_USE_PRIMARY;
				$site_options = $this->config->get_site_options();
				if (isset($site_options[$use_primary]) && $site_options[$use_primary]) {
					$this->display->set_disable_all();
				}
			}
			return;
		}
		elseif (!current_user_can('manage_network_options')) {
			return;
		}

		if (get_transient(LiteSpeed_Cache::WHM_TRANSIENT) == LiteSpeed_Cache::WHM_TRANSIENT_VAL) {
			$this->display->show_display_installed();
		}
	}

	/**
	 * Run litespeed admin actions
	 * 
	 * @since 1.1.0
	 */
	public function proceed_admin_action(){
		$msg = false;
		// handle actions
		switch (LiteSpeed_Cache_Router::get_action()) {

			// Save htaccess
			case LiteSpeed_Cache::ACTION_SAVE_HTACCESS:
				LiteSpeed_Cache_Admin_Rules::get_instance()->htaccess_editor_save();
				break;

			// Save network settings
			case LiteSpeed_Cache::ACTION_SAVE_SETTINGS_NETWORK:
				LiteSpeed_Cache_Admin_Settings::get_instance()->validate_network_settings();// todo: use wp network setting saving
				LiteSpeed_Cache_Admin_Report::get_instance()->update_environment_report();
				break;

			default:
				break;
		}

		if($msg) {
			LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, $msg);
			LiteSpeed_Cache::get_instance()->admin_ctrl_redirect();
			return;
		}

	}

	/**
	 * Check to make sure that the advanced-cache.php file is ours.
	 * If it doesn't exist, try to make it ours.
	 *
	 * If it is not ours and the config is set to check, output an error.
	 *
	 * @since 1.0.11
	 * @access private
	 */
	private function check_advanced_cache(){

		$capability = is_network_admin() ? 'manage_network_options' : 'manage_options';
		if ((defined('LSCACHE_ADV_CACHE') && LSCACHE_ADV_CACHE)
				|| !current_user_can($capability)) {
			if (LiteSpeed_Cache::config(LiteSpeed_Cache_Config::OPID_CHECK_ADVANCEDCACHE) === false) {
				// If it exists because I added it at runtime, try to create the file anyway.
				// Result does not matter.
				LiteSpeed_Cache::get_instance()->try_copy_advanced_cache();
			}

			return;
		}

		if (LiteSpeed_Cache::get_instance()->try_copy_advanced_cache()) {
			return;
		}

		if (is_multisite() && (!is_network_admin() || !current_user_can('manage_network_options'))) {
			$third = __('For this scenario only, the network admin may uncheck "Check Advanced Cache" in LiteSpeed Cache settings.', 'litespeed-cache');
		}else {
			$third = __('For this scenario only, please uncheck "Check Advanced Cache" in LiteSpeed Cache settings.', 'litespeed-cache');
		}
		$msg = __('Please disable/deactivate any other Full Page Cache solutions that are currently being used.', 'litespeed-cache') . ' '
			. __('LiteSpeed Cache does work with other cache solutions, but only their non-page caching offerings—such as minifying css/js files.', 'litespeed-cache') . ' '
			. $third;

		$this->display->add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_YELLOW, $msg);
	}

	/**
	 * Clean up the input string of any extra slashes/spaces.
	 *
	 * @since 1.0.4
	 * @access public
	 * @param string $input The input string to clean.
	 * @return string The cleaned up input.
	 */
	public static function cleanup_text($input){
		return stripslashes(trim($input));
	}

}
