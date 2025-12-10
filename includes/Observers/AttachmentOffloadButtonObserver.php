<?php

namespace NBS3\Observers;

use NBS3\S3Provider;
use NBS3\Interfaces\ObserverInterface;
use NBS3\Traits\OffloaderTrait;

class AttachmentOffloadButtonObserver implements ObserverInterface
{
    use OffloaderTrait;

    /**
     * Cloud provider instance.
     *
     * @var S3Provider
     */
    private S3Provider $s3Provider;

    /**
     * Constructor.
     *
     * @param S3Provider $s3Provider The cloud provider instance.
     */
    public function __construct(S3Provider $s3Provider)
    {
        $this->s3Provider = $s3Provider;
    }

    /**
     * Register the observer with WordPress hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('attachment_fields_to_edit', [$this, 'run'], 10, 2);
        add_action('wp_ajax_nbs3_offload_single_attachment', [$this, 'handle_ajax_offload']);
        add_action('admin_footer', [$this, 'enqueue_scripts']);
    }

    /**
     * Add the "Offload Now" button to attachment fields.
     *
     * @param array    $form_fields The form fields for the attachment.
     * @param \WP_Post $post        The attachment post object.
     * @return array The modified form fields.
     */
    public function run(array $form_fields, \WP_Post $post): array
    {
        // Add the button if the attachment is not already offloaded
        // This includes both new attachments and those with failed offload attempts
        if (!$this->is_offloaded($post->ID)) {
            $buttonText = $this->has_errors($post->ID) ?
                __('Retry Offload', 'nobloat-s3-offload') :
                __('Offload Now', 'nobloat-s3-offload');

            $form_fields['nbs3_offload_button'] = [
                'label' => __('Cloud Offload:', 'nobloat-s3-offload'),
                'input' => 'html',
                'html'  => $this->generateOffloadButtonHtml($post->ID, $buttonText),
            ];
        }

        return $form_fields;
    }

    /**
     * Generate the HTML for the "Offload Now" button.
     *
     * @param int $attachment_id The attachment ID.
     * @param string|null $button_text The text to display on the button.
     * @return string The generated HTML.
     */
    private function generateOffloadButtonHtml(int $attachment_id, ?string $button_text = null): string
    {
        $nonce = wp_create_nonce('nbs3_offload_single_' . $attachment_id);
        $button_text = $button_text ?: __('Offload Now', 'nobloat-s3-offload');

        // Always use blue button style
        $button_style = 'background: #0073aa; color: white; border-color: #0073aa; min-width: 120px;';

        return sprintf(
            '<div style="min-height: 30px;">
                <div style="margin-bottom: 8px;">
                    <button type="button" class="button nbs3-offload-single-btn" 
                            data-attachment-id="%d" 
                            data-nonce="%s"
                            data-original-text="%s"
                            style="%s">
                        %s
                    </button>
                </div>
                <div class="nbs3-processing-indicator" style="display: none; align-items: center; gap: 8px; margin-top: 5px;">
                    <span class="spinner" style="visibility: visible; float: none; margin: 0;"></span>
                    <span style="color: #666; font-style: italic; font-size: 13px;">%s</span>
                </div>
                <div class="nbs3-offload-status" style="display: none; margin-top: 5px;"></div>
            </div>',
            esc_attr($attachment_id),
            esc_attr($nonce),
            esc_attr($button_text),
            esc_attr($button_style),
            esc_html($button_text),
            esc_html__('Uploading to cloud storage, please wait...', 'nobloat-s3-offload')
        );
    }

    /**
     * Handle the Ajax request for offloading a single attachment.
     *
     * @return void
     */
    public function handle_ajax_offload(): void
    {
        // Verify the request
        if (!$this->verify_ajax_request()) {
            wp_send_json_error([
                'message' => __('Invalid request.', 'nobloat-s3-offload')
            ]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified in verify_ajax_request(), attachment_id sanitized by intval()
        $attachment_id = intval($_POST['attachment_id']);

        // Verify attachment exists and is not already offloaded
        if (!get_post($attachment_id) || $this->is_offloaded($attachment_id)) {
            wp_send_json_error([
                'message' => __('Invalid attachment or already offloaded.', 'nobloat-s3-offload')
            ]);
        }

        try {
            // Use the existing CloudAttachmentUploader service
            $uploader = new \NBS3\Services\CloudAttachmentUploader($this->s3Provider);
            $result = $uploader->uploadAttachment($attachment_id);

            if ($result) {
                wp_send_json_success([
                    'message' => __('Attachment successfully offloaded to cloud storage.', 'nobloat-s3-offload'),
                    'attachment_id' => $attachment_id
                ]);
            } else {
                // Check for specific error messages (sanitized for safety)
                $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
                $error_message = !empty($errors) ?
                    wp_kses((is_array($errors) ? implode('; ', $errors) : $errors), []) :
                    __('Failed to offload attachment. Please try again.', 'nobloat-s3-offload');

                wp_send_json_error([
                    'message' => $error_message
                ]);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: error message */
                    __('Error: %s', 'nobloat-s3-offload'),
                    wp_kses($e->getMessage(), [])
                )
            ]);
        }
    }

    /**
     * Verify the Ajax request is valid.
     *
     * @return bool
     */
    private function verify_ajax_request(): bool
    {
        if (!current_user_can('upload_files')) {
            return false;
        }

        if (!isset($_POST['attachment_id']) || !isset($_POST['nonce'])) {
            return false;
        }

        $attachment_id = intval($_POST['attachment_id']);
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));

        return wp_verify_nonce($nonce, 'nbs3_offload_single_' . $attachment_id);
    }

    /**
     * Check if attachment has errors.
     *
     * @param int $attachment_id The attachment ID.
     * @return bool
     */
    private function has_errors(int $attachment_id): bool
    {
        $errors = get_post_meta($attachment_id, 'nbs3_error_log', true);
        return !empty($errors);
    }

    /**
     * Enqueue scripts for the offload button functionality.
     *
     * @return void
     */
    public function enqueue_scripts(): void
    {
        if (!$this->is_media_page()) {
            return;
        }

?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $(document).on('click', '.nbs3-offload-single-btn', function(e) {
                    e.preventDefault();

                    var $button = $(this);
                    var $container = $button.closest('div').parent(); // Get the main container
                    var $status = $container.find('.nbs3-offload-status');
                    var $processingIndicator = $container.find('.nbs3-processing-indicator');
                    var attachmentId = $button.data('attachment-id');
                    var nonce = $button.data('nonce');
                    var originalText = $button.data('original-text');

                    // Show processing state
                    $button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'nobloat-s3-offload')); ?>');
                    $processingIndicator.css('display', 'flex');
                    $status.hide();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nbs3_offload_single_attachment',
                            attachment_id: attachmentId,
                            nonce: nonce
                        },
                        success: function(response) {
                            $processingIndicator.hide();

                            // Create status span safely (XSS protection)
                            var $span = $('<span>').css({'font-weight': '500'});
                            var message = response.data && response.data.message ? response.data.message : '';

                            if (response.success) {
                                $span.css('color', '#00a32a').text('✓ ' + message);
                                $status.empty().append($span).show();

                                // Hide the button after successful offload
                                $button.fadeOut(300, function() {
                                    $(this).remove();
                                });

                                // Reload the attachment details to show updated status
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $span.css('color', '#d63638').text('✗ ' + message);
                                $status.empty().append($span).show();
                                $button.prop('disabled', false).text(originalText);
                            }
                        },
                        error: function() {
                            $processingIndicator.hide();
                            var $span = $('<span>').css({'color': '#d63638', 'font-weight': '500'}).text('✗ <?php echo esc_js(__('Network error. Please try again.', 'nobloat-s3-offload')); ?>');
                            $status.empty().append($span).show();
                            $button.prop('disabled', false).text(originalText);
                        }
                    });
                });
            });
        </script>
<?php
    }

    /**
     * Check if we're on a media-related page.
     *
     * @return bool
     */
    private function is_media_page(): bool
    {
        $current_screen = get_current_screen();

        return $current_screen && (
            $current_screen->id === 'attachment' ||
            $current_screen->base === 'upload' ||
            $current_screen->base === 'media'
        );
    }
}
