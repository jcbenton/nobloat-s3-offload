<?php
/**
 * Admin navigation menu template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the admin page URL for a given page slug.
 *
 * @param string $page The page slug.
 * @return string The admin page URL.
 */
function nbs3_get_admin_page_url( string $page ): string {
	return get_admin_url( null, "admin.php?page={$page}" );
}

$nbs3_menu_items = array(
	'plugin-status' => array(
		'title' => __( 'Status', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3' ),
	),
	'settings'      => array(
		'title' => __( 'Settings', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_settings' ),
	),
	'connection'    => array(
		'title' => __( 'Connection', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_connection' ),
	),
	'media'         => array(
		'title' => __( 'Media', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_media' ),
	),
	'bricks'        => array(
		'title' => __( 'Bricks', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_bricks' ),
	),
	'aws-guide'     => array(
		'title' => __( 'AWS Guide', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_aws_guide' ),
	),
	'documentation' => array(
		'title' => __( 'Documentation', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_documentation' ),
	),
	'about'         => array(
		'title' => __( 'About', 'nobloat-s3-offload' ),
		'url'   => nbs3_get_admin_page_url( 'nbs3_about' ),
	),
);

/**
 * Generate a menu item HTML element.
 *
 * @param array  $item The menu item data with 'title' and 'url' keys.
 * @param string $page The page slug to check if active.
 * @return string The HTML for the menu item.
 */
function nbs3_generate_menu_item( array $item, string $page ): string {
	$class = nbs3_is_settings_page( $page ) ? 'active' : '';
	return sprintf(
		'<a href="%s" class="%s">%s</a>',
		esc_url( $item['url'] ),
		esc_attr( $class ),
		esc_html( $item['title'] )
	);
}
?>

<div class="nbs3-menu">
	<nav>
		<?php foreach ( $nbs3_menu_items as $nbs3_slug => $nbs3_item ) : ?>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nbs3_generate_menu_item() escapes internally.
			echo nbs3_generate_menu_item( $nbs3_item, $nbs3_slug );
			?>
		<?php endforeach; ?>
	</nav>
</div>
