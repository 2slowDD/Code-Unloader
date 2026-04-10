<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DeviceDetector {

	private static ?bool $is_mobile_cache = null;

	public static function is_mobile(): bool {
		if ( null !== self::$is_mobile_cache ) {
			return self::$is_mobile_cache;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// Lightweight UA check — no external library required
		$mobile_keywords = [
			'Mobile', 'Android', 'iPhone', 'iPad', 'iPod',
			'BlackBerry', 'Windows Phone', 'webOS', 'Opera Mini',
			'IEMobile', 'Kindle', 'Silk',
		];

		$result = false;
		foreach ( $mobile_keywords as $keyword ) {
			if ( stripos( $ua, $keyword ) !== false ) {
				$result = true;
				break;
			}
		}

		// Allow override via filter (e.g. WPtouch, Jetpack Mobile)
		$result = (bool) apply_filters( 'code_unloader_is_mobile', $result, $ua );

		self::$is_mobile_cache = $result;
		return $result;
	}

	/**
	 * Returns true if the given rule's device_type matches the current visitor.
	 */
	public static function matches_device( string $device_type ): bool {
		if ( 'all' === $device_type ) {
			return true;
		}
		$is_mobile = self::is_mobile();
		return ( 'mobile' === $device_type ) ? $is_mobile : ! $is_mobile;
	}
}
