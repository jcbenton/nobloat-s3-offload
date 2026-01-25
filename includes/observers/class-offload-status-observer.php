<?php
/**
 * Offload Status Observer.
 *
 * @package NoBloat_S3_Offload
 * @since   1.0.0
 */

namespace NBS3\Observers;

defined( 'ABSPATH' ) || exit;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

/**
 * Observer that displays the offload status on the attachment edit screen.
 *
 * @since 1.0.0
 */
class OffloadStatusObserver implements ObserverInterface {

	use OffloaderTrait;

	/**
	 * Cloud provider instance.
	 *
	 * @var S3Provider
	 */
	private S3Provider $s3_provider;

	/**
	 * Meta key for offloaded timestamp.
	 *
	 * @var string
	 */
	private const META_OFFLOADED_AT = 'nbs3_offloaded_at';

	/**
	 * Meta key for provider name.
	 *
	 * @var string
	 */
	private const META_PROVIDER = 'nbs3_provider';

	/**
	 * Meta key for bucket name.
	 *
	 * @var string
	 */
	private const META_BUCKET = 'nbs3_bucket';

	/**
	 * Constructor.
	 *
	 * @param S3Provider $s3_provider The cloud provider instance.
	 */
	public function __construct( S3Provider $s3_provider ) {
		$this->s3_provider = $s3_provider;
	}

	/**
	 * Register the observer with WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'run' ), 10, 2 );
	}

	/**
	 * Display the offload status of the attachment.
	 *
	 * @param array    $form_fields The form fields for the attachment.
	 * @param \WP_Post $post        The attachment post object.
	 * @return array The modified form fields.
	 */
	public function run( array $form_fields, \WP_Post $post ): array {
		$status_details = $this->get_offload_status_details( $post->ID );

		$form_fields['nbs3_offload_status'] = array(
			'label' => __( 'Offload Status:', 'nobloat-s3-offload' ),
			'input' => 'html',
			'html'  => $this->generate_status_html( $status_details ),
		);

		return $form_fields;
	}

	/**
	 * Get offload status details for an attachment.
	 *
	 * @param int $post_id The attachment post ID.
	 * @return array The offload status details.
	 */
	private function get_offload_status_details( int $post_id ): array {
		if ( $this->is_offloaded( $post_id ) ) {
			return array(
				'status' => $this->get_offloaded_status( $post_id ),
				'color'  => 'green',
			);
		}

		if ( $this->has_errors( $post_id ) ) {
			// Get URL for this settings page: nbs3_media.
			$media_page = admin_url( 'admin.php?page=nbs3_media' );
			$status     = sprintf(
				/* translators: %s: URL to Media page */
				__( 'Offload failed - Action required. View details in <a href="%s">Media</a>', 'nobloat-s3-offload' ),
				esc_url( $media_page )
			);
			return array(
				'status' => $status,
				'color'  => '#D32F2F',
			);
		}

		return array(
			'status' => __( 'Not offloaded yet.', 'nobloat-s3-offload' ),
			'color'  => '#D32F2F',
		);
	}

	/**
	 * Get the offloaded status message.
	 *
	 * @param int $post_id The attachment post ID.
	 * @return string The formatted status message.
	 */
	private function get_offloaded_status( int $post_id ): string {
		$offloaded_at = get_post_meta( $post_id, self::META_OFFLOADED_AT, true );
		$provider     = get_post_meta( $post_id, self::META_PROVIDER, true );
		$bucket       = get_post_meta( $post_id, self::META_BUCKET, true );

		/* translators: %s: cloud provider name (e.g., S3) */
		$status = sprintf( __( 'Offloaded to %s', 'nobloat-s3-offload' ), $provider );

		if ( $bucket ) {
			/* translators: %s: bucket name */
			$status .= sprintf( __( ' (Bucket: %s)', 'nobloat-s3-offload' ), $bucket );
		}

		if ( $offloaded_at ) {
			$formatted_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $offloaded_at );
			/* translators: %s: formatted date and time */
			$status .= sprintf( __( ' on %s', 'nobloat-s3-offload' ), $formatted_date );
		}

		return $status;
	}

	/**
	 * Generate the HTML for displaying the offload status.
	 *
	 * @param array $status_details The offload status details.
	 * @return string The generated HTML.
	 */
	private function generate_status_html( array $status_details ): string {
		return sprintf(
			'<div style="display: flex; align-items: center; height: 100%%; min-height: 30px;">
                <span style="color: %s;">%s</span>
            </div>',
			esc_attr( $status_details['color'] ),
			wp_kses_post( $status_details['status'] )
		);
	}

	/**
	 * Check if attachment has errors.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return bool True if the attachment has errors, false otherwise.
	 */
	private function has_errors( int $attachment_id ): bool {
		$errors = get_post_meta( $attachment_id, 'nbs3_error_log', true );
		return ! empty( $errors );
	}
}
