<?php
/**
 * Plugin Name:       Pulp Purge Cache
 * Plugin URI:        https://github.com/pulpcovers/pulp-purge-cache
 * Description:       Purges Cloudflare cache when a post is first published (everything) or edited afterwards (just that post's URL). A modernized, dependency-free replacement for the abandoned "Purge Cache" (c-purge-cache) plugin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pulp Covers
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pulp-purge-cache
 */

defined( 'ABSPATH' ) || exit;

define( 'PPC_VERSION', '1.0.0' );
define( 'PPC_OPTION_KEY', 'ppc_settings' );

/**
 * Read a single setting out of the plugin's option array.
 */
function ppc_get_option( string $key, $default = '' ) {
	$settings = get_option( PPC_OPTION_KEY, [] );

	return isset( $settings[ $key ] ) && $settings[ $key ] !== '' ? $settings[ $key ] : $default;
}

/**
 * Post types that should trigger cache purging. Filterable for other CPTs.
 */
function ppc_purge_post_types(): array {
	return apply_filters( 'ppc_purge_post_types', [ 'post' ] );
}

/* -------------------------------------------------------------------------
 * Cloudflare API
 * ---------------------------------------------------------------------- */

/**
 * Low level call to the Cloudflare purge_cache endpoint.
 *
 * @return object|WP_Error
 */
function ppc_cloudflare_request( array $data ) {
	$zone_id   = ppc_get_option( 'zone_id' );
	$api_token = ppc_get_option( 'api_token' );

	if ( ! $zone_id || ! $api_token ) {
		return new WP_Error( 'ppc_missing_credentials', __( 'Cloudflare Zone ID and API Token must be configured before the cache can be purged.', 'pulp-purge-cache' ) );
	}

	$response = wp_remote_post(
		"https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache",
		[
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $data ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['success'] ) ) {
		$message = $body['errors'][0]['message'] ?? __( 'Unknown Cloudflare API error.', 'pulp-purge-cache' );

		return new WP_Error( 'ppc_cloudflare_error', $message, [ 'status' => $status ] );
	}

	return (object) [
		'success' => true,
		'result'  => $body,
	];
}

/**
 * Purge the entire zone cache.
 *
 * @return object|WP_Error
 */
function ppc_purge_everything() {
	return ppc_cloudflare_request( [ 'purge_everything' => true ] );
}

/**
 * Purge a specific set of URLs. Cloudflare limits a single request to 30 URLs,
 * so we chunk automatically.
 *
 * @return object|WP_Error
 */
function ppc_purge_urls( array $urls ) {
	$urls = array_values( array_unique( array_filter( $urls ) ) );

	if ( empty( $urls ) ) {
		return (object) [
			'success' => true,
			'result'  => null,
		];
	}

	$result = null;

	foreach ( array_chunk( $urls, 30 ) as $chunk ) {
		$result = ppc_cloudflare_request( [ 'files' => $chunk ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return $result;
}

/**
 * Build the list of URLs that should be purged when an already-published
 * post is edited: the post's own permalink, optionally the homepage, and any
 * configured extra URLs (with simple placeholder support).
 */
function ppc_build_post_urls( WP_Post $post ): array {
	$frontend_url = untrailingslashit( ppc_get_option( 'frontend_url', get_site_url() ) );
	$permalink    = get_permalink( $post );
	$urls         = [];

	if ( $permalink ) {
		$permalink = str_replace( untrailingslashit( get_site_url() ), $frontend_url, $permalink );
		$urls[]    = untrailingslashit( $permalink );
	}

	if ( ppc_get_option( 'purge_home_url', 'on' ) === 'on' ) {
		$urls[] = $frontend_url;
	}

	$extra_urls_raw = trim( (string) ppc_get_option( 'purge_urls', '' ) );

	if ( $extra_urls_raw !== '' ) {
		$categories = implode( ',', wp_get_post_categories( $post->ID, [ 'fields' => 'slugs' ] ) );
		$tags       = implode( ',', wp_list_pluck( get_the_tags( $post ) ?: [], 'slug' ) );
		$author     = get_the_author_meta( 'user_nicename', $post->post_author );

		$replacements = [
			'%slug%'            => $post->post_name,
			'%author_nicename%' => $author,
			'%categories%'      => $categories,
			'%tags%'            => $tags,
		];

		foreach ( preg_split( '/\r\n|\n|\r/', $extra_urls_raw ) as $line ) {
			$line = trim( str_replace( array_keys( $replacements ), array_values( $replacements ), $line ) );

			if ( $line !== '' && filter_var( $line, FILTER_VALIDATE_URL ) ) {
				$urls[] = untrailingslashit( $line );
			}
		}
	}

	return apply_filters( 'ppc_post_purge_urls', $urls, $post );
}

/* -------------------------------------------------------------------------
 * Hooks: decide "new post" (purge everything) vs "post update" (purge one URL)
 * ---------------------------------------------------------------------- */

add_action( 'transition_post_status', 'ppc_handle_post_status_transition', 10, 3 );

function ppc_handle_post_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, ppc_purge_post_types(), true ) ) {
		return;
	}

	// Newly published (covers manual publish and scheduled posts going live).
	if ( $new_status === 'publish' && $old_status !== 'publish' ) {
		ppc_schedule_purge_everything();

		return;
	}

	// An already-published post was edited and re-saved: purge only its URL.
	if ( $new_status === 'publish' && $old_status === 'publish' ) {
		ppc_schedule_purge_post( $post->ID );

		return;
	}

	// A published post was unpublished/trashed: ordering changes site-wide, so purge everything.
	if ( $old_status === 'publish' && in_array( $new_status, [ 'trash', 'draft', 'pending', 'private' ], true ) ) {
		ppc_schedule_purge_everything();
	}
}

function ppc_schedule_purge_everything(): void {
	if ( ! wp_next_scheduled( 'ppc_purge_everything_event' ) ) {
		wp_schedule_single_event( time() + 5, 'ppc_purge_everything_event' );
	}
}

function ppc_schedule_purge_post( int $post_id ): void {
	if ( ! wp_next_scheduled( 'ppc_purge_post_event', [ $post_id ] ) ) {
		wp_schedule_single_event( time() + 5, 'ppc_purge_post_event', [ $post_id ] );
	}
}

add_action( 'ppc_purge_everything_event', function () {
	ppc_log_purge_result( ppc_purge_everything(), 'everything' );
} );

add_action( 'ppc_purge_post_event', function ( int $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post ) {
		return;
	}

	ppc_log_purge_result( ppc_purge_urls( ppc_build_post_urls( $post ) ), "post #{$post_id}" );
} );

/**
 * Very small logging helper so failures are visible without needing debug tooling.
 */
function ppc_log_purge_result( $result, string $context ): void {
	if ( ! is_wp_error( $result ) ) {
		return;
	}

	do_action( 'ppc_purge_failed', $context, $result );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional, only runs when debug logging is enabled.
		error_log( sprintf( '[Pulp Purge Cache] Failed to purge %s: %s', $context, $result->get_error_message() ) );
	}
}

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_options_page(
		__( 'Pulp Purge Cache', 'pulp-purge-cache' ),
		__( 'Pulp Purge Cache', 'pulp-purge-cache' ),
		'manage_options',
		'pulp-purge-cache',
		'ppc_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'ppc_settings_group', PPC_OPTION_KEY, [
		'type'              => 'array',
		'sanitize_callback' => 'ppc_sanitize_settings',
		'default'           => [],
	] );

	add_settings_section( 'ppc_cloudflare_section', __( 'Cloudflare Credentials', 'pulp-purge-cache' ), '__return_false', 'pulp-purge-cache' );

	add_settings_field( 'zone_id', __( 'Zone ID', 'pulp-purge-cache' ), 'ppc_field_zone_id', 'pulp-purge-cache', 'ppc_cloudflare_section' );
	add_settings_field( 'api_token', __( 'API Token', 'pulp-purge-cache' ), 'ppc_field_api_token', 'pulp-purge-cache', 'ppc_cloudflare_section' );
	add_settings_field( 'frontend_url', __( 'Homepage URL', 'pulp-purge-cache' ), 'ppc_field_frontend_url', 'pulp-purge-cache', 'ppc_cloudflare_section' );

	add_settings_section( 'ppc_update_section', __( 'On Post Update (already-published posts)', 'pulp-purge-cache' ), function () {
		echo '<p>' . esc_html__( 'Editing a post that is already live does not purge the whole cache by default — only the URLs below are purged, so the update appears once the cache naturally expires or immediately for the URLs listed here.', 'pulp-purge-cache' ) . '</p>';
	}, 'pulp-purge-cache' );

	add_settings_field( 'purge_home_url', __( 'Purge Homepage', 'pulp-purge-cache' ), 'ppc_field_purge_home_url', 'pulp-purge-cache', 'ppc_update_section' );
	add_settings_field( 'purge_urls', __( 'Additional URLs', 'pulp-purge-cache' ), 'ppc_field_purge_urls', 'pulp-purge-cache', 'ppc_update_section' );

	add_settings_section( 'ppc_admin_section', __( 'Admin Tools', 'pulp-purge-cache' ), '__return_false', 'pulp-purge-cache' );

	add_settings_field( 'admin_button', __( 'Admin Bar Button', 'pulp-purge-cache' ), 'ppc_field_admin_button', 'pulp-purge-cache', 'ppc_admin_section' );

	add_settings_section( 'ppc_rest_section', __( 'REST Endpoint (optional)', 'pulp-purge-cache' ), function () {
		echo '<p>' . esc_html__( 'Lets an external system trigger a full purge via PUT /wp-json/ppc/v1/purge with an "Authorization: Bearer <secret>" header.', 'pulp-purge-cache' ) . '</p>';
	}, 'pulp-purge-cache' );

	add_settings_field( 'secret', __( 'Endpoint Secret', 'pulp-purge-cache' ), 'ppc_field_secret', 'pulp-purge-cache', 'ppc_rest_section' );
} );

function ppc_sanitize_settings( $input ): array {
	$input = is_array( $input ) ? $input : [];

	return [
		'zone_id'        => sanitize_text_field( $input['zone_id'] ?? '' ),
		'api_token'      => sanitize_text_field( $input['api_token'] ?? '' ),
		'frontend_url'   => esc_url_raw( $input['frontend_url'] ?? get_site_url() ),
		'purge_home_url' => ! empty( $input['purge_home_url'] ) ? 'on' : 'off',
		'purge_urls'     => sanitize_textarea_field( $input['purge_urls'] ?? '' ),
		'admin_button'   => ! empty( $input['admin_button'] ) ? 'on' : 'off',
		'secret'         => sanitize_text_field( $input['secret'] ?? '' ) ?: ppc_get_option( 'secret', wp_generate_uuid4() ),
	];
}

function ppc_field_zone_id(): void {
	printf( '<input type="text" name="%1$s[zone_id]" value="%2$s" class="regular-text" autocomplete="off" />', esc_attr( PPC_OPTION_KEY ), esc_attr( ppc_get_option( 'zone_id' ) ) );
}

function ppc_field_api_token(): void {
	printf( '<input type="password" name="%1$s[api_token]" value="%2$s" class="regular-text" autocomplete="off" />', esc_attr( PPC_OPTION_KEY ), esc_attr( ppc_get_option( 'api_token' ) ) );
	echo '<p class="description">' . esc_html__( 'Create a token from your Cloudflare dashboard, under My Profile > API Tokens, using the "Custom token" template with:', 'pulp-purge-cache' ) . '</p>';
	echo '<ul class="description" style="list-style: disc; margin-left: 1.5em;">'
		. '<li>' . wp_kses( __( '<strong>Permissions:</strong> Zone &rarr; Cache Purge &rarr; Purge', 'pulp-purge-cache' ), [ 'strong' => [] ] ) . '</li>'
		. '<li>' . wp_kses( __( '<strong>Zone Resources:</strong> Include &rarr; Specific zone &rarr; the zone for this site only', 'pulp-purge-cache' ), [ 'strong' => [] ] ) . '</li>'
		. '</ul>';
	echo '<p class="description">' . esc_html__( 'Do not grant any other permissions or zones — this token only needs to purge cache for this one site.', 'pulp-purge-cache' ) . '</p>';
}

function ppc_field_frontend_url(): void {
	printf( '<input type="url" name="%1$s[frontend_url]" value="%2$s" class="regular-text" placeholder="%3$s" />', esc_attr( PPC_OPTION_KEY ), esc_attr( ppc_get_option( 'frontend_url', get_site_url() ) ), esc_attr( get_site_url() ) );
}

function ppc_field_purge_home_url(): void {
	printf(
		'<label><input type="checkbox" name="%1$s[purge_home_url]" %2$s /> %3$s</label>',
		esc_attr( PPC_OPTION_KEY ),
		checked( ppc_get_option( 'purge_home_url', 'on' ), 'on', false ),
		esc_html__( 'Also purge the homepage URL when a post is updated.', 'pulp-purge-cache' )
	);
}

function ppc_field_purge_urls(): void {
	printf(
		'<textarea name="%1$s[purge_urls]" rows="4" class="large-text" placeholder="%2$s">%3$s</textarea>',
		esc_attr( PPC_OPTION_KEY ),
		esc_attr( ppc_get_option( 'frontend_url', get_site_url() ) . '/category/%categories%' ),
		esc_textarea( ppc_get_option( 'purge_urls' ) )
	);
	echo '<p class="description">' . esc_html__( 'One URL per line, purged in addition to the post URL on update. Placeholders:', 'pulp-purge-cache' )
		. ' <code>%slug%</code> <code>%author_nicename%</code> <code>%categories%</code> <code>%tags%</code></p>';
}

function ppc_field_admin_button(): void {
	printf(
		'<label><input type="checkbox" name="%1$s[admin_button]" %2$s /> %3$s</label>',
		esc_attr( PPC_OPTION_KEY ),
		checked( ppc_get_option( 'admin_button', 'off' ), 'on', false ),
		esc_html__( 'Show a "Purge All Cache" button in the admin bar.', 'pulp-purge-cache' )
	);
}

function ppc_field_secret(): void {
	printf(
		'<input type="text" name="%1$s[secret]" value="%2$s" class="regular-text" readonly onclick="this.select()" />',
		esc_attr( PPC_OPTION_KEY ),
		esc_attr( ppc_get_option( 'secret', wp_generate_uuid4() ) )
	);
	echo '<p class="description">' . esc_html__( 'Auto-generated. Leave the REST route disabled by keeping this private; there is no on/off toggle because the secret itself gates access.', 'pulp-purge-cache' ) . '</p>';
}

function ppc_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'pulp-purge-cache' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Pulp Purge Cache', 'pulp-purge-cache' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'ppc_settings_group' );
			do_settings_sections( 'pulp-purge-cache' );
			submit_button();
			?>
		</form>
		<p>
			<button type="button" class="button button-secondary" id="ppc-purge-everything"><?php esc_html_e( 'Purge Everything Now', 'pulp-purge-cache' ); ?></button>
			<span id="ppc-purge-everything-result"></span>
		</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Admin bar button + AJAX
 * ---------------------------------------------------------------------- */

add_action( 'admin_bar_menu', function ( WP_Admin_Bar $admin_bar ) {
	if ( ppc_get_option( 'admin_button', 'off' ) !== 'on' || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$admin_bar->add_menu( [
		'id'    => 'ppc-purge-everything',
		'title' => esc_html__( 'Purge All Cache', 'pulp-purge-cache' ),
	] );
}, 100 );

add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	$is_settings_page = $screen && $screen->id === 'settings_page_pulp-purge-cache';

	if ( ppc_get_option( 'admin_button', 'off' ) !== 'on' && ! $is_settings_page ) {
		return;
	}

	wp_enqueue_script( 'ppc-admin', plugins_url( 'assets/js/admin.js', __FILE__ ), [ 'jquery' ], PPC_VERSION, true );
	wp_localize_script( 'ppc-admin', 'ppcAdmin', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'ppc_admin_action' ),
	] );
} );

add_action( 'wp_ajax_ppc_purge_everything', function () {
	check_ajax_referer( 'ppc_admin_action', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'pulp-purge-cache' ) ], 403 );
	}

	$result = ppc_purge_everything();

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
	}

	wp_send_json_success( [ 'message' => __( 'Cloudflare cache purge requested successfully.', 'pulp-purge-cache' ) ] );
} );

/* -------------------------------------------------------------------------
 * Optional REST endpoint for external/manual purge triggers
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', function () {
	register_rest_route( 'ppc/v1', '/purge', [
		'methods'             => WP_REST_Server::EDITABLE,
		'permission_callback' => function ( WP_REST_Request $request ) {
			$secret   = (string) ppc_get_option( 'secret' );
			$provided = (string) $request->get_header( 'authorization' );

			if ( $secret === '' || $provided === '' ) {
				return false;
			}

			return hash_equals( $secret, str_replace( 'Bearer ', '', $provided ) );
		},
		'callback'            => function () {
			$result = ppc_purge_everything();

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 500 ] );
			}

			return new WP_REST_Response( [ 'success' => true ] );
		},
	] );
} );

/* -------------------------------------------------------------------------
 * Activation / deactivation
 * ---------------------------------------------------------------------- */

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'ppc_purge_everything_event' );
} );
