<?php
namespace BikeRentalPlugin;
defined( 'ABSPATH' ) || exit;

/** Providers own signing/evidence only; core owns invitations, identities and readiness. */
interface WaiverProvider {
	public function available();
	public function sanitize_config( array $input );
	public function settings( array $config );
	public function configuration( array $config ); // true or WP_Error, fail closed.
	public function render( array $context, string $token );
	public function verify_completion( array $context, string $submission ); // normalized proof or WP_Error.
}
