<?php
/**
 * Dependency injection container.
 *
 * @package NoBloat_S3_Offload
 * @subpackage Core
 */

namespace NBS3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Class Container
 *
 * A simple dependency injection container for managing service registration
 * and retrieval throughout the plugin.
 */
class Container {

	/**
	 * Registered services.
	 *
	 * @var array
	 */
	private array $services = array();

	/**
	 * Instantiated service instances.
	 *
	 * @var array
	 */
	private array $instances = array();

	/**
	 * Register a service with the container.
	 *
	 * @param string $id       The service identifier.
	 * @param mixed  $concrete The service definition or instance.
	 * @return self The container instance for method chaining.
	 */
	public function register( string $id, $concrete ): self {
		$this->services[ $id ] = $concrete;
		return $this;
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $id The service identifier.
	 * @return mixed The service instance.
	 * @throws \Exception If the service is not found.
	 */
	public function get( string $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $id is a class name constant, not user input.
			throw new \Exception( "Service '$id' not found in container" );
		}

		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( $this->services[ $id ] instanceof \Closure ) {
			$this->instances[ $id ] = $this->services[ $id ]( $this );
		} else {
			$this->instances[ $id ] = $this->services[ $id ];
		}

		return $this->instances[ $id ];
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $id The service identifier.
	 * @return bool True if the service exists, false otherwise.
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] );
	}
}
