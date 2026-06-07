<?php
/**
 * WP Async Request
 *
 * @package NoBloat_S3_Offload
 */

namespace NBS3\Abstracts\WP_Background_Processing;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract WP_Async_Request class.
 *
 * Handles async requests for background processing.
 *
 * @abstract
 */
abstract class WP_Async_Request {

	/**
	 * Prefix for the identifier.
	 *
	 * @var string
	 */
	protected $prefix = 'wp';

	/**
	 * Action name for the async request.
	 *
	 * @var string
	 */
	protected $action = 'async_request';

	/**
	 * Unique identifier for the async request.
	 *
	 * @var string
	 */
	protected $identifier;

	/**
	 * Data to be sent with the async request.
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Initiate new async request.
	 */
	public function __construct() {
		$this->identifier = $this->prefix . '_' . $this->action;

		/*
		 * Background processing must only be triggerable by an authenticated
		 * request. The upstream WP_Background_Processing library historically
		 * also registered wp_ajax_nopriv_*, which has been the root cause of
		 * several CVEs (e.g. WP All Import, WC PDF Invoices) where any visitor
		 * with a leaked/cached nonce could re-dispatch the queue. We register
		 * only the authenticated handler, and maybe_handle() additionally
		 * gates on is_user_logged_in().
		 */
		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}

	/**
	 * Set data used during the request.
	 *
	 * @param array $data Data.
	 *
	 * @return $this
	 */
	public function data( $data ) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Dispatch the async request.
	 *
	 * @return array|WP_Error|false HTTP Response array, WP_Error on failure, or false if not attempted.
	 */
	public function dispatch() {
		$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		$args = $this->get_post_args();

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Get query args.
	 *
	 * @return array
	 */
	protected function get_query_args() {
		if ( property_exists( $this, 'query_args' ) ) {
			return $this->query_args;
		}

		$args = array(
			'action' => $this->identifier,
			'nonce'  => wp_create_nonce( $this->identifier ),
		);

		/**
		 * Filters the query arguments used during an async request.
		 *
		 * @param array $args Query arguments.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Third-party library, identifier is prefixed.
		return apply_filters( $this->identifier . '_query_args', $args );
	}

	/**
	 * Get query URL.
	 *
	 * @return string
	 */
	protected function get_query_url() {
		if ( property_exists( $this, 'query_url' ) ) {
			return $this->query_url;
		}

		$url = admin_url( 'admin-ajax.php' );

		/**
		 * Filters the query URL used during an async request.
		 *
		 * @param string $url Query URL.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Third-party library, identifier is prefixed.
		return apply_filters( $this->identifier . '_query_url', $url );
	}

	/**
	 * Get post args.
	 *
	 * @return array
	 */
	protected function get_post_args() {
		if ( property_exists( $this, 'post_args' ) ) {
			return $this->post_args;
		}

		$args = array(
			'timeout'   => 5,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE, // Passing cookies ensures request is performed as initiating user.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // Local requests, fine to pass false.
		);

		/**
		 * Filters the post arguments used during an async request.
		 *
		 * @param array $args Post arguments.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Third-party library, identifier is prefixed.
		return apply_filters( $this->identifier . '_post_args', $args );
	}

	/**
	 * Maybe handle a dispatched request.
	 *
	 * Check for correct nonce and pass to handler.
	 *
	 * @return void|mixed
	 */
	public function maybe_handle() {
		// Don't lock up other requests while processing.
		session_write_close();

		check_ajax_referer( $this->identifier, 'nonce' );

		/*
		 * Defence in depth: the wp_ajax_nopriv_* registration was removed in
		 * the constructor, so this branch should normally never fire from an
		 * unauthenticated request. We re-check is_user_logged_in() here in
		 * case a future change re-introduces the nopriv hook.
		 */
		if ( ! is_user_logged_in() ) {
			wp_die( -1, 403 );
		}

		/*
		 * Defence in depth: the dispatch nonce is only ever minted in a
		 * privileged (manage_options) context, but a logged-in low-privilege
		 * user who somehow obtained it has no business driving the background
		 * queue. Require a capability as well. Filterable so other consumers of
		 * this vendored library can relax or change it.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Third-party library, identifier is prefixed.
		$required_capability = apply_filters( $this->identifier . '_capability', 'manage_options' );
		if ( ! empty( $required_capability ) && ! current_user_can( $required_capability ) ) {
			wp_die( -1, 403 );
		}

		$this->handle();

		return $this->maybe_wp_die();
	}

	/**
	 * Should the process exit with wp_die?
	 *
	 * @param mixed $return What to return if filter says don't die, default is null.
	 *
	 * @return void|mixed
	 */
	protected function maybe_wp_die( $return = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.returnFound -- Third-party library WP_Background_Processing.
		/**
		 * Filters whether wp_die should be used.
		 *
		 * @param bool $wp_die Whether to use wp_die.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Third-party library, identifier is prefixed.
		if ( apply_filters( $this->identifier . '_wp_die', true ) ) {
			wp_die();
		}

		return $return;
	}

	/**
	 * Handle a dispatched request.
	 *
	 * Override this method to perform any actions required
	 * during the async request.
	 *
	 * @return void
	 */
	abstract protected function handle();
}
