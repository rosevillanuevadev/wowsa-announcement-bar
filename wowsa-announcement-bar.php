<?php
/**
 * Plugin Name:       WOWSA Announcement Bar
 * Plugin URI:        https://github.com/rosevillanuevadev/wowsa-announcement-bar
 * Description:       Reusable institutional announcement bar for WOWSA initiatives. One announcement at a time, displayed above the primary navigation. Fully configurable from the WordPress admin — no code changes required to reuse it.
 * Version:           1.0.2
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            WOWSA
 * License:           GPL-2.0-or-later
 * Text Domain:       wowsa-announcement-bar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WOWSA_AB_VERSION', '1.0.2' );
define( 'WOWSA_AB_OPTION', 'wowsa_announcement_bar' );
define( 'WOWSA_AB_URL', plugin_dir_url( __FILE__ ) );
define( 'WOWSA_AB_PATH', plugin_dir_path( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Settings model
 * ---------------------------------------------------------------------- */

function wowsa_ab_defaults() {
	return array(
		'enabled'         => 0,
		'lockup_id'       => 0,
		'lockup_alt'      => '',
		'message'         => '',
		'cta_text'        => '',
		'cta_url'         => '',
		'cta_new_tab'     => 1,
		'start_date'      => '',
		'end_date'        => '',
		'dismissible'     => 1,
		'exclude_front'   => 1,
		'exclude_ids'     => '',
		'exclude_slugs'   => '',
	);
}

function wowsa_ab_settings() {
	$saved = get_option( WOWSA_AB_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, wowsa_ab_defaults() );
}

function wowsa_ab_sanitize( $input ) {
	$defaults = wowsa_ab_defaults();
	$out      = array();

	$out['enabled']       = empty( $input['enabled'] ) ? 0 : 1;
	$out['dismissible']   = empty( $input['dismissible'] ) ? 0 : 1;
	$out['cta_new_tab']   = empty( $input['cta_new_tab'] ) ? 0 : 1;
	$out['exclude_front'] = empty( $input['exclude_front'] ) ? 0 : 1;

	$out['lockup_id']  = isset( $input['lockup_id'] ) ? absint( $input['lockup_id'] ) : 0;
	$out['lockup_alt'] = isset( $input['lockup_alt'] ) ? sanitize_text_field( $input['lockup_alt'] ) : '';
	$out['message']    = isset( $input['message'] ) ? sanitize_text_field( $input['message'] ) : '';
	$out['cta_text']   = isset( $input['cta_text'] ) ? sanitize_text_field( $input['cta_text'] ) : '';
	$out['cta_url']    = isset( $input['cta_url'] ) ? esc_url_raw( trim( (string) $input['cta_url'] ) ) : '';

	foreach ( array( 'start_date', 'end_date' ) as $key ) {
		$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		$out[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	// Comma or newline separated list of post/page IDs.
	$ids = isset( $input['exclude_ids'] ) ? (string) $input['exclude_ids'] : '';
	$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $ids ) ) );
	$out['exclude_ids'] = implode( ', ', array_unique( $ids ) );

	// Comma or newline separated list of slugs / URL path fragments.
	$slugs = isset( $input['exclude_slugs'] ) ? (string) $input['exclude_slugs'] : '';
	$slugs = array_filter( array_map(
		static function ( $slug ) {
			return sanitize_title( trim( $slug ) );
		},
		preg_split( '/[\s,]+/', $slugs )
	) );
	$out['exclude_slugs'] = implode( ', ', array_unique( $slugs ) );

	return wp_parse_args( $out, $defaults );
}

/* -------------------------------------------------------------------------
 * Display rules
 * ---------------------------------------------------------------------- */

/**
 * Is the announcement live right now (enabled + inside its schedule window)?
 */
function wowsa_ab_is_active( $settings = null ) {
	$s = $settings ? $settings : wowsa_ab_settings();

	if ( empty( $s['enabled'] ) || '' === trim( (string) $s['message'] ) ) {
		return false;
	}

	$today = current_time( 'Y-m-d' );

	if ( ! empty( $s['start_date'] ) && $today < $s['start_date'] ) {
		return false;
	}
	if ( ! empty( $s['end_date'] ) && $today > $s['end_date'] ) {
		return false;
	}

	return true;
}

/**
 * Should the bar render on the page currently being viewed?
 * Institutional pages: yes. Conversion-focused landing pages: no.
 */
function wowsa_ab_should_display() {
	$s = wowsa_ab_settings();

	if ( ! wowsa_ab_is_active( $s ) ) {
		return false;
	}

	if ( ! empty( $s['exclude_front'] ) && ( is_front_page() || is_home() ) ) {
		return false;
	}

	$post_id = get_queried_object_id();

	if ( $post_id && '' !== $s['exclude_ids'] ) {
		$excluded = array_map( 'absint', array_filter( array_map( 'trim', explode( ',', $s['exclude_ids'] ) ) ) );
		if ( in_array( (int) $post_id, $excluded, true ) ) {
			return false;
		}
	}

	if ( '' !== $s['exclude_slugs'] ) {
		$slugs = array_filter( array_map( 'trim', explode( ',', $s['exclude_slugs'] ) ) );
		$path  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path  = strtolower( wp_parse_url( $path, PHP_URL_PATH ) );

		foreach ( $slugs as $slug ) {
			if ( '' !== $slug && false !== strpos( $path, '/' . $slug ) ) {
				return false;
			}
		}
	}

	/**
	 * Final say for themes / other plugins.
	 *
	 * add_filter( 'wowsa_announcement_bar_display', '__return_false' );
	 */
	return (bool) apply_filters( 'wowsa_announcement_bar_display', true );
}

/* -------------------------------------------------------------------------
 * Front-end rendering
 * ---------------------------------------------------------------------- */

function wowsa_ab_assets() {
	if ( ! wowsa_ab_should_display() ) {
		return;
	}

	wp_enqueue_style(
		'wowsa-announcement-bar',
		WOWSA_AB_URL . 'assets/announcement-bar.css',
		array(),
		WOWSA_AB_VERSION
	);

	$s = wowsa_ab_settings();
	if ( ! empty( $s['dismissible'] ) ) {
		wp_enqueue_script(
			'wowsa-announcement-bar',
			WOWSA_AB_URL . 'assets/announcement-bar.js',
			array(),
			WOWSA_AB_VERSION,
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'wowsa_ab_assets' );

/**
 * A stable key for the current announcement so a dismissal only lasts until
 * the announcement itself changes.
 */
function wowsa_ab_key( $s ) {
	return substr( md5( $s['message'] . '|' . $s['cta_text'] . '|' . $s['cta_url'] . '|' . $s['lockup_id'] ), 0, 12 );
}

function wowsa_ab_markup() {
	$s = wowsa_ab_settings();

	if ( ! wowsa_ab_is_active( $s ) ) {
		return '';
	}

	$lockup = '';
	if ( ! empty( $s['lockup_id'] ) ) {
		$alt    = '' !== $s['lockup_alt'] ? $s['lockup_alt'] : get_post_meta( $s['lockup_id'], '_wp_attachment_image_alt', true );
		$src    = wp_get_attachment_image_url( $s['lockup_id'], 'full' );
		if ( $src ) {
			$lockup = sprintf(
				'<img class="wowsa-ab__lockup" src="%1$s" alt="%2$s" decoding="async" />',
				esc_url( $src ),
				esc_attr( $alt )
			);
		}
	}

	$cta = '';
	if ( '' !== trim( (string) $s['cta_text'] ) && '' !== trim( (string) $s['cta_url'] ) ) {
		$cta = sprintf(
			'<a class="wowsa-ab__cta" href="%1$s"%2$s>%3$s <span aria-hidden="true">&rarr;</span></a>',
			esc_url( $s['cta_url'] ),
			! empty( $s['cta_new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '',
			esc_html( rtrim( $s['cta_text'], " \t\n\r\0\x0B→>-" ) )
		);
	}

	$dismiss = '';
	if ( ! empty( $s['dismissible'] ) ) {
		$dismiss = sprintf(
			'<button type="button" class="wowsa-ab__dismiss" aria-label="%s">&times;</button>',
			esc_attr__( 'Dismiss announcement', 'wowsa-announcement-bar' )
		);
	}

	return sprintf(
		'<aside class="wowsa-ab" role="region" aria-label="%1$s" data-wowsa-ab-key="%2$s">
			<div class="wowsa-ab__inner">
				%3$s
				<p class="wowsa-ab__text">%4$s</p>
				%5$s
			</div>
			%6$s
		</aside>',
		esc_attr__( 'Site announcement', 'wowsa-announcement-bar' ),
		esc_attr( wowsa_ab_key( $s ) ),
		$lockup,
		esc_html( $s['message'] ),
		$cta,
		$dismiss
	);
}

function wowsa_ab_render() {
	if ( ! wowsa_ab_should_display() ) {
		return;
	}
	echo wowsa_ab_markup(); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- escaped in wowsa_ab_markup().
}
add_action( 'wp_body_open', 'wowsa_ab_render', 1 );

/** Shortcode fallback for themes without wp_body_open, or for manual placement. */
add_shortcode( 'wowsa_announcement_bar', static function () {
	return wowsa_ab_should_display() ? wowsa_ab_markup() : '';
} );

/**
 * Automatic fallback for themes that never call wp_body_open() (common in
 * older/page-builder themes). Buffers the page and, only if the bar didn't
 * already render via the hook, splices it in right after the opening
 * <body> tag. No theme edits required.
 */
add_action( 'template_redirect', static function () {
	if ( is_admin() || ! wowsa_ab_should_display() ) {
		return;
	}
	ob_start( 'wowsa_ab_inject_fallback' );
} );

function wowsa_ab_inject_fallback( $html ) {
	if ( false !== strpos( $html, 'wowsa-ab__inner' ) ) {
		return $html; // Theme already rendered it via wp_body_open.
	}
	$markup = wowsa_ab_markup();
	if ( '' === $markup ) {
		return $html;
	}
	$replaced = preg_replace( '/(<body[^>]*>)/i', '$1' . $markup, $html, 1 );
	return null !== $replaced ? $replaced : $html;
}

add_filter( 'body_class', static function ( $classes ) {
	if ( wowsa_ab_should_display() ) {
		$classes[] = 'has-wowsa-announcement-bar';
	}
	return $classes;
} );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', static function () {
	add_options_page(
		__( 'Announcement Bar', 'wowsa-announcement-bar' ),
		__( 'Announcement Bar', 'wowsa-announcement-bar' ),
		'manage_options',
		'wowsa-announcement-bar',
		'wowsa_ab_settings_page'
	);
} );

add_action( 'admin_init', static function () {
	register_setting(
		'wowsa_ab_group',
		WOWSA_AB_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wowsa_ab_sanitize',
			'default'           => wowsa_ab_defaults(),
		)
	);
} );

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
	if ( 'settings_page_wowsa-announcement-bar' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'wowsa-announcement-bar', WOWSA_AB_URL . 'assets/announcement-bar.css', array(), WOWSA_AB_VERSION );
	wp_enqueue_style( 'wowsa-announcement-bar-admin', WOWSA_AB_URL . 'assets/admin.css', array( 'wowsa-announcement-bar' ), WOWSA_AB_VERSION );
	wp_enqueue_script( 'wowsa-announcement-bar-admin', WOWSA_AB_URL . 'assets/admin.js', array( 'jquery' ), WOWSA_AB_VERSION, true );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( $links ) {
	$url = admin_url( 'options-general.php?page=wowsa-announcement-bar' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wowsa-announcement-bar' ) . '</a>' );
	return $links;
} );

function wowsa_ab_field_name( $key ) {
	return WOWSA_AB_OPTION . '[' . $key . ']';
}

function wowsa_ab_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s        = wowsa_ab_settings();
	$lockup   = $s['lockup_id'] ? wp_get_attachment_image_url( $s['lockup_id'], 'full' ) : '';
	$is_live  = wowsa_ab_is_active( $s );
	?>
	<div class="wrap wowsa-ab-admin">
		<h1><?php esc_html_e( 'WOWSA Announcement Bar', 'wowsa-announcement-bar' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'One institutional announcement at a time, displayed above the primary navigation. Change the lockup, text and destination to reuse this bar for any WOWSA initiative — no code changes required.', 'wowsa-announcement-bar' ); ?>
		</p>

		<p>
			<strong><?php esc_html_e( 'Current status:', 'wowsa-announcement-bar' ); ?></strong>
			<span class="wowsa-ab-status <?php echo $is_live ? 'is-live' : 'is-off'; ?>">
				<?php echo $is_live ? esc_html__( 'Live', 'wowsa-announcement-bar' ) : esc_html__( 'Not showing', 'wowsa-announcement-bar' ); ?>
			</span>
		</p>

		<h2 class="wowsa-ab-preview-title"><?php esc_html_e( 'Preview', 'wowsa-announcement-bar' ); ?></h2>
		<div class="wowsa-ab-preview">
			<?php echo wowsa_ab_markup(); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped ?>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'wowsa_ab_group' ); ?>

			<h2><?php esc_html_e( 'Announcement', 'wowsa-announcement-bar' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable bar', 'wowsa-announcement-bar' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( wowsa_ab_field_name( 'enabled' ) ); ?>" value="1" <?php checked( $s['enabled'], 1 ); ?> />
							<?php esc_html_e( 'Show the announcement bar across the site', 'wowsa-announcement-bar' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wowsa-ab-lockup"><?php esc_html_e( 'Initiative lockup', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<div class="wowsa-ab-lockup-field">
							<img id="wowsa-ab-lockup-preview" src="<?php echo esc_url( $lockup ); ?>" alt="" <?php echo $lockup ? '' : 'style="display:none"'; ?> />
							<input type="hidden" id="wowsa-ab-lockup" name="<?php echo esc_attr( wowsa_ab_field_name( 'lockup_id' ) ); ?>" value="<?php echo esc_attr( $s['lockup_id'] ); ?>" />
							<p>
								<button type="button" class="button" id="wowsa-ab-lockup-select"><?php esc_html_e( 'Upload / choose lockup', 'wowsa-announcement-bar' ); ?></button>
								<button type="button" class="button-link" id="wowsa-ab-lockup-remove" <?php echo $lockup ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Remove', 'wowsa-announcement-bar' ); ?></button>
							</p>
						</div>
						<p class="description"><?php esc_html_e( 'SVG or transparent PNG. Displayed at 30px high. Upload the official lockup — do not recreate it.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wowsa-ab-lockup-alt"><?php esc_html_e( 'Lockup alt text', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="wowsa-ab-lockup-alt" name="<?php echo esc_attr( wowsa_ab_field_name( 'lockup_alt' ) ); ?>" value="<?php echo esc_attr( $s['lockup_alt'] ); ?>" placeholder="The WOWSA Awards 2026" />
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wowsa-ab-message"><?php esc_html_e( 'Announcement text', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<textarea id="wowsa-ab-message" class="large-text" rows="2" name="<?php echo esc_attr( wowsa_ab_field_name( 'message' ) ); ?>"><?php echo esc_textarea( $s['message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Keep to one or two sentences. Plain text only.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wowsa-ab-cta-text"><?php esc_html_e( 'CTA text', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="wowsa-ab-cta-text" name="<?php echo esc_attr( wowsa_ab_field_name( 'cta_text' ) ); ?>" value="<?php echo esc_attr( $s['cta_text'] ); ?>" placeholder="Submit a Nomination" />
						<p class="description"><?php esc_html_e( 'The arrow is added automatically.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wowsa-ab-cta-url"><?php esc_html_e( 'Destination URL', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<input type="url" class="regular-text code" id="wowsa-ab-cta-url" name="<?php echo esc_attr( wowsa_ab_field_name( 'cta_url' ) ); ?>" value="<?php echo esc_attr( $s['cta_url'] ); ?>" placeholder="https://wowsaawards.com" />
						<p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( wowsa_ab_field_name( 'cta_new_tab' ) ); ?>" value="1" <?php checked( $s['cta_new_tab'], 1 ); ?> />
								<?php esc_html_e( 'Open in a new tab', 'wowsa-announcement-bar' ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Schedule & behaviour', 'wowsa-announcement-bar' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Schedule (optional)', 'wowsa-announcement-bar' ); ?></th>
					<td>
						<label for="wowsa-ab-start"><?php esc_html_e( 'Start', 'wowsa-announcement-bar' ); ?></label>
						<input type="date" id="wowsa-ab-start" name="<?php echo esc_attr( wowsa_ab_field_name( 'start_date' ) ); ?>" value="<?php echo esc_attr( $s['start_date'] ); ?>" />
						&nbsp;
						<label for="wowsa-ab-end"><?php esc_html_e( 'End', 'wowsa-announcement-bar' ); ?></label>
						<input type="date" id="wowsa-ab-end" name="<?php echo esc_attr( wowsa_ab_field_name( 'end_date' ) ); ?>" value="<?php echo esc_attr( $s['end_date'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to run indefinitely. Dates are inclusive and use the site timezone.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dismiss button', 'wowsa-announcement-bar' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( wowsa_ab_field_name( 'dismissible' ) ); ?>" value="1" <?php checked( $s['dismissible'], 1 ); ?> />
							<?php esc_html_e( 'Let visitors dismiss the bar with an × button', 'wowsa-announcement-bar' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A dismissal is remembered until the announcement text, CTA or lockup changes.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Where it appears', 'wowsa-announcement-bar' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The bar shows on institutional pages (news, learning centre, governance, directory, history, hall of records, articles, academy). Exclude conversion-focused landing pages below.', 'wowsa-announcement-bar' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Home page', 'wowsa-announcement-bar' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( wowsa_ab_field_name( 'exclude_front' ) ); ?>" value="1" <?php checked( $s['exclude_front'], 1 ); ?> />
							<?php esc_html_e( 'Hide on the home page and blog index', 'wowsa-announcement-bar' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wowsa-ab-exclude-ids"><?php esc_html_e( 'Excluded page IDs', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<textarea id="wowsa-ab-exclude-ids" class="large-text code" rows="2" name="<?php echo esc_attr( wowsa_ab_field_name( 'exclude_ids' ) ); ?>"><?php echo esc_textarea( $s['exclude_ids'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma separated, e.g. 12, 48, 106.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wowsa-ab-exclude-slugs"><?php esc_html_e( 'Excluded URL slugs', 'wowsa-announcement-bar' ); ?></label></th>
					<td>
						<textarea id="wowsa-ab-exclude-slugs" class="large-text code" rows="2" name="<?php echo esc_attr( wowsa_ab_field_name( 'exclude_slugs' ) ); ?>"><?php echo esc_textarea( $s['exclude_slugs'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Comma separated, e.g. safe-coach, wowsa-awards, donate. Any URL containing one of these path segments is excluded.', 'wowsa-announcement-bar' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Manual placement', 'wowsa-announcement-bar' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: 1: hook name, 2: shortcode */
				esc_html__( 'The bar renders automatically on the %1$s hook. If your theme does not support it, place %2$s at the very top of your header template instead.', 'wowsa-announcement-bar' ),
				'<code>wp_body_open</code>',
				'<code>[wowsa_announcement_bar]</code>'
			);
			?>
		</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Activation — seed the first announcement (kept disabled until reviewed)
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, static function () {
	if ( false !== get_option( WOWSA_AB_OPTION, false ) ) {
		return;
	}
	$seed = wowsa_ab_defaults();
	$seed['message']    = '2026 WOWSA Awards nominations are now open. Help recognize the people shaping the next century of open water swimming.';
	$seed['cta_text']   = 'Submit a Nomination';
	$seed['cta_url']    = 'https://wowsaawards.com';
	$seed['lockup_alt'] = 'The WOWSA Awards 2026';
	add_option( WOWSA_AB_OPTION, $seed );
} );
