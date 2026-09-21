<?php
/** License, activation and audit data intentionally survive uninstall. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
wp_clear_scheduled_hook( 'ntlc_cleanup' );
// No implicit data removal, remote calls, or effects on licensed product sites.
