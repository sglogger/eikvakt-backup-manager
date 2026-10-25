<?php
/**
 * Serialisation-safe search and replace, for moving a site to a new domain
 * or path.
 *
 * A plain string replacement breaks PHP-serialised data: `s:19:"http://old.example"`
 * becomes invalid as soon as the length changes, and WordPress silently
 * discards the whole option. So serialised values are unserialised, replaced
 * recursively, and serialised again. JSON-escaped occurrences
 * (`https:\/\/old.example`, as found in block attributes and page-builder
 * data) are replaced as well.
 *
 * Security: unserialize() runs with `allowed_classes => false`, so no object
 * from the backup is ever instantiated — a crafted backup cannot trigger
 * object injection. Objects come back as __PHP_Incomplete_Class, are left
 * untouched, and serialise back to exactly what they were.
 *
 * Free of WordPress: unit-tested on its own.
 *
 * @package EikvaktBackupManager
 */

declare( strict_types = 1 );

namespace EikvaktBackupManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Search_Replace {

	/**
	 * Normalise user-entered pairs: drop empty and identity pairs, add the
	 * JSON-escaped variant of each, longest search string first so that
	 * `https://www.old.example` is replaced before `old.example` is.
	 *
	 * @param array<int, array{0:string, 1:string}> $pairs [from, to] pairs.
	 * @return array<int, array{0:string, 1:string}>
	 */
	public static function expand_pairs( array $pairs ): array {
		$out = [];

		foreach ( $pairs as $pair ) {
			$from = (string) ( $pair[0] ?? '' );
			$to   = (string) ( $pair[1] ?? '' );

			if ( '' === $from || $from === $to ) {
				continue;
			}

			$out[ $from ] = $to;

			$escaped_from = str_replace( '/', '\\/', $from );
			if ( $escaped_from !== $from ) {
				$out[ $escaped_from ] = str_replace( '/', '\\/', $to );
			}
		}

		uksort( $out, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );

		$result = [];
		foreach ( $out as $from => $to ) {
			$result[] = [ (string) $from, (string) $to ];
		}
		return $result;
	}

	/**
	 * Replace in any value: plain strings, serialised strings (recursively,
	 * including serialised data inside serialised data), and arrays.
	 *
	 * @param mixed                                 $value Value.
	 * @param array<int, array{0:string, 1:string}> $pairs Expanded pairs.
	 * @return mixed
	 */
	public static function replace( $value, array $pairs ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace( $item, $pairs );
			}
			return $value;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( ! self::contains_any( $value, $pairs ) ) {
			return $value;
		}

		if ( self::looks_serialized( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false: no object is ever instantiated; malformed data falls through to the plain replacement below.
			$data = @unserialize( $value, [ 'allowed_classes' => false ] );

			if ( false !== $data || 'b:0;' === $value ) {
				$replaced = self::replace_data( $data, $pairs );
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- re-serialising data that was serialised to begin with.
				return $replaced === $data ? $value : serialize( $replaced );
			}
		}

		return self::replace_string( $value, $pairs );
	}

	/**
	 * @param mixed                                 $data  Unserialised data.
	 * @param array<int, array{0:string, 1:string}> $pairs Pairs.
	 * @return mixed
	 */
	private static function replace_data( $data, array $pairs ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $item ) {
				$data[ $key ] = self::replace_data( $item, $pairs );
			}
			return $data;
		}

		if ( is_string( $data ) ) {
			return self::replace( $data, $pairs );
		}

		// Objects (incomplete classes), numbers, booleans, null: untouched.
		return $data;
	}

	/**
	 * @param array<int, array{0:string, 1:string}> $pairs Pairs.
	 */
	private static function replace_string( string $value, array $pairs ): string {
		return strtr( $value, array_column( $pairs, 1, 0 ) );
	}

	/**
	 * @param array<int, array{0:string, 1:string}> $pairs Pairs.
	 */
	public static function contains_any( string $value, array $pairs ): bool {
		foreach ( $pairs as $pair ) {
			if ( str_contains( $value, $pair[0] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function looks_serialized( string $value ): bool {
		$value = trim( $value );
		if ( 'N;' === $value ) {
			return true;
		}
		return 1 === preg_match( '/^([adObisC]):[0-9:]/', $value ) && in_array( substr( $value, -1 ), [ ';', '}' ], true );
	}
}
