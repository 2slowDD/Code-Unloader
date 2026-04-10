<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PatternMatcher {

	/**
	 * Test if a URL matches a rule's pattern.
	 *
	 * @param object $rule  stdClass with url_pattern, match_type
	 * @param string $url   Normalized current page URL
	 */
	public static function match( object $rule, string $url ): bool {
		return match ( $rule->match_type ) {
			'exact'    => self::match_exact( $rule->url_pattern, $url ),
			'wildcard' => self::match_wildcard( $rule->url_pattern, $url ),
			'regex'    => self::match_regex( $rule->url_pattern, $url ),
			default    => false,
		};
	}

	private static function match_exact( string $pattern, string $url ): bool {
		return $pattern === $url;
	}

	private static function match_wildcard( string $pattern, string $url ): bool {
		// fnmatch supports * (any chars) and ? (single char)
		return fnmatch( $pattern, $url, FNM_CASEFOLD );
	}

	private static function match_regex( string $pattern, string $url ): bool {
		// Auto-wrap with delimiters if not present
		$delimited = self::ensure_delimiter( $pattern );
		if ( null === $delimited ) {
			return false;
		}

		// Guard against catastrophic backtracking
		$prev_limit = ini_get( 'pcre.backtrack_limit' );
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_set -- Temporary limit to prevent ReDoS.
		ini_set( 'pcre.backtrack_limit', '100000' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing preg_match warnings gracefully.
		set_error_handler( static function (): bool { return true; } );
		$result = preg_match( $delimited, $url );
		restore_error_handler();

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_ini_set -- Restoring original limit.
		ini_set( 'pcre.backtrack_limit', (string) $prev_limit );

		return $result === 1;
	}

	/**
	 * Validate a pattern string and return a descriptive error or null on success.
	 */
	public static function validate( string $pattern, string $match_type ): ?string {
		if ( '' === trim( $pattern ) ) {
			return __( 'Pattern cannot be empty.', 'code-unloader' );
		}

		if ( 'regex' === $match_type ) {
			$delimited = self::ensure_delimiter( $pattern );
			if ( null === $delimited ) {
				return __( 'Pattern contains invalid characters for regex.', 'code-unloader' );
			}

			// Capture PHP warnings from preg_match
			$error = null;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing preg_match compile errors for user feedback.
			set_error_handler( function ( int $errno, string $errstr ) use ( &$error ): bool {
				$error = $errstr;
				return true;
			} );
			@preg_match( $delimited, '' );
			restore_error_handler();

			if ( null !== $error ) {
				/* translators: %s: regex error message */
				return sprintf( __( 'Invalid regex: %s', 'code-unloader' ), $error );
			}
		}

		return null; // valid
	}

	/**
	 * Ensure pattern has regex delimiters. Returns null on failure.
	 */
	public static function ensure_delimiter( string $pattern ): ?string {
		// Already has a delimiter?
		if ( strlen( $pattern ) >= 2 && in_array( $pattern[0], [ '/', '#', '~', '!' ], true ) ) {
			return $pattern;
		}
		// Wrap with ~ delimiter
		$escaped = str_replace( '~', '\\~', $pattern );
		return '~' . $escaped . '~';
	}

	/**
	 * Normalize a URL for storage and lookup.
	 * Strips query string, fragment, trailing slash (except root), lowercases scheme+host.
	 */
	public static function normalize_url( string $url ): string {
		// Allow filter override
		$normalized = (string) apply_filters( 'code_unloader_normalize_url', null, $url );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( $url );
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host']   ?? '' );
		$path   = $parts['path'] ?? '/';

		// Remove trailing slash unless root
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $scheme . '://' . $host . $path;
	}
}
