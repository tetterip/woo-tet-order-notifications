<?php
/**
 * ttrp-common: "All Plugins" page under the ttrp.gr Plugins menu.
 *
 * Lists the plugins the update server offers to this site (?action=catalog) and
 * lets an administrator install / activate them. Installing goes through WordPress
 * core's own installer (update.php?action=install-plugin): this class only answers
 * plugins_api() for catalog slugs so core knows where to download the ZIP from.
 */

defined( 'ABSPATH' ) || exit;

class TTRP_Common_Catalog {

	const VERSION     = '1.0.0'; // Keep in sync with the version registered in loader.php.
	const SERVER      = 'https://plugins.ttrp.gr/';
	const PAGE        = 'ttrp-all-plugins';
	const TEXT_DOMAIN = 'ttrp-common';
	const CACHE_TTL   = 43200; // 12 h, like WordPress's own update checks.

	/** @var string Directory of the loaded ttrp-common copy. */
	private static $dir = '';

	public static function init( $dir ) {
		self::$dir = $dir;
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 6 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
	}

	public static function load_textdomain() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		load_textdomain( self::TEXT_DOMAIN, self::$dir . '/languages/' . self::TEXT_DOMAIN . '-' . $locale . '.mo' );
		// Greek sites often use el while the file is named after the language only.
		if ( 0 === strpos( $locale, 'el' ) ) {
			load_textdomain( self::TEXT_DOMAIN, self::$dir . '/languages/' . self::TEXT_DOMAIN . '-el.mo' );
		}
	}

	/**
	 * Adds "All Plugins" as the first entry of the shared ttrp.gr Plugins menu,
	 * creating the top-level menu if no other ttrp.gr plugin has yet.
	 */
	public static function register_menu() {
		global $menu;
		$has_brand_menu = false;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && 'ttrp-plugins' === $item[2] ) {
				$has_brand_menu = true;
				break;
			}
		}
		if ( ! $has_brand_menu ) {
			add_menu_page( 'ttrp.gr Plugins', 'ttrp.gr Plugins', 'manage_options', 'ttrp-plugins', array( __CLASS__, 'render_page' ), self::menu_icon(), 56 );
			add_action(
				'admin_menu',
				function () {
					remove_submenu_page( 'ttrp-plugins', 'ttrp-plugins' );
				},
				999
			);
		}

		// Menu label stays English, like every entry under ttrp.gr Plugins.
		add_submenu_page( 'ttrp-plugins', 'All Plugins', 'All Plugins', 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ), 0 );
	}

	private static function menu_icon() {
		$svg = self::$dir . '/../assets/ttrp-logo.svg';
		if ( file_exists( $svg ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $svg ) );
		}
		return 'dashicons-admin-plugins';
	}

	/**
	 * Catalog items for this site (validated), or null when the server can't be reached.
	 *
	 * @param bool $refresh Skip the cache.
	 * @return array|null
	 */
	public static function get_catalog( $refresh = false ) {
		$locale = get_user_locale();
		$key    = 'ttrp_common_catalog_' . md5( $locale . self::VERSION );

		if ( ! $refresh ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'action' => 'catalog',
					'locale' => $locale,
				),
				self::SERVER
			),
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
			)
		);

		$body = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['plugins'] ) || ! is_array( $body['plugins'] ) ) {
			return null;
		}

		$items = array();
		foreach ( $body['plugins'] as $p ) {
			$item = self::sanitize_item( $p );
			if ( $item ) {
				$items[ $item['slug'] ] = $item;
			}
		}

		set_site_transient( $key, $items, self::CACHE_TTL );
		return $items;
	}

	/**
	 * Validates one catalog entry from the server. Download URLs must point at the
	 * update server itself, so a tampered response can't make WordPress install
	 * a package from elsewhere.
	 */
	private static function sanitize_item( $p ) {
		if ( ! is_array( $p ) || empty( $p['slug'] ) || ! preg_match( '/^[a-z0-9\-]{1,100}$/', (string) $p['slug'] ) ) {
			return null;
		}
		$download = isset( $p['download_url'] ) ? (string) $p['download_url'] : '';
		if ( 0 !== strpos( $download, self::SERVER ) ) {
			return null;
		}
		$icon = isset( $p['icon'] ) ? (string) $p['icon'] : '';

		$text = function ( $key ) use ( $p ) {
			return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? sanitize_text_field( (string) $p[ $key ] ) : '';
		};

		return array(
			'slug'           => (string) $p['slug'],
			'name'           => $text( 'name' ),
			'description'    => $text( 'description' ),
			'category'       => $text( 'category' ),
			'category_label' => $text( 'category_label' ),
			'version'        => $text( 'version' ),
			'last_updated'   => $text( 'last_updated' ),
			'requires'       => $text( 'requires' ),
			'tested'         => $text( 'tested' ),
			'requires_php'   => $text( 'requires_php' ),
			'icon'           => 0 === strpos( $icon, self::SERVER ) ? $icon : '',
			'download_url'   => $download,
		);
	}

	/**
	 * Answers plugins_api( 'plugin_information' ) for catalog plugins that aren't
	 * installed yet (installed ones are answered by their own updater), so core's
	 * installer can fetch them.
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( false !== $result || 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		$items = self::get_catalog();
		if ( empty( $items[ $args->slug ] ) ) {
			return $result;
		}
		$p = $items[ $args->slug ];

		return (object) array(
			'name'          => $p['name'],
			'slug'          => $p['slug'],
			'version'       => $p['version'],
			'author'        => '<a href="https://ttrp.gr">ttrp.gr</a>',
			'homepage'      => 'https://ttrp.gr',
			'requires'      => $p['requires'],
			'tested'        => $p['tested'],
			'requires_php'  => $p['requires_php'],
			'last_updated'  => $p['last_updated'],
			'sections'      => array( 'description' => esc_html( $p['description'] ) ),
			'download_link' => $p['download_url'],
			'icons'         => $p['icon'] ? array( 'svg' => $p['icon'] ) : array(),
		);
	}

	/**
	 * Installed plugins by folder name: folder => [ file, version ].
	 */
	private static function installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$folder = dirname( $file );
			if ( '.' !== $folder ) {
				$out[ $folder ] = array(
					'file'    => $file,
					'version' => isset( $data['Version'] ) ? $data['Version'] : '',
				);
			}
		}
		return $out;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$refreshed = false;
		if ( isset( $_POST['ttrp_common_refresh'] ) && check_admin_referer( 'ttrp_common_refresh' ) ) {
			$refreshed = true;
		}
		$items     = self::get_catalog( $refreshed );
		$installed = self::installed_plugins();

		// Group by category, keeping the server's order.
		$groups = array();
		foreach ( (array) $items as $item ) {
			$label              = '' !== $item['category_label'] ? $item['category_label'] : __( 'Other', 'ttrp-common' );
			$groups[ $label ][] = $item;
		}
		?>
		<div class="wrap ttrp-catalog">
			<h1 class="wp-heading-inline">All Plugins</h1>
			<form method="post" class="ttrp-catalog-refresh">
				<?php wp_nonce_field( 'ttrp_common_refresh' ); ?>
				<button type="submit" name="ttrp_common_refresh" value="1" class="page-title-action"><?php esc_html_e( 'Refresh list', 'ttrp-common' ); ?></button>
			</form>
			<hr class="wp-header-end">

			<p class="ttrp-catalog-intro"><?php esc_html_e( 'Plugins made by ttrp.gr for WooCommerce stores. Install and activate them from here; after that they update like any other plugin.', 'ttrp-common' ); ?></p>

			<?php if ( $refreshed && null !== $items ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The list was updated.', 'ttrp-common' ); ?></p></div>
			<?php endif; ?>

			<?php if ( null === $items ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Could not load the plugin list from the update server. Please try again later.', 'ttrp-common' ); ?></p></div>
			<?php endif; ?>

			<?php foreach ( $groups as $label => $group ) : ?>
				<h2><?php echo esc_html( $label ); ?></h2>
				<div class="ttrp-catalog-grid">
					<?php foreach ( $group as $item ) : ?>
						<?php self::render_card( $item, isset( $installed[ $item['slug'] ] ) ? $installed[ $item['slug'] ] : null ); ?>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<style>
			.ttrp-catalog-refresh { display: inline; }
			.ttrp-catalog-intro { max-width: 760px; }
			.ttrp-catalog h2 { margin-top: 28px; }
			.ttrp-catalog-grid { display: grid; grid-template-columns: repeat( auto-fill, minmax( 340px, 1fr ) ); gap: 16px; }
			.ttrp-catalog-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px; display: grid; grid-template-columns: 72px 1fr; gap: 14px; }
			.ttrp-catalog-card img { width: 72px; height: 72px; border-radius: 4px; }
			.ttrp-catalog-card h3 { margin: 0 0 4px; font-size: 14px; line-height: 1.4; }
			.ttrp-catalog-card p { margin: 0 0 10px; color: #50575e; }
			.ttrp-catalog-meta { font-size: 12px; color: #646970; margin-bottom: 10px; }
			.ttrp-catalog-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
			.ttrp-catalog-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 12px; background: #edfaef; color: #00661b; border: 1px solid #b8e6bf; }
		</style>
		<?php
	}

	/**
	 * @param array      $item      Catalog entry.
	 * @param array|null $installed [ file, version ] when the plugin is installed.
	 */
	private static function render_card( $item, $installed ) {
		$is_active = $installed && is_plugin_active( $installed['file'] );
		?>
		<div class="ttrp-catalog-card">
			<div>
				<?php if ( $item['icon'] ) : ?>
					<img src="<?php echo esc_url( $item['icon'] ); ?>" alt="">
				<?php endif; ?>
			</div>
			<div>
				<h3><?php echo esc_html( $item['name'] ); ?></h3>
				<p><?php echo esc_html( $item['description'] ); ?></p>
				<div class="ttrp-catalog-meta">
					<?php
					if ( $installed && '' !== $installed['version'] ) {
						/* translators: %s: installed plugin version */
						echo esc_html( sprintf( __( 'Installed version %s', 'ttrp-common' ), $installed['version'] ) );
					} else {
						/* translators: %s: latest plugin version */
						echo esc_html( sprintf( __( 'Version %s', 'ttrp-common' ), $item['version'] ) );
					}
					?>
				</div>
				<div class="ttrp-catalog-actions">
					<?php
					if ( ! $installed ) {
						if ( current_user_can( 'install_plugins' ) ) {
							$url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $item['slug'] ) ), 'install-plugin_' . $item['slug'] );
							echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Install Now', 'ttrp-common' ) . '</a>';
						}
					} elseif ( ! $is_active ) {
						if ( current_user_can( 'activate_plugin', $installed['file'] ) ) {
							$url = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $installed['file'] ) ), 'activate-plugin_' . $installed['file'] );
							echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Activate', 'ttrp-common' ) . '</a>';
						} else {
							echo '<span class="ttrp-catalog-meta">' . esc_html__( 'Installed', 'ttrp-common' ) . '</span>';
						}
					} else {
						echo '<span class="ttrp-catalog-badge">' . esc_html__( 'Active', 'ttrp-common' ) . '</span>';
					}

					if ( $installed && '' !== $item['version'] && version_compare( $item['version'], $installed['version'], '>' ) && current_user_can( 'update_plugins' ) ) {
						/* translators: %s: new plugin version */
						echo '<a class="button" href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html( sprintf( __( 'Update to %s', 'ttrp-common' ), $item['version'] ) ) . '</a>';
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}
}
