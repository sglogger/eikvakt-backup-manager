<?php
/**
 * Which files and folders a backup includes.
 *
 * Two layers:
 *
 *   Folder rules  Chosen in the folder tree on the Files tab: a folder path
 *                 (`root/…` or `content/…`) marked `include` or `exclude`.
 *                 The most specific rule wins and applies to everything
 *                 below it — so `content/uploads` can be excluded while
 *                 `content/uploads/2026` is still included, and a folder the
 *                 built-in patterns skip (a cache) can be included on
 *                 purpose.
 *   Patterns      fnmatch() globs: built-in ones (caches, other plugins'
 *                 backup folders) plus the administrator's own (`*.log`).
 *                 They decide wherever no folder rule applies.
 *
 * A size limit, when set, skips larger files regardless.
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

final class File_Rules {

	public const INCLUDE = 'include';
	public const EXCLUDE = 'exclude';

	/** Upper bound of stored rules, so a crafted request cannot bloat the option. */
	private const MAX_RULES = 2000;

	/** @var array<string, string> Folder => include|exclude, longest path first. */
	private array $rules;

	/** @var string[] */
	private array $patterns;

	private int $max_bytes;

	/**
	 * @param array<string, string> $rules     Folder rules.
	 * @param string[]              $patterns  fnmatch() globs.
	 * @param int                   $max_bytes Skip larger files (0 = no limit).
	 */
	public function __construct( array $rules, array $patterns, int $max_bytes = 0 ) {
		$rules = self::sanitize( $rules );
		uksort( $rules, static fn( $a, $b ) => strlen( (string) $b ) <=> strlen( (string) $a ) );

		$this->rules     = $rules;
		$this->patterns  = array_values( $patterns );
		$this->max_bytes = max( 0, $max_bytes );
	}

	/**
	 * Keep only well-formed rules: a logical root, folder names without
	 * traversal, and a known verdict.
	 *
	 * @param mixed $rules Untrusted rules.
	 * @return array<string, string>
	 */
	public static function sanitize( $rules ): array {
		$out = [];
		foreach ( (array) $rules as $path => $verdict ) {
			$path = trim( (string) $path, '/' );
			if ( ! self::is_valid_path( $path ) || ! in_array( $verdict, [ self::INCLUDE, self::EXCLUDE ], true ) ) {
				continue;
			}
			$out[ $path ] = (string) $verdict;
			if ( count( $out ) >= self::MAX_RULES ) {
				break;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function is_valid_path( string $path ): bool {
		if ( 1 !== preg_match( '#^(root|content)(/[^/\x00-\x1f]+)*$#', $path ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Verdict of the most specific folder rule covering a path, if any.
	 */
	public function rule_for( string $path ): ?string {
		foreach ( $this->rules as $folder => $verdict ) {
			if ( $path === $folder || str_starts_with( $path, $folder . '/' ) ) {
				return $verdict;
			}
		}
		return null;
	}

	public function pattern_excluded( string $archive_path ): bool {
		foreach ( $this->patterns as $pattern ) {
			if ( fnmatch( $pattern, $archive_path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a folder (archive path without trailing slash) excluded?
	 */
	public function excluded_dir( string $dir ): bool {
		$rule = $this->rule_for( $dir );
		if ( null !== $rule ) {
			return self::EXCLUDE === $rule;
		}
		return $this->pattern_excluded( $dir . '/' );
	}

	/**
	 * Must the scanner enter a folder? Yes when it is included — and also
	 * when it is excluded but a folder below it is explicitly included.
	 */
	public function descend( string $dir ): bool {
		if ( ! $this->excluded_dir( $dir ) ) {
			return true;
		}
		foreach ( $this->rules as $folder => $verdict ) {
			if ( self::INCLUDE === $verdict && str_starts_with( $folder, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Is a file excluded?
	 */
	public function excluded_file( string $path, int $size ): bool {
		if ( $this->max_bytes > 0 && $size > $this->max_bytes ) {
			return true;
		}

		$rule = $this->rule_for( $path );
		if ( self::INCLUDE === $rule ) {
			// An explicitly included folder overrides the patterns, except
			// for file-name patterns such as `*.log`, which describe files,
			// not places.
			return $this->pattern_excluded_by_name( $path );
		}
		if ( self::EXCLUDE === $rule ) {
			return true;
		}

		return $this->pattern_excluded( $path );
	}

	/**
	 * Patterns that do not mention a folder (`*.log`, `*.DS_Store`) keep
	 * applying inside explicitly included folders.
	 */
	private function pattern_excluded_by_name( string $path ): bool {
		foreach ( $this->patterns as $pattern ) {
			if ( ! str_contains( $pattern, '/' ) && fnmatch( $pattern, basename( $path ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, string>
	 */
	public function rules(): array {
		return $this->rules;
	}
}
