<?php
/**
 * Media Overview admin page handler.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Admin
 */

namespace NBS3\Admin;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Class MediaOverview
 *
 * Handles the media overview admin page, displaying statistics about
 * offloaded and non-offloaded media files, and providing bulk offload functionality.
 */
class MediaOverview {

	/**
	 * Singleton instance.
	 *
	 * @var MediaOverview|null
	 */
	private static $instance = null;

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {
		$this->register();
	}

	/**
	 * Get the singleton instance.
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
	 * Register hooks and actions.
	 *
	 * @return void
	 */
	private function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'initialize' ) );
		add_action( 'wp_ajax_nbs3_download_errors_csv', array( $this, 'handle_download_errors_csv' ) );
		add_action( 'wp_ajax_nbs3_sync_media_now', array( $this, 'sync_media_ajax' ) );
		add_action( 'wp_ajax_nbs3_remove_media_from_s3', array( $this, 'remove_media_from_s3_ajax' ) );
		add_action( 'wp_ajax_nbs3_invalidate_media', array( $this, 'invalidate_media_ajax' ) );

		// Add offload status column to Media Library.
		add_filter( 'manage_media_columns', array( $this, 'add_offload_status_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_offload_status_column' ), 10, 2 );
		add_filter( 'manage_upload_sortable_columns', array( $this, 'make_offload_column_sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_offload_status' ) );

		// Add meta box to attachment edit page.
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_meta_box' ) );

		// Single attachment AJAX handlers.
		add_action( 'wp_ajax_nbs3_offload_single_attachment', array( $this, 'offload_single_attachment_ajax' ) );
		add_action( 'wp_ajax_nbs3_remove_single_attachment', array( $this, 'remove_single_attachment_ajax' ) );
		add_action( 'wp_ajax_nbs3_invalidate_single_attachment', array( $this, 'invalidate_single_attachment_ajax' ) );
	}

	/**
	 * Initialize the media overview page.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->add_media_overview_fields();
		$this->add_media_overview_sections();
	}

	/**
	 * Add the media overview submenu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'nbs3',
			__( 'Media', 'nobloat-s3-offload' ),
			__( 'Media', 'nobloat-s3-offload' ),
			'manage_options',
			'nbs3_media',
			array( $this, 'media_overview_page_view' )
		);
	}

	/**
	 * Render the media overview page view.
	 *
	 * @return void
	 */
	public function media_overview_page_view() {
		nbs3_get_view( 'admin/media-overview' );
	}

	/**
	 * Add offload status column to Media Library.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_offload_status_column( $columns ) {
		$columns['nbs3_offload_status'] = __( 'S3 Status', 'nobloat-s3-offload' );
		return $columns;
	}

	/**
	 * Render the offload status column content.
	 *
	 * @param string $column_name The column name.
	 * @param int    $attachment_id The attachment ID.
	 * @return void
	 */
	public function render_offload_status_column( $column_name, $attachment_id ) {
		if ( 'nbs3_offload_status' !== $column_name ) {
			return;
		}

		$is_offloaded = get_post_meta( $attachment_id, 'nbs3_offloaded', true );
		$has_errors   = get_post_meta( $attachment_id, 'nbs3_error_log', true );
		$s3_path      = get_post_meta( $attachment_id, 'nbs3_path', true );

		if ( $is_offloaded ) {
			echo '<span style="color: #00a32a;" title="' . esc_attr( $s3_path ) . '">&#10003; Offloaded</span>';
			if ( $s3_path ) {
				echo '<br><small style="color: #666;">' . esc_html( $s3_path ) . '</small>';
			}
		} elseif ( ! empty( $has_errors ) ) {
			$error_text = is_array( $has_errors ) ? implode( '; ', $has_errors ) : $has_errors;
			echo '<span style="color: #d63638;" title="' . esc_attr( $error_text ) . '">&#10007; Error</span>';
			echo '<br><small style="color: #d63638; max-width: 200px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' . esc_attr( $error_text ) . '">';
			echo esc_html( wp_trim_words( $error_text, 10, '...' ) );
			echo '</small>';
		} else {
			echo '<span style="color: #996800;">&#8212; Not offloaded</span>';
		}

		// Show attachment ID for debugging.
		echo '<br><small style="color: #999;">ID: ' . esc_html( $attachment_id ) . '</small>';
	}

	/**
	 * Make the offload status column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function make_offload_column_sortable( $columns ) {
		$columns['nbs3_offload_status'] = 'nbs3_offload_status';
		return $columns;
	}

	/**
	 * Handle sorting by offload status.
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function sort_by_offload_status( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'nbs3_offload_status' === $orderby ) {
			$query->set( 'meta_key', 'nbs3_offloaded' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add meta box to attachment edit page.
	 *
	 * @param \WP_Post $post The attachment post object (unused, required by hook signature).
	 * @return void
	 */
	public function add_attachment_meta_box( $post ) {
		unset( $post ); // Unused but required by add_meta_boxes hook signature.
		add_meta_box(
			'nbs3_offload_status',
			__( 'S3 Offload Status', 'nobloat-s3-offload' ),
			array( $this, 'render_attachment_meta_box' ),
			'attachment',
			'side',
			'high'
		);
	}

	/**
	 * Render the attachment meta box content.
	 *
	 * @param \WP_Post $post The attachment post object.
	 * @return void
	 */
	public function render_attachment_meta_box( $post ) {
		$attachment_id = $post->ID;
		$is_offloaded  = get_post_meta( $attachment_id, 'nbs3_offloaded', true );
		$has_errors    = get_post_meta( $attachment_id, 'nbs3_error_log', true );
		$s3_path       = get_post_meta( $attachment_id, 'nbs3_path', true );
		$offloaded_at  = get_post_meta( $attachment_id, 'nbs3_offloaded_at', true );
		$bucket        = get_post_meta( $attachment_id, 'nbs3_bucket', true );

		// Nonces for AJAX actions.
		$offload_nonce    = wp_create_nonce( 'nbs3_offload_single_' . $attachment_id );
		$remove_nonce     = wp_create_nonce( 'nbs3_remove_single_' . $attachment_id );
		$invalidate_nonce = wp_create_nonce( 'nbs3_invalidate_single_' . $attachment_id );

		echo '<div class="nbs3-attachment-meta-box">';

		// Status indicator.
		echo '<p><strong>' . esc_html__( 'Status:', 'nobloat-s3-offload' ) . '</strong> ';
		if ( $is_offloaded ) {
			echo '<span style="color: #00a32a;">&#10003; ' . esc_html__( 'Offloaded', 'nobloat-s3-offload' ) . '</span>';
		} elseif ( ! empty( $has_errors ) ) {
			echo '<span style="color: #d63638;">&#10007; ' . esc_html__( 'Error', 'nobloat-s3-offload' ) . '</span>';
		} else {
			echo '<span style="color: #996800;">&#8212; ' . esc_html__( 'Not offloaded', 'nobloat-s3-offload' ) . '</span>';
		}
		echo '</p>';

		// Details if offloaded.
		if ( $is_offloaded ) {
			if ( $s3_path ) {
				echo '<p><strong>' . esc_html__( 'S3 Path:', 'nobloat-s3-offload' ) . '</strong><br>';
				echo '<code style="word-break: break-all; font-size: 11px;">' . esc_html( $s3_path ) . '</code></p>';
			}
			if ( $bucket ) {
				echo '<p><strong>' . esc_html__( 'Bucket:', 'nobloat-s3-offload' ) . '</strong> ' . esc_html( $bucket ) . '</p>';
			}
			if ( $offloaded_at ) {
				echo '<p><strong>' . esc_html__( 'Offloaded:', 'nobloat-s3-offload' ) . '</strong> ';
				echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $offloaded_at ) );
				echo '</p>';
			}
		}

		// Error details.
		if ( ! empty( $has_errors ) ) {
			$error_text = is_array( $has_errors ) ? implode( "\n", $has_errors ) : $has_errors;
			echo '<div style="background: #fcf0f1; border-left: 4px solid #d63638; padding: 8px; margin: 10px 0;">';
			echo '<strong>' . esc_html__( 'Error Details:', 'nobloat-s3-offload' ) . '</strong><br>';
			echo '<small style="white-space: pre-wrap; word-break: break-word;">' . esc_html( $error_text ) . '</small>';
			echo '</div>';
		}

		// Attachment ID for reference.
		echo '<p><strong>' . esc_html__( 'Attachment ID:', 'nobloat-s3-offload' ) . '</strong> ' . esc_html( $attachment_id ) . '</p>';

		// Action buttons.
		echo '<div class="nbs3-attachment-actions" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">';
		echo '<p id="nbs3-attachment-action-status" style="margin-bottom: 10px;"></p>';

		if ( ! $is_offloaded ) {
			echo '<button type="button" class="button button-primary" id="nbs3-offload-attachment" ';
			echo 'data-attachment-id="' . esc_attr( $attachment_id ) . '" ';
			echo 'data-nonce="' . esc_attr( $offload_nonce ) . '">';
			echo esc_html__( 'Offload to S3', 'nobloat-s3-offload' );
			echo '</button> ';
		}

		if ( $is_offloaded ) {
			echo '<button type="button" class="button" id="nbs3-remove-attachment" ';
			echo 'data-attachment-id="' . esc_attr( $attachment_id ) . '" ';
			echo 'data-nonce="' . esc_attr( $remove_nonce ) . '" ';
			echo 'style="color: #b32d2e;">';
			echo esc_html__( 'Remove from S3', 'nobloat-s3-offload' );
			echo '</button> ';
		}

		if ( $is_offloaded || ! empty( $has_errors ) ) {
			echo '<button type="button" class="button" id="nbs3-invalidate-attachment" ';
			echo 'data-attachment-id="' . esc_attr( $attachment_id ) . '" ';
			echo 'data-nonce="' . esc_attr( $invalidate_nonce ) . '" ';
			echo 'style="color: #996800;">';
			echo esc_html__( 'Clear Status', 'nobloat-s3-offload' );
			echo '</button>';
		}

		echo '</div>';
		echo '</div>';

		// Inline JavaScript for the buttons.
		$this->render_attachment_meta_box_script();
	}

	/**
	 * Render inline JavaScript for attachment meta box actions.
	 *
	 * @return void
	 */
	private function render_attachment_meta_box_script() {
		?>
		<script type="text/javascript">
		(function() {
			const statusEl = document.getElementById('nbs3-attachment-action-status');

			function showStatus(message, isError = false) {
				if (statusEl) {
					statusEl.textContent = message;
					statusEl.style.color = isError ? '#d63638' : '#00a32a';
				}
			}

			function setButtonLoading(button, loading) {
				if (loading) {
					button.disabled = true;
					button.dataset.originalText = button.textContent;
					button.textContent = '<?php echo esc_js( __( 'Working...', 'nobloat-s3-offload' ) ); ?>';
				} else {
					button.disabled = false;
					if (button.dataset.originalText) {
						button.textContent = button.dataset.originalText;
					}
				}
			}

			// Offload button
			const offloadBtn = document.getElementById('nbs3-offload-attachment');
			if (offloadBtn) {
				offloadBtn.addEventListener('click', function() {
					setButtonLoading(this, true);
					const data = new URLSearchParams();
					data.append('action', 'nbs3_offload_single_attachment');
					data.append('attachment_id', this.dataset.attachmentId);
					data.append('security_nonce', this.dataset.nonce);

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: data
					})
					.then(r => r.json())
					.then(data => {
						if (data.success) {
							showStatus(data.data.message);
							setTimeout(() => location.reload(), 1500);
						} else {
							setButtonLoading(offloadBtn, false);
							showStatus(data.data?.message || 'Offload failed.', true);
						}
					})
					.catch(e => {
						setButtonLoading(offloadBtn, false);
						showStatus('Error: ' + e.message, true);
					});
				});
			}

			// Remove button
			const removeBtn = document.getElementById('nbs3-remove-attachment');
			if (removeBtn) {
				removeBtn.addEventListener('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Remove this file from S3? The local file will be preserved.', 'nobloat-s3-offload' ) ); ?>')) {
						return;
					}
					setButtonLoading(this, true);
					const data = new URLSearchParams();
					data.append('action', 'nbs3_remove_single_attachment');
					data.append('attachment_id', this.dataset.attachmentId);
					data.append('security_nonce', this.dataset.nonce);

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: data
					})
					.then(r => r.json())
					.then(data => {
						if (data.success) {
							showStatus(data.data.message);
							setTimeout(() => location.reload(), 1500);
						} else {
							setButtonLoading(removeBtn, false);
							showStatus(data.data?.message || 'Removal failed.', true);
						}
					})
					.catch(e => {
						setButtonLoading(removeBtn, false);
						showStatus('Error: ' + e.message, true);
					});
				});
			}

			// Invalidate/Clear button
			const invalidateBtn = document.getElementById('nbs3-invalidate-attachment');
			if (invalidateBtn) {
				invalidateBtn.addEventListener('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Clear offload status? This does NOT delete the file from S3.', 'nobloat-s3-offload' ) ); ?>')) {
						return;
					}
					setButtonLoading(this, true);
					const data = new URLSearchParams();
					data.append('action', 'nbs3_invalidate_single_attachment');
					data.append('attachment_id', this.dataset.attachmentId);
					data.append('security_nonce', this.dataset.nonce);

					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: data
					})
					.then(r => r.json())
					.then(data => {
						if (data.success) {
							showStatus(data.data.message);
							setTimeout(() => location.reload(), 1500);
						} else {
							setButtonLoading(invalidateBtn, false);
							showStatus(data.data?.message || 'Clear failed.', true);
						}
					})
					.catch(e => {
						setButtonLoading(invalidateBtn, false);
						showStatus('Error: ' + e.message, true);
					});
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * AJAX handler to offload a single attachment.
	 *
	 * @return void
	 */
	public function offload_single_attachment_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately after using dynamic action name.
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_offload_single_' . $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'nobloat-s3-offload' ) ) );
		}

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nobloat-s3-offload' ) ) );
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error( array( 'message' => __( 'S3 credentials not configured.', 'nobloat-s3-offload' ) ) );
		}

		try {
			$s3_provider = new \NBS3\S3Provider();
			$uploader    = new \NBS3\Services\CloudAttachmentUploader( $s3_provider );
			$result      = $uploader->upload_attachment( $attachment_id );

			if ( $result ) {
				wp_send_json_success( array( 'message' => __( 'File offloaded successfully.', 'nobloat-s3-offload' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Offload failed. Check the error log for details.', 'nobloat-s3-offload' ) ) );
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging; raw message must not be returned to client.
			error_log( 'NBS3 Single Offload Error: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Offload failed. Check the error log for details.', 'nobloat-s3-offload' ) ) );
		}
	}

	/**
	 * AJAX handler to remove a single attachment from S3.
	 *
	 * @return void
	 */
	public function remove_single_attachment_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately after using dynamic action name.
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_remove_single_' . $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'nobloat-s3-offload' ) ) );
		}

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nobloat-s3-offload' ) ) );
		}

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error( array( 'message' => __( 'S3 credentials not configured.', 'nobloat-s3-offload' ) ) );
		}

		try {
			$s3_provider = new \NBS3\S3Provider();
			$result      = $s3_provider->delete_attachment( $attachment_id );

			if ( $result ) {
				// Clear offload metadata.
				delete_post_meta( $attachment_id, 'nbs3_offloaded' );
				delete_post_meta( $attachment_id, 'nbs3_s3_key' );
				delete_post_meta( $attachment_id, 'nbs3_s3_url' );
				delete_post_meta( $attachment_id, 'nbs3_offloaded_sizes' );
				delete_post_meta( $attachment_id, 'nbs3_path' );
				delete_post_meta( $attachment_id, 'nbs3_offloaded_at' );
				delete_post_meta( $attachment_id, 'nbs3_bucket' );
				delete_post_meta( $attachment_id, 'nbs3_provider' );

				wp_send_json_success( array( 'message' => __( 'File removed from S3.', 'nobloat-s3-offload' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Failed to remove file from S3.', 'nobloat-s3-offload' ) ) );
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Raw message must not be returned to client.
			error_log( 'NBS3 Single Remove Error: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Removal failed. Check the error log for details.', 'nobloat-s3-offload' ) ) );
		}
	}

	/**
	 * AJAX handler to invalidate/clear a single attachment's offload status.
	 *
	 * @return void
	 */
	public function invalidate_single_attachment_ajax() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified immediately after using dynamic action name.
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_invalidate_single_' . $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'nobloat-s3-offload' ) ) );
		}

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nobloat-s3-offload' ) ) );
		}

		// Clear all offload-related metadata.
		delete_post_meta( $attachment_id, 'nbs3_offloaded' );
		delete_post_meta( $attachment_id, 'nbs3_s3_key' );
		delete_post_meta( $attachment_id, 'nbs3_s3_url' );
		delete_post_meta( $attachment_id, 'nbs3_offloaded_sizes' );
		delete_post_meta( $attachment_id, 'nbs3_path' );
		delete_post_meta( $attachment_id, 'nbs3_offloaded_at' );
		delete_post_meta( $attachment_id, 'nbs3_bucket' );
		delete_post_meta( $attachment_id, 'nbs3_provider' );
		delete_post_meta( $attachment_id, 'nbs3_error_log' );
		delete_post_meta( $attachment_id, 'nbs3_object_version' );
		delete_post_meta( $attachment_id, 'nbs3_retention_policy' );

		wp_send_json_success( array( 'message' => __( 'Offload status cleared.', 'nobloat-s3-offload' ) ) );
	}

	/**
	 * Add settings sections for the media overview page.
	 *
	 * @return void
	 */
	private function add_media_overview_sections() {
		add_settings_section(
			'media_overview',
			__( 'Media Library Overview', 'nobloat-s3-offload' ),
			function () {
				echo '<p>' . esc_html__( 'Get a comprehensive overview of all media files in your WordPress library. Easily identify any files that haven\'t been offloaded to the Cloud and offload them in bulk.', 'nobloat-s3-offload' ) . '</p></div>';
			},
			'nbs3_media',
			array(
				'before_section' => '<div class="nbs3-section nbs3-media-overview"><div class="nbs3-section-header">',
				'after_section'  => '</div>',
			)
		);

		add_settings_section(
			'media_bulk_offload',
			__( 'Bulk Offload', 'nobloat-s3-offload' ),
			function () {
				echo '<p>' . esc_html__( 'Bulk offload your unoffloaded media files to cloud storage. This process frees up your server storage and boosts your website\'s performance in one go!', 'nobloat-s3-offload' ) . '</p></div>';
			},
			'nbs3_media',
			array(
				'before_section' => '<div class="nbs3-section nbs3-media-overview"><div class="nbs3-section-header">',
				'after_section'  => '</div>',
			)
		);
	}

	/**
	 * Add settings fields for the media overview page.
	 *
	 * @return void
	 */
	private function add_media_overview_fields() {
		add_settings_field(
			'total_media_files',
			__( 'Total Media Files', 'nobloat-s3-offload' ),
			array( $this, 'total_media_files_field' ),
			'nbs3_media',
			'media_overview',
			array(
				'class' => 'nbs3-field nbs3-non-offloaded-media',
			)
		);

		add_settings_field(
			'offloaded_media',
			__( 'Offloaded Media', 'nobloat-s3-offload' ),
			array( $this, 'offloaded_media_field' ),
			'nbs3_media',
			'media_overview',
			array(
				'class' => 'nbs3-field nbs3-non-offloaded-media',
			)
		);

		add_settings_field(
			'non_offloaded_media',
			__( 'Non-Offloaded Media', 'nobloat-s3-offload' ),
			array( $this, 'non_offloaded_media_field' ),
			'nbs3_media',
			'media_overview',
			array(
				'class' => 'nbs3-field nbs3-non-offloaded-media',
			)
		);

		add_settings_field(
			'offload_errors',
			__( 'Offload Errors', 'nobloat-s3-offload' ),
			array( $this, 'offload_errors_field' ),
			'nbs3_media',
			'media_overview',
			array(
				'class' => 'nbs3-field nbs3-non-offloaded-media',
			)
		);

		add_settings_field(
			'media_actions',
			__( 'Actions', 'nobloat-s3-offload' ),
			array( $this, 'media_actions_field' ),
			'nbs3_media',
			'media_overview',
			array(
				'class' => 'nbs3-field nbs3-media-actions',
			)
		);

		add_settings_field(
			'bulk_offload_media',
			__( 'Bulk Offload Existing Media', 'nobloat-s3-offload' ),
			array( $this, 'bulk_offload_media_field' ),
			'nbs3_media',
			'media_bulk_offload',
			array(
				'class' => 'nbs3-field nbs3-bulk-offload-media',
			)
		);
	}

	/**
	 * Render the total media files field.
	 *
	 * @return void
	 */
	public function total_media_files_field() {
		$total_attachments = wp_count_attachments();
		$total_count       = array_sum( (array) $total_attachments );

		echo '<p class="nbs3-stat">';
		echo '<span class="nbs3-stat-number">' . esc_html( number_format_i18n( $total_count ) ) . ' </span>';
		echo '<span class="nbs3-stat-label">' . esc_html__( 'Media Attachments', 'nobloat-s3-offload' ) . '</span>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Total number of media files stored on your server.', 'nobloat-s3-offload' ) . '</p>';
	}

	/**
	 * Render the offloaded media field.
	 *
	 * @return void
	 */
	public function offloaded_media_field() {
		$offloaded_count = nbs3_get_offloaded_media_items_count();

		echo '<p class="nbs3-stat">';
		echo '<span class="nbs3-stat-number">' . esc_html( number_format_i18n( $offloaded_count ) ) . ' </span>';
		echo '<span class="nbs3-stat-label">' . esc_html__( 'Media Attachments', 'nobloat-s3-offload' ) . '</span>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Number of media files successfully moved to cloud storage.', 'nobloat-s3-offload' ) . '</p>';
	}

	/**
	 * Render the non-offloaded media field.
	 *
	 * @return void
	 */
	public function non_offloaded_media_field() {
		$non_offloaded_count = nbs3_get_unoffloaded_media_items_count();

		echo '<p class="nbs3-stat">';
		echo '<span class="nbs3-stat-number">' . esc_html( number_format_i18n( $non_offloaded_count ) ) . ' </span>';
		if ( 0 === $non_offloaded_count ) {
			echo '<span class="nbs3-stat-label">' . esc_html__( 'Media Attachments', 'nobloat-s3-offload' ) . '</span>';
		} elseif ( 1 === $non_offloaded_count ) {
			echo '<span class="nbs3-stat-label">' . esc_html__( 'Media Attachment', 'nobloat-s3-offload' ) . '</span>';
		} else {
			echo '<span class="nbs3-stat-label">' . esc_html__( 'Media Attachments', 'nobloat-s3-offload' ) . '</span>';
		}
		echo '</p>';

		if ( 0 === $non_offloaded_count ) {
			echo '<p class="description">' . esc_html__( 'Great job! All your media files are offloaded to cloud storage.', 'nobloat-s3-offload' ) . '</p>';
		} elseif ( 1 === $non_offloaded_count ) {
			echo '<p class="description">' . esc_html__( 'There is 1 media file still stored on your local server.', 'nobloat-s3-offload' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'This file can be offloaded to free up local storage space.', 'nobloat-s3-offload' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'These files can be offloaded to free up local storage space.', 'nobloat-s3-offload' ) . '</p>';
		}
	}

	/**
	 * Render the offload errors field.
	 *
	 * @return void
	 */
	public function offload_errors_field() {
		$attachments_with_errors = $this->get_attachments_with_errors();
		$count                   = count( $attachments_with_errors );

		$nonce        = wp_create_nonce( 'nbs3_download_errors_csv' );
		$download_url = admin_url( 'admin-ajax.php?action=nbs3_download_errors_csv&nonce=' . $nonce );

		echo '<p>' . esc_html__( 'Number of attachments with errors:', 'nobloat-s3-offload' ) . ' <strong>' . esc_html( $count ) . '</strong></p>';

		if ( $count > 0 ) {
			echo '<p><a href="' . esc_url( $download_url ) . '" class="button button-secondary">' . esc_html__( 'Download Errors CSV', 'nobloat-s3-offload' ) . '</a></p>';
		}
	}

	/**
	 * Render the media actions field with sync/delete/invalidate buttons.
	 *
	 * @return void
	 */
	public function media_actions_field() {
		$non_offloaded_count = nbs3_get_unoffloaded_media_items_count();
		$offloaded_count     = nbs3_get_offloaded_media_items_count();

		// Status display (same format as Bricks tab).
		echo '<div class="nbs3-media-sync-status" style="margin-top: 15px; padding: 12px; background: #f0f0f1; border-radius: 4px;">';
		echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Status:', 'nobloat-s3-offload' ) . '</strong></p>';
		echo '<p style="margin: 0;" id="nbs3-media-status-text">';
		printf(
			/* translators: %1$d: number offloaded, %2$d: number pending, %3$d: total number */
			esc_html__( '%1$d offloaded, %2$d pending, %3$d total', 'nobloat-s3-offload' ),
			intval( $offloaded_count ),
			intval( $non_offloaded_count ),
			intval( $offloaded_count + $non_offloaded_count )
		);
		echo '</p>';
		echo '</div>';

		echo '<div class="nbs3-media-actions-buttons" style="margin-top: 15px;">';
		echo '<button type="button" class="button" id="nbs3-sync-media-now">' . esc_html__( 'Sync Now', 'nobloat-s3-offload' ) . '</button> ';
		echo '<button type="button" class="button" id="nbs3-remove-media-s3" style="color: #b32d2e;">' . esc_html__( 'Remove from S3', 'nobloat-s3-offload' ) . '</button> ';
		echo '<button type="button" class="button" id="nbs3-invalidate-media" style="color: #996800;">' . esc_html__( 'Invalidate', 'nobloat-s3-offload' ) . '</button>';
		echo '<span id="nbs3-media-action-status" style="margin-left: 10px;"></span>';
		echo '</div>';

		echo '<div style="margin-top: 15px;">';
		echo '<p class="description">' . esc_html__( 'Sync Now: Upload non-offloaded media to S3 (50 files per batch).', 'nobloat-s3-offload' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Remove from S3: Delete all offloaded media files from S3.', 'nobloat-s3-offload' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Invalidate: Clear offload tracking metadata without deleting S3 files.', 'nobloat-s3-offload' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'For large operations, use WP-CLI:', 'nobloat-s3-offload' ) . '</strong> <code>wp nbs3 offload</code></p>';
		echo '</div>';
	}

	/**
	 * AJAX handler for syncing media to S3.
	 *
	 * @return void
	 */
	public function sync_media_ajax() {
		// Increase time limit to prevent timeouts during batch processing.
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- set_time_limit may be disabled on some hosts; needed to prevent timeouts during batch uploads.
			@set_time_limit( 120 );
		}

		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_media_sync' ) ) {
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

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			// Use smaller batch size to avoid timeouts.
			$limit           = 10;
			$unoffloaded_ids = nbs3_get_unoffloaded_media_item_ids( $limit );
			$uploaded        = 0;
			$errors          = 0;
			$error_messages  = array();

			if ( empty( $unoffloaded_ids ) ) {
				wp_send_json_success(
					array(
						'message'  => __( 'All media files are already offloaded.', 'nobloat-s3-offload' ),
						'uploaded' => 0,
						'errors'   => 0,
						'has_more' => false,
						'status'   => $this->get_media_status(),
					)
				);
			}

			$s3_provider = new \NBS3\S3Provider();
			$uploader    = new \NBS3\Services\CloudAttachmentUploader( $s3_provider );

			foreach ( $unoffloaded_ids as $attachment_id ) {
				try {
					$result = $uploader->upload_attachment( $attachment_id );
					if ( $result ) {
						++$uploaded;
					} else {
						++$errors;
						$error_messages[] = sprintf( 'Attachment %d failed to upload', $attachment_id );
					}
				} catch ( \Exception $e ) {
					++$errors;
					/* translators: %d: attachment ID */
					$error_messages[] = sprintf( __( 'Attachment %d failed (see error log for details).', 'nobloat-s3-offload' ), $attachment_id );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging; raw $e->getMessage() must not reach the client.
					error_log( 'NBS3 Media Sync Error for attachment ' . $attachment_id . ': ' . $e->getMessage() );
				}
			}

			$remaining = nbs3_get_unoffloaded_media_items_count();
			$has_more  = $remaining > 0;

			if ( $has_more ) {
				$message = sprintf(
					/* translators: %1$d: files uploaded, %2$d: remaining files */
					__( 'Syncing... %1$d uploaded, %2$d remaining.', 'nobloat-s3-offload' ),
					$uploaded,
					$remaining
				);
			} else {
				$message = sprintf(
					/* translators: %d: files uploaded */
					__( 'Sync completed. %d files uploaded.', 'nobloat-s3-offload' ),
					$uploaded
				);
			}

			if ( $errors > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of errors */
					__( '(%d errors)', 'nobloat-s3-offload' ),
					$errors
				);
			}

			wp_send_json_success(
				array(
					'message'        => $message,
					'uploaded'       => $uploaded,
					'errors'         => $errors,
					'error_messages' => $error_messages,
					'has_more'       => $has_more,
					'remaining'      => $remaining,
					'status'         => $this->get_media_status(),
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Raw message must not be returned to client.
			error_log( 'NBS3 Media Sync Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Sync failed. Check the error log for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for removing all media from S3.
	 *
	 * @return void
	 */
	public function remove_media_from_s3_ajax() {
		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_media_remove' ) ) {
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

		$bucket = nbs3_get_credential( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please configure your S3 credentials first.', 'nobloat-s3-offload' ),
				)
			);
		}

		try {
			$offloaded_ids = nbs3_get_offloaded_media_item_ids();
			$deleted       = 0;
			$errors        = 0;

			if ( empty( $offloaded_ids ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'No offloaded media files to remove.', 'nobloat-s3-offload' ),
						'deleted' => 0,
						'errors'  => 0,
						'status'  => $this->get_media_status(),
					)
				);
			}

			$s3_provider = new \NBS3\S3Provider();

			foreach ( $offloaded_ids as $attachment_id ) {
				try {
					// Use S3Provider's delete_attachment method which handles all sizes.
					$result = $s3_provider->delete_attachment( $attachment_id );

					if ( $result ) {
						// Clear offload metadata.
						delete_post_meta( $attachment_id, 'nbs3_offloaded' );
						delete_post_meta( $attachment_id, 'nbs3_s3_key' );
						delete_post_meta( $attachment_id, 'nbs3_s3_url' );
						delete_post_meta( $attachment_id, 'nbs3_offloaded_sizes' );
						delete_post_meta( $attachment_id, 'nbs3_path' );

						++$deleted;
					} else {
						++$errors;
					}
				} catch ( \Exception $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
					error_log( 'NBS3 Media Remove Error for attachment ' . $attachment_id . ': ' . $e->getMessage() );
					++$errors;
				}
			}

			$message = sprintf(
				/* translators: %d: number of files deleted */
				__( 'Removed %d media files from S3.', 'nobloat-s3-offload' ),
				$deleted
			);

			if ( $errors > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of errors */
					__( '%d files had errors.', 'nobloat-s3-offload' ),
					$errors
				);
			}

			wp_send_json_success(
				array(
					'message' => $message,
					'deleted' => $deleted,
					'errors'  => $errors,
					'status'  => $this->get_media_status(),
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Raw message must not be returned to client.
			error_log( 'NBS3 Media Remove Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Removal failed. Check the error log for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * AJAX handler for invalidating media offload tracking.
	 *
	 * @return void
	 */
	public function invalidate_media_ajax() {
		if ( ! $this->verify_ajax_nonce( 'security_nonce', 'nbs3_media_invalidate' ) ) {
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

		try {
			global $wpdb;

			// Count offloaded items before clearing.
			$offloaded_count = nbs3_get_offloaded_media_items_count();

			if ( 0 === $offloaded_count ) {
				wp_send_json_success(
					array(
						'message'     => __( 'No offloaded media to invalidate.', 'nobloat-s3-offload' ),
						'invalidated' => 0,
						'status'      => $this->get_media_status(),
					)
				);
			}

			// Delete all offload-related meta keys.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared, bulk meta deletion for invalidation.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE meta_key IN (%s, %s, %s, %s)',
					$wpdb->postmeta,
					'nbs3_offloaded',
					'nbs3_s3_key',
					'nbs3_s3_url',
					'nbs3_offloaded_sizes'
				)
			);

			$message = sprintf(
				/* translators: %d: number of files invalidated */
				__( 'Invalidated %d media files. S3 files were not deleted.', 'nobloat-s3-offload' ),
				$offloaded_count
			);

			wp_send_json_success(
				array(
					'message'     => $message,
					'invalidated' => $offloaded_count,
					'status'      => $this->get_media_status(),
				)
			);
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging.
			error_log( 'NBS3 Media Invalidate Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => __( 'Invalidation failed. Check your server error logs for details.', 'nobloat-s3-offload' ),
				)
			);
		}
	}

	/**
	 * Get the current media offload status.
	 *
	 * @return array Status with total, offloaded, and non_offloaded counts.
	 */
	private function get_media_status() {
		$total_attachments = wp_count_attachments();
		$total_count       = array_sum( (array) $total_attachments );

		return array(
			'total'         => $total_count,
			'offloaded'     => nbs3_get_offloaded_media_items_count(),
			'non_offloaded' => nbs3_get_unoffloaded_media_items_count(),
		);
	}

	/**
	 * Verify an AJAX nonce.
	 *
	 * @param string $name   The name of the nonce field in POST data.
	 * @param string $action The nonce action to verify against.
	 * @return bool|int False if invalid, 1 or 2 if valid.
	 */
	private function verify_ajax_nonce( $name, $action ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce values do not need unslashing.
		$security_nonce = isset( $_POST[ $name ] ) ? sanitize_text_field( $_POST[ $name ] ) : '';
		return wp_verify_nonce( $security_nonce, $action );
	}

	/**
	 * Get attachment IDs that have offload errors.
	 *
	 * @return array Array of attachment IDs with errors.
	 */
	private function get_attachments_with_errors() {
		global $wpdb;

		$query = $wpdb->prepare(
			'SELECT post_id FROM %i WHERE meta_key = %s',
			$wpdb->postmeta,
			'nbs3_error_log'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is fully prepared above.
		return $wpdb->get_col( $query );
	}

	/**
	 * Handle the AJAX request to download errors as CSV.
	 *
	 * @throws Exception If user lacks permissions, nonce is invalid, or no errors found.
	 * @return void
	 */
	public function handle_download_errors_csv() {
		// Authorization gates outside the try/catch — check_admin_referer dies on failure and never returns.
		check_admin_referer( 'nbs3_download_errors_csv', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'nobloat-s3-offload' ), '', array( 'response' => 403 ) );
		}

		try {
			$attachments_with_errors = $this->get_attachments_with_errors();

			if ( empty( $attachments_with_errors ) ) {
				throw new Exception( 'No attachments with errors found.' );
			}

			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="nbs3_errors.csv"' );

			$output = fopen( 'php://output', 'w' );
			if ( false === $output ) {
				throw new Exception( 'Unable to create output stream.' );
			}

			fputcsv( $output, array( 'Attachment ID', 'Attachment Title', 'Errors' ) );

			foreach ( $attachments_with_errors as $attachment_id ) {
				$attachment = get_post( $attachment_id );
				if ( ! $attachment ) {
					// Skip if attachment doesn't exist.
					continue;
				}
				$errors        = get_post_meta( $attachment_id, 'nbs3_error_log', true );
				$errors_string = is_array( $errors ) ? implode( "; \n", $errors ) : $errors;

				fputcsv(
					$output,
					array(
						$attachment_id,
						$this->escape_csv_cell( $attachment->post_title ),
						$this->escape_csv_cell( $errors_string ),
					)
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output stream for CSV generation, WP_Filesystem not applicable.
			fclose( $output );
			exit;
		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Internal exception logging.
			error_log( 'NBS3 CSV Export Error: ' . $e->getMessage() );
			status_header( 500 );
			wp_die( esc_html__( 'Error generating CSV.', 'nobloat-s3-offload' ), '', array( 'response' => 500 ) );
		}
	}

	/**
	 * Escape a CSV cell against spreadsheet formula injection.
	 *
	 * Cells starting with =, +, -, @, tab, or carriage return are
	 * interpreted as formulas by Excel/LibreOffice/Numbers. An attacker
	 * with `edit_post` on an attachment (e.g., an Author for their own
	 * media) could set a post title beginning with `=cmd|...!A1` and
	 * cause command execution when an admin opens the exported CSV.
	 * Prefixing a single quote neutralises the leading character.
	 *
	 * @param mixed $value The cell value.
	 * @return string The escaped cell value.
	 */
	private function escape_csv_cell( $value ): string {
		$value = (string) $value;
		if ( strlen( $value ) > 0 && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Render the bulk offload media field.
	 *
	 * @return void
	 */
	public function bulk_offload_media_field() {
		// WP CLI Notice.
		echo '<div style="background: #f0f6fc; border: 1px solid #c3d4e7; border-radius: 4px; padding: 12px; margin-bottom: 20px;">';
		echo '<p style="margin: 0 0 8px 0;"><strong style="color: #0073aa;">' . esc_html__( 'Use WP CLI for Large Operations', 'nobloat-s3-offload' ) . '</strong></p>';
		echo '<p style="margin: 0 0 8px 0; font-size: 13px;">' . esc_html__( 'For sites with hundreds or thousands of media files, our WP CLI command offers superior performance and control:', 'nobloat-s3-offload' ) . '</p>';
		echo '<p style="margin: 0 0 8px 0;"><code style="background: #fff; padding: 2px 6px; border-radius: 3px; font-family: monospace;">wp nbs3 offload</code></p>';
		echo '</div>';

		echo '<p class="description"><strong>' . esc_html__( 'Note:', 'nobloat-s3-offload' ) . '</strong> ';
		echo esc_html__( 'The web-based sync (above) supports up to 50 media attachments per batch. For larger operations with hundreds or thousands of files, we recommend using WP CLI commands which provide better performance and reliability for large-scale operations.', 'nobloat-s3-offload' ) . '</p>';

		$bulk_offload_data = nbs3_get_bulk_offload_data();
		$is_offloading     = 'processing' === $bulk_offload_data['status'];

		// Show progress bar if background process is running.
		if ( $is_offloading ) {
			$progress      = ( $bulk_offload_data['total'] > 0 ) ? ( $bulk_offload_data['processed'] / $bulk_offload_data['total'] ) * 100 : 0;
			$progress_text = $bulk_offload_data['total'] > 0 ? round( $progress ) . '%' : __( 'Preparing...', 'nobloat-s3-offload' );
			$processed     = $bulk_offload_data['processed'] ?? 0;
			$total         = $bulk_offload_data['total'] ?? 0;

			echo '<div id="progress-container" style="margin-top: 20px;" data-status="processing">';
			echo '<p id="progress-title" style="font-size: 16px; font-weight: bold;">' .
				sprintf(
					/* translators: %1$s: processed count span, %2$s: total count span */
					esc_html__( 'Offloading media files to cloud storage (%1$s of %2$s)', 'nobloat-s3-offload' ),
					'<span id="processed-count">' . esc_html( $processed ) . '</span>',
					'<span id="total-count">' . esc_html( $total ) . '</span>'
				) .
				'</p>';
			echo '<div class="progress-bar-container" style="width: 100%; background-color: #e0e0e0; padding: 3px; border-radius: 3px;">';
			printf( '<div id="offload-progress" style="width: %.1f%%; height: 20px; background-color: #0073aa; border-radius: 2px; transition: width 0.5s;"></div>', esc_html( $progress ) );
			echo '</div>';
			printf( '<p id="progress-text" style="margin-top: 10px; font-weight: bold;">%s</p>', esc_html( $progress_text ) );
			if ( false === get_option( 'nbs3_bulk_offload_cancelled' ) ) {
				echo '<button type="button" id="bulk-offload-cancel-button" class="button">' . esc_html__( 'Cancel', 'nobloat-s3-offload' ) . '</button>';
			} else {
				echo '<p>' . esc_html__( 'Canceling the bulk offload process...', 'nobloat-s3-offload' ) . '</p>';
			}
			echo '</div>';
		}
	}
}
