<?php
/**
 * General Settings admin page handler.
 *
 * Handles the registration and display of plugin settings in the WordPress admin.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Admin;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;

/**
 * Class General_Settings
 *
 * Manages the plugin's general settings page in the WordPress admin area.
 *
 * @since 1.0.0
 */
class GeneralSettings {

	/**
	 * Singleton instance of the class.
	 *
	 * @since 1.0.0
	 * @var General_Settings|null
	 */
	private static $instance = null;

	/**
	 * Private constructor to enforce singleton pattern.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Gets the singleton instance of this class.
	 *
	 * @since 1.0.0
	 *
	 * @return self The singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers WordPress hooks for the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'initialize' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_nbs3_test_connection', array( $this, 'check_connection_ajax' ) );
		add_action( 'wp_ajax_nbs3_save_general_settings', array( $this, 'save_general_settings_ajax' ) );
		add_action( 'wp_ajax_nbs3_save_credentials', array( $this, 'save_credentials_ajax' ) );
		add_action( 'wp_ajax_nbs3_sync_bricks_now', array( $this, 'sync_bricks_ajax' ) );
		add_action( 'wp_ajax_nbs3_remove_bricks_from_s3', array( $this, 'remove_bricks_from_s3_ajax' ) );
		add_action( 'wp_ajax_nbs3_get_bricks_status', array( $this, 'get_bricks_status_ajax' ) );
		add_action( 'wp_ajax_nbs3_sync_bricks_theme_assets', array( $this, 'sync_bricks_theme_assets_ajax' ) );
		add_action( 'wp_ajax_nbs3_remove_bricks_theme_assets', array( $this, 'remove_bricks_theme_assets_ajax' ) );
		add_action( 'wp_ajax_nbs3_invalidate_bricks_css', array( $this, 'invalidate_bricks_css_ajax' ) );
		add_action( 'wp_ajax_nbs3_invalidate_bricks_theme_assets', array( $this, 'invalidate_bricks_theme_assets_ajax' ) );
		add_action( 'wp_ajax_nbs3_toggle_plugin_status', array( $this, 'toggle_plugin_status_ajax' ) );
	}

	/**
	 * Initializes the settings, sections, and fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function initialize() {
		register_setting(
			'nbs3',
			'nbs3_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
				'autoload'          => false,
			)
		);

		register_setting(
			'nbs3',
			'nbs3_credentials',
			array(
				'sanitize_callback' => array( $this, 'sanitize_credentials' ),
				'autoload'          => false,
			)
		);

		$this->add_settings_section();
		$this->add_auto_offload_field();
		$this->add_retention_policy_field();
		$this->add_path_prefix_field();
		$this->add_object_versioning_field();
		$this->add_mirror_delete_field();
	}

	/**
	 * Adds the master enable/disable section at the top.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	private function add_master_enable_section() {
		add_settings_section(
			'master_enable',
			__( 'Plugin Status', 'nobloat-s3-offload' ),
			array( $this, 'master_enable_section_callback' ),
			'nbs3',
			array(
				'before_section' => '<div class="nbs3-section nbs3-master-enable-section"><div class="nbs3-section-header">',
				'after_section'  => '</div>',
			)
		);

		add_settings_field(
			'plugin_enabled',
			__( 'Enable Plugin', 'nobloat-s3-offload' ),
			array( $this, 'master_enable_field' ),
			'nbs3',
			'master_enable',
			array(
				'class' => 'nbs3-field nbs3-master-enable',
			)
		);
	}

	/**
	 * Callback for the master enable section description.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function master_enable_section_callback() {
		$is_enabled = nbs3_get_setting( 'plugin_enabled', 0 );

		if ( ! $is_enabled ) {
			echo '<div class="nbs3-setup-notice">';
			echo '<p><strong>' . esc_html__( 'Before enabling the plugin:', 'nobloat-s3-offload' ) . '</strong></p>';
			echo '<ol>';
			echo '<li>' . esc_html__( 'Configure your S3 credentials below (Access Key, Secret Key, Bucket, Region).', 'nobloat-s3-offload' ) . '</li>';
			echo '<li>' . esc_html__( 'Click "Test Connection" to verify your credentials work correctly.', 'nobloat-s3-offload' ) . '</li>';
			echo '<li>' . esc_html__( 'Review the General Settings to configure your preferred offload behavior.', 'nobloat-s3-offload' ) . '</li>';
			echo '<li>' . esc_html__( 'Once everything is configured and tested, enable the plugin to start offloading media.', 'nobloat-s3-offload' ) . '</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Renders the master enable/disable toggle field.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function master_enable_field() {
		$options    = get_option( 'nbs3_settings' );
		$is_enabled = isset( $options['plugin_enabled'] ) ? intval( $options['plugin_enabled'] ) : 0;

		echo '<div class="nbs3-master-toggle">';
		echo '<label class="nbs3-toggle-switch">';
		echo '<input type="checkbox" id="plugin_enabled" name="nbs3_settings[plugin_enabled]" value="1" ' . checked( 1, $is_enabled, false ) . '/>';
		echo '<span class="nbs3-toggle-slider"></span>';
		echo '</label>';
		echo '<span class="nbs3-toggle-label">' . ( $is_enabled ? esc_html__( 'Enabled', 'nobloat-s3-offload' ) : esc_html__( 'Disabled', 'nobloat-s3-offload' ) ) . '</span>';
		echo '</div>';

		if ( $is_enabled ) {
			echo '<p class="description" style="color: #00a32a;">' . esc_html__( 'The plugin is active and will offload media to S3.', 'nobloat-s3-offload' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'The plugin is disabled. Media will not be offloaded until you enable it.', 'nobloat-s3-offload' ) . '</p>';
		}
	}

	/**
	 * Adds the settings sections for the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_settings_section() {
		add_settings_section(
			'general_settings',
			__( 'General Settings', 'nobloat-s3-offload' ),
			function () {
				echo '<p>' . esc_html__( 'Configure the core options for managing and offloading your media files.', 'nobloat-s3-offload' ) . '</p></div>';
			},
			'nbs3',
			array(
				'before_section' => '<div class="nbs3-section nbs3-general-settings"><div class="nbs3-section-header">',
				'after_section'  => '</div>',
			)
		);
	}

	/**
	 * Adds the credentials settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_credentials_field() {
		add_settings_field(
			'nbs3_credentials',
			__( 'Credentials', 'nobloat-s3-offload' ),
			array( $this, 'credentials_field' ),
			'nbs3',
			'cloud_provider',
			array(
				'class' => 'nbs3-field nbs3-cloud-provider-credentials',
			)
		);
	}

	/**
	 * Adds the S3 ACL settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_s3_acl_field() {
		add_settings_field(
			's3_object_acl',
			__( 'Object Permissions', 'nobloat-s3-offload' ),
			array( $this, 's3_acl_field' ),
			'nbs3',
			'cloud_provider',
			array(
				'class' => 'nbs3-field nbs3-s3-acl',
			)
		);
	}

	/**
	 * Adds the Bricks integration settings section.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_bricks_section() {
		add_settings_section(
			'bricks_integration',
			__( 'Bricks Integration', 'nobloat-s3-offload' ),
			function () {
				echo '<p>' . esc_html__( 'Sync Bricks Builder CSS files to S3 for CDN delivery.', 'nobloat-s3-offload' ) . '</p></div>';
			},
			'nbs3',
			array(
				'before_section' => '<div class="nbs3-section nbs3-bricks-integration"><div class="nbs3-section-header">',
				'after_section'  => '</div>',
			)
		);
	}

	/**
	 * Adds the Bricks CSS sync settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_bricks_sync_field() {
		add_settings_field(
			'sync_bricks_css',
			__( 'Bricks CSS Sync', 'nobloat-s3-offload' ),
			array( $this, 'bricks_sync_field' ),
			'nbs3',
			'bricks_integration',
			array(
				'class' => 'nbs3-field nbs3-bricks-sync',
			)
		);
	}

	/**
	 * Adds the Bricks theme assets settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_bricks_theme_assets_field() {
		add_settings_field(
			'sync_bricks_theme_assets',
			__( 'Bricks Theme Assets', 'nobloat-s3-offload' ),
			array( $this, 'bricks_theme_assets_field' ),
			'nbs3',
			'bricks_integration',
			array(
				'class' => 'nbs3-field nbs3-bricks-theme-assets',
			)
		);
	}

	/**
	 * Adds the retention policy settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_retention_policy_field() {
		add_settings_field(
			'retention_policy',
			__( 'Retention Policy', 'nobloat-s3-offload' ),
			array( $this, 'retention_policy_field' ),
			'nbs3',
			'general_settings',
			array(
				'class' => 'nbs3-field nbs3-retention_policy',
			)
		);
	}

	/**
	 * Adds the mirror delete settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_mirror_delete_field() {
		add_settings_field(
			'mirror_delete',
			__( 'Mirror Delete', 'nobloat-s3-offload' ),
			array( $this, 'mirror_delete_field' ),
			'nbs3',
			'general_settings',
			array(
				'class' => 'nbs3-field nbs3-mirror-delete',
			)
		);
	}

	/**
	 * Adds the auto-offload settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_auto_offload_field() {
		add_settings_field(
			'auto_offload_uploads',
			__( 'Auto-Offload Media', 'nobloat-s3-offload' ),
			array( $this, 'auto_offload_field' ),
			'nbs3',
			'general_settings',
			array(
				'class' => 'nbs3-field nbs3-auto-offload',
			)
		);
	}

	/**
	 * Adds the object versioning settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_object_versioning_field() {
		add_settings_field(
			'object_versioning',
			__( 'File Versioning', 'nobloat-s3-offload' ),
			array( $this, 'object_versioning_field' ),
			'nbs3',
			'general_settings',
			array(
				'class' => 'nbs3-field nbs3-object-versioning',
			)
		);
	}

	/**
	 * Adds the path prefix settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function add_path_prefix_field() {
		add_settings_field(
			'path_prefix',
			__( 'Custom Path Prefix', 'nobloat-s3-offload' ),
			array( $this, 'path_prefix_field' ),
			'nbs3',
			'general_settings',
			array(
				'class' => 'nbs3-field nbs3-path-prefix',
			)
		);
	}

	/**
	 * Renders the credentials field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function credentials_field() {
		$s3_provider = new S3Provider();
		$s3_provider->credentials_field();
	}

	/**
	 * Renders the S3 ACL field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function s3_acl_field() {
		$options       = get_option( 'nbs3_settings' );
		$s3_object_acl = isset( $options['s3_object_acl'] ) ? $options['s3_object_acl'] : 'none';

		echo '<div class="nbs3-radio-group">';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="s3_acl_none" name="nbs3_settings[s3_object_acl]" value="none" ' . checked( 'none', $s3_object_acl, false ) . '/>';
		echo '<label for="s3_acl_none">' . esc_html__( 'None (Bucket Policy)', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Recommended. Access is controlled by your S3 bucket policy. Works with modern S3 buckets that have ACLs disabled.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="s3_acl_public" name="nbs3_settings[s3_object_acl]" value="public-read" ' . checked( 'public-read', $s3_object_acl, false ) . '/>';
		echo '<label for="s3_acl_public">' . esc_html__( 'Public Read (ACL)', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Set public-read ACL on each uploaded object. Only works if your bucket allows ACLs.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="s3_acl_private" name="nbs3_settings[s3_object_acl]" value="private" ' . checked( 'private', $s3_object_acl, false ) . '/>';
		echo '<label for="s3_acl_private">' . esc_html__( 'Private (ACL)', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Set private ACL on each uploaded object. Use with CloudFront signed URLs.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders the Bricks CSS sync field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function bricks_sync_field() {
		$options         = get_option( 'nbs3_settings' );
		$sync_bricks_css = isset( $options['sync_bricks_css'] ) ? intval( $options['sync_bricks_css'] ) : 0;
		$status          = nbs3_get_bricks_sync_status();

		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="sync_bricks_css" name="nbs3_settings[sync_bricks_css]" value="1" ' . checked( 1, $sync_bricks_css, false ) . '/>';
		echo '<label for="sync_bricks_css">' . esc_html__( 'Sync Bricks CSS to S3', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Automatically upload Bricks-generated CSS files to S3 and serve via CDN.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		// Status display.
		echo '<div class="nbs3-bricks-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">';
		echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Status:', 'nobloat-s3-offload' ) . '</strong></p>';
		echo '<p style="margin: 0;" id="nbs3-bricks-status-text">';
		printf(
			/* translators: %1$d: number synced, %2$d: number pending, %3$d: total number */
			esc_html__( '%1$d synced, %2$d pending, %3$d total', 'nobloat-s3-offload' ),
			intval( $status['synced'] ),
			intval( $status['pending'] ),
			intval( $status['total'] )
		);
		echo '</p>';
		echo '</div>';

		// Action buttons.
		echo '<div class="nbs3-bricks-actions" style="margin-top: 15px;">';
		echo '<button type="button" class="button" id="nbs3-sync-bricks-now">' . esc_html__( 'Sync Now', 'nobloat-s3-offload' ) . '</button> ';
		echo '<button type="button" class="button" id="nbs3-remove-bricks-s3" style="color: #b32d2e;">' . esc_html__( 'Remove from S3', 'nobloat-s3-offload' ) . '</button>';
		echo '<span id="nbs3-bricks-action-status" style="margin-left: 10px;"></span>';
		echo '</div>';

		// WP-CLI info.
		echo '<div style="margin-top: 15px;">';
		echo '<p class="description">' . esc_html__( 'For large operations, use WP-CLI:', 'nobloat-s3-offload' ) . ' <code>wp nbs3 sync-bricks</code></p>';
		echo '</div>';
	}

	/**
	 * Renders the Bricks theme assets field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function bricks_theme_assets_field() {
		$options           = get_option( 'nbs3_settings' );
		$sync_theme_assets = isset( $options['sync_bricks_theme_assets'] ) ? intval( $options['sync_bricks_theme_assets'] ) : 0;

		// Get status.
		$s3_provider = new S3Provider();
		$service     = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );
		$status      = $service->get_status();

		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="sync_bricks_theme_assets" name="nbs3_settings[sync_bricks_theme_assets]" value="1" ' . checked( 1, $sync_theme_assets, false ) . '/>';
		echo '<label for="sync_bricks_theme_assets">' . esc_html__( 'Sync Bricks Theme Assets to S3', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Upload Bricks theme static assets (CSS, JS, fonts) to S3. Re-syncs automatically when any plugin or theme is updated.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		// Status display.
		echo '<div class="nbs3-bricks-theme-assets-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">';
		echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Status:', 'nobloat-s3-offload' ) . '</strong></p>';
		echo '<p style="margin: 0;" id="nbs3-bricks-theme-assets-status-text">';
		printf(
			/* translators: %1$d: number synced, %2$d: number pending, %3$d: total number of files */
			esc_html__( '%1$d synced, %2$d pending, %3$d total files', 'nobloat-s3-offload' ),
			intval( $status['synced'] ),
			intval( $status['pending'] ),
			intval( $status['total'] )
		);
		echo '</p>';
		echo '</div>';

		// Action buttons.
		echo '<div class="nbs3-bricks-theme-assets-actions" style="margin-top: 15px;">';
		echo '<button type="button" class="button" id="nbs3-sync-bricks-theme-assets-now">' . esc_html__( 'Sync Now', 'nobloat-s3-offload' ) . '</button> ';
		echo '<button type="button" class="button" id="nbs3-remove-bricks-theme-assets-s3" style="color: #b32d2e;">' . esc_html__( 'Remove from S3', 'nobloat-s3-offload' ) . '</button>';
		echo '<span id="nbs3-bricks-theme-assets-action-status" style="margin-left: 10px;"></span>';
		echo '</div>';

		// Info about auto-sync.
		echo '<div style="margin-top: 15px;">';
		echo '<p class="description">' . esc_html__( 'Theme assets auto-sync whenever any plugin or theme is updated.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the path prefix field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function path_prefix_field() {
		$options            = get_option( 'nbs3_settings' );
		$path_prefix        = isset( $options['path_prefix'] ) ? $options['path_prefix'] : 'wp-content/uploads/';
		$path_prefix_active = isset( $options['path_prefix_active'] ) ? $options['path_prefix_active'] : 0;
		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="path_prefix_active" name="nbs3_settings[path_prefix_active]" value="1" ' . checked( 1, $path_prefix_active, false ) . '/>';
		echo '<label for="path_prefix_active">' . esc_html__( 'Use Custom Path Prefix', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description"><input type="text" id="path_prefix" name="nbs3_settings[path_prefix]" value="' . esc_attr( $path_prefix ) . '"' . ( $path_prefix_active ? '' : ' disabled' ) . '/></p>';
		echo '<p class="description">' . esc_html__( 'Add a common prefix to organize offloaded media files from this site in your cloud storage bucket.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the object versioning field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function object_versioning_field() {
		$options           = get_option( 'nbs3_settings' );
		$object_versioning = isset( $options['object_versioning'] ) ? $options['object_versioning'] : 1;

		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="object_versioning" name="nbs3_settings[object_versioning]" value="1" ' . checked( 1, $object_versioning, false ) . '/>';
		echo '<label for="object_versioning">' . esc_html__( 'Add Version to Bucket Path', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Automatically add unique timestamps to your media file paths to ensure the latest versions are always delivered.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the mirror delete field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function mirror_delete_field() {
		$options       = get_option( 'nbs3_settings' );
		$mirror_delete = isset( $options['mirror_delete'] ) ? intval( $options['mirror_delete'] ) : 0;
		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="mirror_delete" name="nbs3_settings[mirror_delete]" value="1" ' . checked( 1, $mirror_delete, false ) . '/>';
		echo '<label for="mirror_delete">' . esc_html__( 'Sync Deletion with Cloud Storage', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, deleting a media file in WordPress will also remove it from your cloud storage.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the auto-offload field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function auto_offload_field() {
		$options              = get_option( 'nbs3_settings' );
		$auto_offload_uploads = isset( $options['auto_offload_uploads'] ) ? intval( $options['auto_offload_uploads'] ) : 1;
		echo '<div class="nbs3-checkbox-option">';
		echo '<input type="checkbox" id="auto_offload_uploads" name="nbs3_settings[auto_offload_uploads]" value="1" ' . checked( 1, $auto_offload_uploads, false ) . '/>';
		echo '<label for="auto_offload_uploads">' . esc_html__( 'Upload files to cloud storage', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Automatically send new uploads to cloud storage.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders the retention policy field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function retention_policy_field() {
		$options          = get_option( 'nbs3_settings' );
		$retention_policy = isset( $options['retention_policy'] ) ? intval( $options['retention_policy'] ) : 0;

		echo '<div class="nbs3-radio-group">';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="retention_policy" name="nbs3_settings[retention_policy]" value="0" ' . checked( 0, $retention_policy, false ) . '/>';
		echo '<label for="retention_policy_none">' . esc_html__( 'Retain Local Files', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Keep all files on your local server after offloading to the cloud.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="retention_policy_cloud" name="nbs3_settings[retention_policy]" value="1" ' . checked( 1, $retention_policy, false ) . '/>';
		echo '<label for="retention_policy_cloud">' . esc_html__( 'Smart Local Cleanup', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Remove local copies after cloud offloading, but keep the original file as a backup.', 'nobloat-s3-offload' ) . '</p>';
		echo '</div>';

		echo '<div class="nbs3-radio-option">';
		echo '<input type="radio" id="retention_policy_all" name="nbs3_settings[retention_policy]" value="2" ' . checked( 2, $retention_policy, false ) . '/>';
		echo '<label for="retention_policy_all">' . esc_html__( 'Full Cloud Migration', 'nobloat-s3-offload' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Remove all local files, including originals, after successful cloud offloading.', 'nobloat-s3-offload' ) . '</p>';
		echo '<p class="description" style="margin-top: 5px; color: #666;"><em>' . esc_html__( 'Safety Note: If File Versioning is disabled and a file with the same name already exists in S3, the local copy will be preserved to prevent data loss.', 'nobloat-s3-offload' ) . '</em></p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Sanitizes the settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $options The options array to sanitize.
	 * @return array The sanitized options array.
	 */
	public function sanitize( $options ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( 'nbs3_settings', array() );
		}

		$sanitized = array(
			'plugin_enabled'           => 0,
			'retention_policy'         => 0,
			'object_versioning'        => 1,
			'path_prefix'              => '',
			'mirror_delete'            => 0,
			'path_prefix_active'       => 0,
			'auto_offload_uploads'     => 1,
			's3_object_acl'            => 'none',
			'sync_bricks_css'          => 0,
			'sync_bricks_theme_assets' => 0,
		);

		// Master enable toggle - disabled by default.
		$sanitized['plugin_enabled'] = isset( $options['plugin_enabled'] ) && 1 === (int) $options['plugin_enabled'] ? 1 : 0;

		$sanitized['retention_policy']     = isset( $options['retention_policy'] ) ? intval( $options['retention_policy'] ) : 0;
		$sanitized['retention_policy']     = in_array( $sanitized['retention_policy'], array( 0, 1, 2 ), true ) ? $sanitized['retention_policy'] : 0;
		$sanitized['object_versioning']    = isset( $options['object_versioning'] ) && 1 === (int) $options['object_versioning'] ? 1 : 0;
		$sanitized['path_prefix']          = isset( $options['path_prefix'] ) ? nbs3_sanitize_path( $options['path_prefix'] ) : '';
		$sanitized['mirror_delete']        = isset( $options['mirror_delete'] ) && 1 === (int) $options['mirror_delete'] ? 1 : 0;
		$sanitized['path_prefix_active']   = isset( $options['path_prefix_active'] ) && 1 === (int) $options['path_prefix_active'] ? 1 : 0;
		$sanitized['auto_offload_uploads'] = isset( $options['auto_offload_uploads'] ) && 1 === (int) $options['auto_offload_uploads'] ? 1 : 0;

		// S3 ACL setting.
		$valid_acls                 = array( 'none', 'public-read', 'private' );
		$sanitized['s3_object_acl'] = isset( $options['s3_object_acl'] ) && in_array( $options['s3_object_acl'], $valid_acls, true )
			? $options['s3_object_acl']
			: 'none';

		// Bricks sync setting.
		$old_bricks_setting           = nbs3_get_setting( 'sync_bricks_css', 0 );
		$sanitized['sync_bricks_css'] = isset( $options['sync_bricks_css'] ) && 1 === (int) $options['sync_bricks_css'] ? 1 : 0;

		// Auto-sync if enabling and under threshold.
		if ( $sanitized['sync_bricks_css'] && ! $old_bricks_setting && nbs3_is_bricks_active() ) {
			$status = nbs3_get_bricks_sync_status();
			if ( $status['pending'] > 0 && $status['total'] < 50 ) {
				// Schedule immediate sync.
				wp_schedule_single_event( time(), 'nbs3_bricks_initial_sync' );
			}
		}

		// Bricks theme assets sync setting.
		$sanitized['sync_bricks_theme_assets'] = isset( $options['sync_bricks_theme_assets'] ) && 1 === (int) $options['sync_bricks_theme_assets'] ? 1 : 0;

		add_settings_error(
			'nbs3_messages',
			'nbs3_message',
			__( 'Settings Saved', 'nobloat-s3-offload' ),
			'updated'
		);

		return $sanitized;
	}

	/**
	 * Sanitizes the credentials array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $credentials The credentials array to sanitize.
	 * @return array The sanitized credentials array.
	 */
	public function sanitize_credentials( $credentials ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return get_option( 'nbs3_credentials', array() );
		}

		if ( empty( $credentials ) || ! is_array( $credentials ) ) {
			return get_option( 'nbs3_credentials', array() );
		}

		$sanitized       = array();
		$checkbox_fields = array( 'path_style_endpoint' );

		foreach ( $credentials as $field_name => $field_value ) {
			$constant_name = 'NBS3_' . strtoupper( $field_name );
			if ( defined( $constant_name ) ) {
				continue;
			}

			if ( 'endpoint' === $field_name ) {
				$sanitized[ $field_name ] = nbs3_validate_endpoint( (string) $field_value );
			} elseif ( 'domain' === $field_name ) {
				$sanitized[ $field_name ] = nbs3_normalize_url( (string) $field_value );
			} elseif ( 'region' === $field_name ) {
				$sanitized[ $field_name ] = nbs3_validate_region( (string) $field_value );
			} elseif ( in_array( $field_name, array( 'key', 'secret' ), true ) ) {
				$sanitized[ $field_name ] = sanitize_text_field( $field_value );
			} elseif ( in_array( $field_name, $checkbox_fields, true ) ) {
				$sanitized[ $field_name ] = ( '1' === $field_value || 1 === $field_value ) ? 1 : 0;
			} else {
				$sanitized[ $field_name ] = sanitize_text_field( $field_value );
			}
		}

		foreach ( $checkbox_fields as $checkbox_field ) {
			$constant_name = 'NBS3_' . strtoupper( $checkbox_field );
			if ( defined( $constant_name ) ) {
				continue;
			}
			if ( ! isset( $credentials[ $checkbox_field ] ) ) {
				$sanitized[ $checkbox_field ] = 0;
			}
		}

		return $sanitized;
	}

	/**
	 * Adds the settings page to the admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_menu_page(
			__( 'Nobloat S3 Offload', 'nobloat-s3-offload' ),
			__( 'S3 Offload', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3',
			array( $this, 'plugin_status_page_view' ),
			'dashicons-cloud',
			100
		);

		add_submenu_page(
			'nbs3',
			__( 'Status', 'nobloat-s3-offload' ),
			__( 'Status', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3',
			array( $this, 'plugin_status_page_view' )
		);

		add_submenu_page(
			'nbs3',
			__( 'Settings', 'nobloat-s3-offload' ),
			__( 'Settings', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_settings',
			array( $this, 'settings_page_view' )
		);

		add_submenu_page(
			'nbs3',
			__( 'Connection', 'nobloat-s3-offload' ),
			__( 'Connection', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_connection',
			array( $this, 'connection_page_view' )
		);

		// AWS Guide and Documentation added with priority 99 to ensure correct order.
		add_action( 'admin_menu', array( $this, 'add_additional_submenus' ), 99 );
	}

	/**
	 * Adds additional submenu pages.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_additional_submenus() {
		add_submenu_page(
			'nbs3',
			__( 'Bricks', 'nobloat-s3-offload' ),
			__( 'Bricks', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_bricks',
			array( $this, 'bricks_page_view' )
		);

		add_submenu_page(
			'nbs3',
			__( 'AWS Guide', 'nobloat-s3-offload' ),
			__( 'AWS Guide', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_aws_guide',
			array( $this, 'aws_guide_page_view' )
		);

		add_submenu_page(
			'nbs3',
			__( 'Documentation', 'nobloat-s3-offload' ),
			__( 'Documentation', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_documentation',
			array( $this, 'documentation_page_view' )
		);

		add_submenu_page(
			'nbs3',
			__( 'About', 'nobloat-s3-offload' ),
			__( 'About', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_about',
			array( $this, 'about_page_view' )
		);
	}

	/**
	 * Renders the Bricks integration page view.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function bricks_page_view() {
		nbs3_get_view( 'admin/bricks' );
	}

	/**
	 * Renders the AWS guide page view.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function aws_guide_page_view() {
		nbs3_get_view( 'admin/aws-guide' );
	}

	/**
	 * Renders the documentation page view.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function documentation_page_view() {
		nbs3_get_view( 'admin/documentation' );
	}

	/**
	 * Renders the about page view.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function about_page_view() {
		nbs3_get_view( 'admin/about' );
	}

	/**
	 * Renders the plugin status page view.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function plugin_status_page_view() {
		nbs3_get_view( 'admin/plugin-status' );
	}

	/**
	 * Renders the settings page view.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function settings_page_view() {
		nbs3_get_view( 'admin/general-settings' );
	}

	/**
	 * Renders the connection page view.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function connection_page_view() {
		nbs3_get_view( 'admin/connection' );
	}

	/**
	 * Enqueues scripts and styles for the settings pages.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! nbs3_is_settings_page() ) {
			return;
		}

		if ( nbs3_is_settings_page( 'plugin-status' ) || nbs3_is_settings_page( 'settings' ) || nbs3_is_settings_page( 'connection' ) || nbs3_is_settings_page( 'bricks' ) ) {
			wp_enqueue_script( 'nbs3_settings', NBS3_URL . 'assets/js/nbs3_settings.js', array(), NBS3_VERSION, true );
			wp_localize_script(
				'nbs3_settings',
				'nbs3_ajax_object',
				array(
					'ajax_url'                         => admin_url( 'admin-ajax.php' ),
					'nonce'                            => wp_create_nonce( 'nbs3_test_connection' ),
					'save_general_nonce'               => wp_create_nonce( 'nbs3_save_general_settings' ),
					'toggle_status_nonce'              => wp_create_nonce( 'nbs3_toggle_plugin_status' ),
					'save_credentials_nonce'           => wp_create_nonce( 'nbs3_save_credentials' ),
					'bricks_sync_nonce'                => wp_create_nonce( 'nbs3_bricks_sync' ),
					'bricks_remove_nonce'              => wp_create_nonce( 'nbs3_bricks_remove' ),
					'bricks_status_nonce'              => wp_create_nonce( 'nbs3_bricks_status' ),
					'bricks_theme_assets_sync_nonce'   => wp_create_nonce( 'nbs3_bricks_theme_assets_sync' ),
					'bricks_theme_assets_remove_nonce' => wp_create_nonce( 'nbs3_bricks_theme_assets_remove' ),
					'bricks_invalidate_nonce'          => wp_create_nonce( 'nbs3_bricks_invalidate' ),
				)
			);
		}

		if ( nbs3_is_settings_page( 'media' ) ) {
			wp_enqueue_script( 'nbs3_bulkoffload', NBS3_URL . 'assets/js/nbs3_bulkoffload.js', array(), NBS3_VERSION, true );
			wp_localize_script(
				'nbs3_bulkoffload',
				'nbs3_ajax_object',
				array(
					'ajax_url'               => admin_url( 'admin-ajax.php' ),
					'bulk_offload_nonce'     => wp_create_nonce( 'nbs3_bulk_offload' ),
					'media_sync_nonce'       => wp_create_nonce( 'nbs3_media_sync' ),
					'media_remove_nonce'     => wp_create_nonce( 'nbs3_media_remove' ),
					'media_invalidate_nonce' => wp_create_nonce( 'nbs3_media_invalidate' ),
				)
			);
		}

		wp_enqueue_style( 'nbs3_admin', NBS3_URL . 'assets/css/admin.css', array(), NBS3_VERSION );

		if ( is_rtl() ) {
			wp_enqueue_style( 'nbs3_admin_rtl', NBS3_URL . 'assets/css/admin-rtl.css', array( 'nbs3_admin' ), NBS3_VERSION );
		}
	}

	/**
	 * AJAX handler for testing S3 connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function check_connection_ajax() {
		$current_time  = current_time( 'd/m/Y - h:i A' );
		$response_data = array(
			'last_check' => $current_time,
		);

		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_test_connection' ) ) {
			$response_data['message'] = __( 'Invalid nonce!', 'nobloat-s3-offload' );
			wp_send_json_error( $response_data );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$response_data['message'] = __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' );
			wp_send_json_error( $response_data, 403 );
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			$response_data['message'] = __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' );
			wp_send_json_error( $response_data );
		}

		try {
			$s3_provider       = new S3Provider();
			$connection_result = $s3_provider->check_connection();

			update_option( 'nbs3_last_connection_check', $current_time );

			if ( $connection_result ) {
				$response_data['message'] = __( 'Connection successful!', 'nobloat-s3-offload' );
				wp_send_json_success( $response_data );
			} else {
				$response_data['message'] = __( 'Connection failed!', 'nobloat-s3-offload' );
				wp_send_json_error( $response_data, 401 );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for connection failures.
			error_log( 'NBS3 Connection Error: ' . $e->getMessage() );
			update_option( 'nbs3_last_connection_check', $current_time );

			$error_msg    = $e->getMessage();
			$safe_message = '';

			if ( false !== strpos( $error_msg, 'InvalidAccessKeyId' ) ) {
				$safe_message = __( 'Invalid Access Key ID. Please check your credentials.', 'nobloat-s3-offload' );
			} elseif ( false !== strpos( $error_msg, 'SignatureDoesNotMatch' ) ) {
				$safe_message = __( 'Invalid Secret Access Key. Please check your credentials.', 'nobloat-s3-offload' );
			} elseif ( false !== strpos( $error_msg, 'NoSuchBucket' ) ) {
				$safe_message = __( 'Bucket not found. Please check the bucket name.', 'nobloat-s3-offload' );
			} elseif ( false !== strpos( $error_msg, 'AccessDenied' ) ) {
				$safe_message = __( 'Access denied. Please check your credentials and bucket permissions.', 'nobloat-s3-offload' );
			} elseif ( false !== strpos( $error_msg, 'PermanentRedirect' ) || false !== strpos( $error_msg, 'region' ) ) {
				$safe_message = __( 'Region mismatch. Please check your region setting matches the bucket location.', 'nobloat-s3-offload' );
			} elseif ( false !== strpos( $error_msg, 'Could not resolve host' ) || false !== strpos( $error_msg, 'cURL error' ) ) {
				$safe_message = __( 'Could not connect to S3. Please check your endpoint and region settings.', 'nobloat-s3-offload' );
			} else {
				// Show a truncated version of the actual error for debugging (sanitized).
				$safe_message = __( 'Connection failed: ', 'nobloat-s3-offload' ) . wp_kses( substr( $error_msg, 0, 300 ), array() );
			}

			$response_data['message'] = $safe_message;
			wp_send_json_error( $response_data, 500 );
		}
	}

	/**
	 * AJAX handler for saving general settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function save_general_settings_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_save_general_settings' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		// Validate input structure.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_security_nonce() above.
		if ( ! isset( $_POST['nbs3_settings'] ) || ! is_array( $_POST['nbs3_settings'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid settings data format.', 'nobloat-s3-offload' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, data sanitized by $this->sanitize().
		$settings = wp_unslash( $_POST['nbs3_settings'] );

		// Additional validation after unslashing.
		if ( ! is_array( $settings ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid settings data structure.', 'nobloat-s3-offload' ),
				)
			);
		}

		$sanitized_settings = $this->sanitize( $settings );
		update_option( 'nbs3_settings', $sanitized_settings, false );

		wp_send_json_success(
			array(
				'message' => __( 'Settings saved successfully!', 'nobloat-s3-offload' ),
			)
		);
	}

	/**
	 * AJAX handler for saving credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function save_credentials_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_save_credentials' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above, data sanitized by $this->sanitize_credentials().
		$credentials           = isset( $_POST['nbs3_credentials'] ) ? wp_unslash( $_POST['nbs3_credentials'] ) : array();
		$sanitized_credentials = $this->sanitize_credentials( $credentials );
		update_option( 'nbs3_credentials', $sanitized_credentials, false );

		wp_send_json_success(
			array(
				'message' => __( 'Credentials saved successfully!', 'nobloat-s3-offload' ),
			)
		);
	}

	/**
	 * AJAX handler for syncing Bricks CSS files to S3.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function sync_bricks_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_sync' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! nbs3_is_bricks_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Bricks Builder is not active.', 'nobloat-s3-offload' ),
				)
			);
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			$s3_provider  = new S3Provider();
			$sync_service = new \NBS3\Services\BricksCssSyncService( $s3_provider );
			$result       = $sync_service->batch_sync( 50 );

			if ( $result['has_more'] ) {
				$message = sprintf(
					/* translators: %1$d: number of files uploaded, %2$d: remaining files */
					__( 'Syncing... %1$d uploaded, %2$d remaining.', 'nobloat-s3-offload' ),
					$result['uploaded'],
					$result['remaining']
				);
			} else {
				$message = sprintf(
					/* translators: %1$d: number of files uploaded, %2$d: number of files deleted */
					__( 'Sync completed. %1$d uploaded, %2$d deleted.', 'nobloat-s3-offload' ),
					$result['uploaded'],
					$result['deleted']
				);
			}

			wp_send_json_success(
				array(
					'message'  => $message,
					'uploaded' => $result['uploaded'],
					'deleted'  => $result['deleted'],
					'errors'   => $result['errors'],
					'has_more' => $result['has_more'],
					'status'   => $result['status'],
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for sync failures.
			error_log( 'NBS3 Bricks Sync Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Sync failed. Check your server error logs for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for removing Bricks CSS files from S3.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function remove_bricks_from_s3_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_remove' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! nbs3_is_bricks_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Bricks Builder is not active.', 'nobloat-s3-offload' ),
				)
			);
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			$s3_provider  = new S3Provider();
			$sync_service = new \NBS3\Services\BricksCssSyncService( $s3_provider );
			$result       = $sync_service->remove_all_from_s3();

			$status = nbs3_get_bricks_sync_status();

			$message = sprintf(
				/* translators: %d: number of files deleted */
				__( 'Removed %d files from S3.', 'nobloat-s3-offload' ),
				$result['deleted']
			);
			wp_send_json_success(
				array(
					'message' => $message,
					'deleted' => $result['deleted'],
					'errors'  => $result['errors'],
					'status'  => $status,
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging S3 sync failures.
			error_log( 'NBS3 Bricks Remove Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Removal failed. Check your server error logs for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for getting Bricks CSS sync status.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function get_bricks_status_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_status' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		$status = nbs3_get_bricks_sync_status();

		$status_text = sprintf(
			/* translators: %1$d: number synced, %2$d: number pending, %3$d: total number */
			__( '%1$d synced, %2$d pending, %3$d total', 'nobloat-s3-offload' ),
			$status['synced'],
			$status['pending'],
			$status['total']
		);
		wp_send_json_success(
			array(
				'status'      => $status,
				'status_text' => $status_text,
			)
		);
	}

	/**
	 * AJAX handler for syncing Bricks theme assets to S3.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function sync_bricks_theme_assets_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_theme_assets_sync' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! nbs3_is_bricks_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Bricks Builder is not active.', 'nobloat-s3-offload' ),
				)
			);
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			$s3_provider  = new S3Provider();
			$sync_service = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );
			$result       = $sync_service->batch_sync( 50 );

			if ( $result['has_more'] ) {
				$message = sprintf(
					/* translators: %1$d: number of files uploaded, %2$d: remaining files */
					__( 'Syncing... %1$d uploaded, %2$d remaining.', 'nobloat-s3-offload' ),
					$result['uploaded'],
					$result['remaining']
				);
			} else {
				$message = sprintf(
					/* translators: %1$d: files uploaded, %2$d: files deleted */
					__( 'Sync completed. %1$d uploaded, %2$d deleted.', 'nobloat-s3-offload' ),
					$result['uploaded'],
					$result['deleted']
				);
			}

			wp_send_json_success(
				array(
					'message'  => $message,
					'uploaded' => $result['uploaded'],
					'deleted'  => $result['deleted'],
					'errors'   => $result['errors'],
					'has_more' => $result['has_more'],
					'status'   => $result['status'],
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for sync failures.
			error_log( 'NBS3 Bricks Theme Assets Sync Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Sync failed. Check your server error logs for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for removing Bricks theme assets from S3.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function remove_bricks_theme_assets_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_theme_assets_remove' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! nbs3_is_bricks_active() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Bricks Builder is not active.', 'nobloat-s3-offload' ),
				)
			);
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			$s3_provider  = new S3Provider();
			$sync_service = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );
			$result       = $sync_service->remove_all_from_s3();
			$status       = $sync_service->get_status();

			$message = sprintf(
				/* translators: %d: number of files deleted */
				__( 'Removed %d files from S3.', 'nobloat-s3-offload' ),
				$result['deleted']
			);
			wp_send_json_success(
				array(
					'message' => $message,
					'deleted' => $result['deleted'],
					'errors'  => $result['errors'],
					'status'  => $status,
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for debugging S3 sync failures.
			error_log( 'NBS3 Bricks Theme Assets Remove Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Removal failed. Check your server error logs for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for invalidating Bricks CSS sync status.
	 * Attempts to delete from S3 first, then clears local tracking.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function invalidate_bricks_css_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_invalidate' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		$synced_files = get_option( 'nbs3_synced_bricks_files', array() );
		$total_files  = count( $synced_files );

		if ( 0 === $total_files ) {
			wp_send_json_success(
				array(
					'message' => __( 'No Bricks CSS files to invalidate.', 'nobloat-s3-offload' ),
					'deleted' => 0,
					'status'  => nbs3_get_bricks_sync_status(),
				)
			);
		}

		$deleted       = 0;
		$delete_errors = 0;

		// Try to delete from S3 if credentials are configured.
		$bucket = nbs3_get_credential( 'bucket' );
		if ( ! empty( $bucket ) ) {
			try {
				$s3_provider  = new S3Provider();
				$sync_service = new \NBS3\Services\BricksCssSyncService( $s3_provider );

				foreach ( $synced_files as $file => $data ) {
					try {
						$sync_service->delete_from_s3( $file );
						++$deleted;
					} catch ( \Exception $e ) {
						++$delete_errors;
					}
				}
			} catch ( \Exception $e ) {
				// S3 connection failed, just invalidate locally.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
				error_log( 'NBS3 Bricks CSS Invalidate - S3 connection failed: ' . $e->getMessage() );
			}
		}

		// Always clear the local sync tracking.
		delete_option( 'nbs3_synced_bricks_files' );

		$status = nbs3_get_bricks_sync_status();

		if ( $deleted > 0 ) {
			$message = sprintf(
				/* translators: %1$d: files deleted from S3, %2$d: total files invalidated */
				__( 'Invalidated %1$d files. %2$d deleted from S3.', 'nobloat-s3-offload' ),
				$total_files,
				$deleted
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of files invalidated */
				__( 'Invalidated %d files. S3 deletion skipped or failed.', 'nobloat-s3-offload' ),
				$total_files
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'deleted' => $deleted,
				'status'  => $status,
			)
		);
	}

	/**
	 * AJAX handler for invalidating Bricks theme assets sync status.
	 * Attempts to delete from S3 first, then clears local tracking.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function invalidate_bricks_theme_assets_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_bricks_invalidate' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		$synced_files = get_option( 'nbs3_synced_bricks_theme_assets', array() );
		$total_files  = count( $synced_files );

		if ( 0 === $total_files ) {
			$s3_provider = new S3Provider();
			$service     = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );

			wp_send_json_success(
				array(
					'message' => __( 'No Bricks theme assets to invalidate.', 'nobloat-s3-offload' ),
					'deleted' => 0,
					'status'  => $service->get_status(),
				)
			);
		}

		$deleted       = 0;
		$delete_errors = 0;

		// Try to delete from S3 if credentials are configured.
		$bucket = nbs3_get_credential( 'bucket' );
		if ( ! empty( $bucket ) ) {
			try {
				$s3_provider  = new S3Provider();
				$sync_service = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );

				foreach ( $synced_files as $file => $data ) {
					try {
						$sync_service->delete_from_s3( $file );
						++$deleted;
					} catch ( \Exception $e ) {
						++$delete_errors;
					}
				}
			} catch ( \Exception $e ) {
				// S3 connection failed, just invalidate locally.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
				error_log( 'NBS3 Bricks Theme Assets Invalidate - S3 connection failed: ' . $e->getMessage() );
			}
		}

		// Always clear the local sync tracking.
		delete_option( 'nbs3_synced_bricks_theme_assets' );

		$s3_provider = new S3Provider();
		$service     = new \NBS3\Services\BricksThemeAssetsSyncService( $s3_provider );
		$status      = $service->get_status();

		if ( $deleted > 0 ) {
			$message = sprintf(
				/* translators: %1$d: files deleted from S3, %2$d: total files invalidated */
				__( 'Invalidated %1$d files. %2$d deleted from S3.', 'nobloat-s3-offload' ),
				$total_files,
				$deleted
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of files invalidated */
				__( 'Invalidated %d files. S3 deletion skipped or failed.', 'nobloat-s3-offload' ),
				$total_files
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'deleted' => $deleted,
				'status'  => $status,
			)
		);
	}

	/**
	 * AJAX handler for toggling the plugin enabled status.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public function toggle_plugin_status_ajax() {
		if ( ! $this->verify_security_nonce( 'security_nonce', 'nbs3_toggle_plugin_status' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token!', 'nobloat-s3-offload' ),
				)
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'nobloat-s3-offload' ),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_security_nonce() above.
		$plugin_enabled = isset( $_POST['plugin_enabled'] ) && '1' === $_POST['plugin_enabled'] ? 1 : 0;

		// Get current settings and update only the plugin_enabled value.
		$settings                   = get_option( 'nbs3_settings', array() );
		$settings['plugin_enabled'] = $plugin_enabled;
		update_option( 'nbs3_settings', $settings, false );

		wp_send_json_success(
			array(
				'message' => $plugin_enabled
					? __( 'Plugin enabled!', 'nobloat-s3-offload' )
					: __( 'Plugin disabled.', 'nobloat-s3-offload' ),
				'enabled' => $plugin_enabled,
			)
		);
	}

	/**
	 * Verifies a security nonce from POST data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name   The name of the nonce field in POST data.
	 * @param string $action The nonce action to verify against.
	 * @return bool|int False if the nonce is invalid, 1 if valid and generated 0-12 hours ago, 2 if valid and generated 12-24 hours ago.
	 */
	private function verify_security_nonce( $name, $action ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce values do not need unslashing.
		$security_nonce = isset( $_POST[ $name ] ) ? sanitize_text_field( $_POST[ $name ] ) : '';
		return wp_verify_nonce( $security_nonce, $action );
	}

	/**
	 * Prevents cloning of the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevents unserializing of the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function __wakeup() {}
}
