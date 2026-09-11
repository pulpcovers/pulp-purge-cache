<?php
// Exit if uninstall not called from WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ppc_settings' );
wp_clear_scheduled_hook( 'ppc_purge_everything_event' );
