<?php
/**
 * ttrp-common: tells the site owner what the update checks send to plugins.ttrp.gr.
 *
 * - A one-time admin notice (until an administrator clicks "Got it"), on the Dashboard,
 *   the Plugins screen and ttrp.gr pages only.
 * - A "Data and privacy" box on the All Plugins page.
 * - A suggested paragraph in WordPress's privacy policy guide (Settings → Privacy).
 *
 * Nothing here changes what is sent; see TTRP_Common_Updater and TTRP_Common_Catalog.
 */

defined( 'ABSPATH' ) || exit;

class TTRP_Common_Privacy {

	const OPTION      = 'ttrp_common_privacy_notice'; // Stores the dismissed version ('1').
	const TERMS_URL   = 'https://plugins.ttrp.gr/?page=terms';
	const PRIVACY_URL = 'https://plugins.ttrp.gr/?page=privacy';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Links in the admin language (Greek for el*, else English). */
	public static function url( $kind ) {
		$lang = 0 === strpos( get_user_locale(), 'el' ) ? 'el' : 'en';
		return ( 'terms' === $kind ? self::TERMS_URL : self::PRIVACY_URL ) . '&lang=' . $lang;
	}

	/** One sentence on what is sent, shared by the notice and the box. */
	public static function summary() {
		return __( 'ttrp.gr plugins get their updates from plugins.ttrp.gr. To check for updates, this site sends that server its address, its WordPress version and the installed versions of the ttrp.gr plugins; the server also records the IP address. No customer, order or other personal data of your shop is sent.', 'ttrp-common' );
	}

	public static function links_html() {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a> · <a href="%3$s" target="_blank" rel="noopener">%4$s</a>',
			esc_url( self::url( 'terms' ) ),
			esc_html__( 'Terms of use', 'ttrp-common' ),
			esc_url( self::url( 'privacy' ) ),
			esc_html__( 'Privacy policy', 'ttrp-common' )
		);
	}

	public static function notice() {
		if ( get_option( self::OPTION ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'dashboard', 'plugins' ), true ) && false === strpos( $id, 'ttrp' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'ttrp_common_privacy', 'dismiss' ), 'ttrp_common_privacy' );
		?>
		<div class="notice notice-info ttrp-common-privacy">
			<p><strong><?php esc_html_e( 'ttrp.gr plugins: updates and privacy', 'ttrp-common' ); ?></strong></p>
			<p><?php echo esc_html( self::summary() ); ?></p>
			<p><?php echo self::links_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links_html(). ?></p>
			<p><a class="button button-primary" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Got it', 'ttrp-common' ); ?></a></p>
		</div>
		<?php
	}

	public static function maybe_dismiss() {
		if ( ! isset( $_GET['ttrp_common_privacy'] ) || 'dismiss' !== $_GET['ttrp_common_privacy'] || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ttrp_common_privacy' );
		update_option( self::OPTION, '1', false );
		wp_safe_redirect( remove_query_arg( array( 'ttrp_common_privacy', '_wpnonce' ) ) );
		exit;
	}

	/** "Data and privacy" box on the All Plugins page. */
	public static function render_box() {
		?>
		<div class="ttrp-catalog-privacy">
			<span class="dashicons dashicons-shield" aria-hidden="true"></span>
			<div>
				<strong><?php esc_html_e( 'Data and privacy', 'ttrp-common' ); ?></strong>
				<p><?php echo esc_html( self::summary() ); ?> <?php esc_html_e( 'Opening this page also sends the admin language.', 'ttrp-common' ); ?></p>
				<p><?php echo self::links_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in links_html(). ?></p>
			</div>
		</div>
		<?php
	}

	/** Suggested text for Settings → Privacy → Policy Guide. */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'This site uses plugins by ttrp.gr, which get their updates from plugins.ttrp.gr. The text below is a suggestion; it concerns the site, not its visitors.', 'ttrp-common' ) . '</p>';
		$text .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'ttrp-common' ) . ' </strong>';
		$text .= esc_html__( 'To receive updates for some of its plugins, this website connects to plugins.ttrp.gr and sends the website address, the WordPress version and the plugin versions. No data about visitors or customers is sent.', 'ttrp-common' ) . ' ';
		$text .= sprintf(
			/* translators: %s: link to the plugins.ttrp.gr privacy policy */
			esc_html__( 'See the %s of plugins.ttrp.gr.', 'ttrp-common' ),
			'<a href="' . esc_url( self::url( 'privacy' ) ) . '">' . esc_html__( 'privacy policy', 'ttrp-common' ) . '</a>'
		);
		$text .= '</p>';
		wp_add_privacy_policy_content( 'ttrp.gr Plugins', wp_kses_post( $text ) );
	}
}
