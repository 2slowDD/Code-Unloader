<?php
declare( strict_types=1 );

namespace CodeUnloader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	public function boot(): void {
		Core\Installer::maybe_upgrade();

		// Core engine — always runs on frontend
		if ( ! is_admin() ) {
			( new Core\DequeueEngine() )->init();
			( new Core\InlineBlocker() )->init();
			( new Frontend\FrontendPanel() )->init();
		}

		// Admin screen
		if ( is_admin() ) {
			( new Admin\AdminScreen() )->init();
		}

		// REST API — available on all requests
		add_action( 'rest_api_init', [ new Api\RestController(), 'register_routes' ] );
	}
}
