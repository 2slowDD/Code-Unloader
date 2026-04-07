<?php
/**
 * Plugin Name: Code Unloader
 * Plugin URI:  https://wpservice.pro/
 * Description: Per-page JavaScript & CSS asset management. Surgically dequeue scripts and styles on any page using exact, wildcard, or regex URL rules.
 * Version:     1.4.2
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author:      Dalibor Druzinec
 * Author URI:  https://wpservice.pro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: code-unloader
 */

declare( strict_types=1 );

namespace CodeUnloader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants
define( 'CDUNLOADER_VERSION',     '1.4.2' );
define( 'CDUNLOADER_FILE',        __FILE__ );
define( 'CDUNLOADER_DIR',         plugin_dir_path( __FILE__ ) );
define( 'CDUNLOADER_URL',         plugin_dir_url( __FILE__ ) );
define( 'CDUNLOADER_DB_VERSION',  '1.2' );
define( 'CDUNLOADER_OPTION_KILL', 'cdunloader_kill_switch' );
define( 'CDUNLOADER_OPTION_DBVER','cdunloader_db_version' );

// PSR-4 autoloader
spl_autoload_register( function ( string $class ): void {
	$prefix = 'CodeUnloader\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
	$file      = CDUNLOADER_DIR . 'src/' . $relative . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Activation / Deactivation / Uninstall hooks
register_activation_hook(   __FILE__, [ Core\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core\Installer::class, 'deactivate' ] );

// Boot the plugin
add_action( 'plugins_loaded', function (): void {
	( new Plugin() )->boot();
} );
