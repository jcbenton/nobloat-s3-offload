<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div id="nbs3">
	<div class="wrap">
		<div class="nbs3-documentation nbs3-about-page">

			<div class="nbs3-about-main">
				<div class="nbs3-section">
					<div class="nbs3-section-header">
						<h2><?php esc_html_e( 'About Nobloat S3 Offload', 'nobloat-s3-offload' ); ?></h2>
					</div>
					<div class="nbs3-section-content">
						<p><?php esc_html_e( 'This plugin is completely free and open source. No premium tiers, no feature gates, no upsells - just a straightforward tool that does what it says.', 'nobloat-s3-offload' ); ?></p>

						<h3><?php esc_html_e( 'The No-Bloat Philosophy', 'nobloat-s3-offload' ); ?></h3>
						<p><?php esc_html_e( 'Most WordPress plugins start simple, then grow into bloated monstrosities packed with features nobody asked for, aggressive upsells, and megabytes of unnecessary code. Nobloat S3 Offload takes the opposite approach: do one thing well, keep the codebase clean, and respect your server resources.', 'nobloat-s3-offload' ); ?></p>

						<h3><?php esc_html_e( 'About the Author', 'nobloat-s3-offload' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: %s: Link to Mailborder Systems website */
								esc_html__( 'Nobloat S3 Offload was created by Jerry Benton, founder of %s and the developer behind MailScanner v5, the open source email security system protecting millions of mailboxes worldwide.', 'nobloat-s3-offload' ),
								'<a href="https://www.mailborder.com" target="_blank" rel="noopener noreferrer">Mailborder Systems</a>'
							);
							?>
						</p>
					</div>
				</div>
			</div>

			<div class="nbs3-about-sidebar">
				<div class="nbs3-section">
					<div class="nbs3-section-header" style="text-align: center;">
						<h2><?php esc_html_e( 'Support Development', 'nobloat-s3-offload' ); ?></h2>
					</div>
					<div class="nbs3-section-content" style="text-align: center;">
						<p><?php esc_html_e( 'If this plugin saves you time or money, consider buying me a coffee. Your support helps fund continued development and maintenance.', 'nobloat-s3-offload' ); ?></p>
						<p>
							<a href="https://donate.stripe.com/3cIfZi81NbxX9CX4uybfO01" target="_blank" rel="noopener noreferrer" class="button button-primary">
								<?php esc_html_e( 'Donate via Stripe', 'nobloat-s3-offload' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>

		</div>
	</div>
</div>

<style>
.nbs3-about-page {
	display: flex;
	gap: 30px;
	max-width: 1200px;
}

.nbs3-about-main {
	flex: 1;
}

.nbs3-about-sidebar {
	width: 300px;
	flex-shrink: 0;
}

@media screen and (max-width: 960px) {
	.nbs3-about-page {
		flex-direction: column;
	}

	.nbs3-about-sidebar {
		width: 100%;
		max-width: 400px;
	}
}
</style>
