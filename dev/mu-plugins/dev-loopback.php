<?php
/**
 * Plugin Name: Dev — make localhost URLs reachable between containers
 * Description: Local development only. The sites are published as http://localhost:9190 and :9192 on the host, but inside the Docker network "localhost" is the container itself. Requests WordPress makes to those addresses — loopbacks that drive backup jobs, the backup-directory exposure test, migration pulls between the two sites — are re-sent to the compose service names instead.
 *
 * Lives OUTSIDE the plugin directory (dev/mu-plugins/, mounted only by
 * docker-compose) and is excluded from the release ZIP. It must never ship.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( $response, array $args, string $url ) {
		if ( 'local' !== wp_get_environment_type() || ! empty( $args['_ekbm_dev_rewritten'] ) ) {
			return $response;
		}

		$map = [
			'localhost:' . ( getenv( 'WP_PORT' ) ?: '9190' )        => 'wordpress',
			'localhost:' . ( getenv( 'WP_TARGET_PORT' ) ?: '9192' ) => 'wordpress-target',
		];

		$parts = wp_parse_url( $url );
		$key   = ( $parts['host'] ?? '' ) . ':' . ( $parts['port'] ?? '' );

		if ( ! isset( $map[ $key ] ) ) {
			return $response;
		}

		$args['_ekbm_dev_rewritten'] = true;
		$args['headers']             = (array) ( $args['headers'] ?? [] );
		$args['headers']['Host']     = $key;

		$target = 'http://' . $map[ $key ] . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		return wp_remote_request( $target, $args );
	},
	10,
	3
);
