<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page under ttrp.gr Plugins → Order Notifications, with three tabs
 * rendered with the WC Settings API: Channels, Message and Admin alerts.
 * Also the single place that reads the options (getters with defaults).
 */
class Tet_Notify_Settings {

	// Plugin title in admin is never translated (suite-wide uniformity rule).
	const PLUGIN_TITLE = 'Order Notifications';
	const PAGE_SLUG    = 'ttrp-order-notifications';
	const TABS         = [ 'alerts', 'channels', 'message' ];

	/** Screen id of the settings page, set once the submenu is registered. */
	private static string $page_hook = '';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'maybe_register_brand_menu' ], 5 );
		add_action( 'admin_menu', [ __CLASS__, 'add_submenu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'woocommerce_screen_ids', [ __CLASS__, 'add_screen_id' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( TET_NOTIFY_FILE ), [ __CLASS__, 'action_links' ] );
		add_action( 'woocommerce_admin_field_tet_notify_test', [ __CLASS__, 'render_test_field' ] );
		add_action( 'woocommerce_admin_field_tet_notify_media', [ __CLASS__, 'render_media_field' ] );
		add_action( 'wp_ajax_tet_notify_test', [ __CLASS__, 'ajax_test' ] );
		add_filter( 'woocommerce_admin_settings_sanitize_option_tet_notify_live_sound_url', 'esc_url_raw' );
		add_filter( 'woocommerce_admin_settings_sanitize_option_tet_notify_webhook_url', [ __CLASS__, 'sanitize_url' ] );
		add_filter( 'woocommerce_admin_settings_sanitize_option_tet_notify_live_interval', static function ( $v ) {
			return (string) max( 10, min( 120, (int) $v ) );
		} );
	}

	public static function sanitize_url( $value ): string {
		return esc_url_raw( trim( (string) $value ), [ 'http', 'https' ] );
	}

	/* ── Option getters ─────────────────────────────────────── */

	public static function yes( string $option, string $default = 'no' ): bool {
		return 'yes' === get_option( $option, $default );
	}

	/** Order statuses that trigger a notification, without the "wc-" prefix. */
	public static function statuses(): array {
		$saved = get_option( 'tet_notify_statuses', [ 'wc-processing', 'wc-on-hold' ] );
		$saved = is_array( $saved ) ? $saved : [];
		return array_values( array_map( static function ( $s ) {
			return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
		}, $saved ) );
	}

	public static function default_title(): string {
		/* translators: {order_number} is a template tag, keep it as is. */
		return __( 'New order #{order_number}', 'tet-order-notifications' );
	}

	public static function default_body(): string {
		// Only tags, no words: nothing to translate.
		return "{total} · {payment_method}\n{customer_name} · {city}\n{items}";
	}

	public static function default_voice_text(): string {
		return __( 'You have a new online order', 'tet-order-notifications' );
	}

	public static function title_template(): string {
		$v = (string) get_option( 'tet_notify_title', '' );
		return '' !== trim( $v ) ? $v : self::default_title();
	}

	public static function body_template(): string {
		$v = (string) get_option( 'tet_notify_body', '' );
		return '' !== trim( $v ) ? $v : self::default_body();
	}

	public static function voice_text(): string {
		$v = (string) get_option( 'tet_notify_live_voice_text', '' );
		return '' !== trim( $v ) ? $v : self::default_voice_text();
	}

	public static function live_roles(): array {
		$roles = get_option( 'tet_notify_live_roles', [ 'administrator', 'shop_manager' ] );
		return is_array( $roles ) ? array_values( $roles ) : [];
	}

	public static function live_interval(): int {
		return max( 10, min( 120, (int) get_option( 'tet_notify_live_interval', 15 ) ) );
	}

	/** Template tags, filterable so custom keys added via `tet_notify_order_data` can be used. */
	public static function tags(): array {
		return (array) apply_filters( 'tet_notify_template_tags', Tet_Notify_Message::TAGS );
	}

	/* ── Menu (shared ttrp.gr pattern) ──────────────────────── */

	public static function url( string $tab = 'alerts' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'tet-order-notifications' ) . '</a>' );
		return $links;
	}

	/** WC admin JS (tipTip, selectWoo) on our page, so desc_tip icons and multiselects work. */
	public static function add_screen_id( array $screen_ids ): array {
		if ( self::$page_hook ) {
			$screen_ids[] = self::$page_hook;
		}
		return $screen_ids;
	}

	public static function maybe_register_brand_menu(): void {
		global $menu;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && 'ttrp-plugins' === $item[2] ) {
					return;
				}
			}
		}
		if ( ! function_exists( 'ttrp_brand_dashboard' ) ) {
			function ttrp_brand_dashboard(): void {
				global $submenu;
				$target = null;
				if ( ! empty( $submenu['ttrp-plugins'] ) ) {
					foreach ( $submenu['ttrp-plugins'] as $item ) {
						if ( 'ttrp-plugins' !== $item[2] ) {
							$target = $item[2];
							break;
						}
					}
				}
				if ( $target ) {
					$url = esc_url( admin_url( 'admin.php?page=' . $target ) );
					echo '<meta http-equiv="refresh" content="0;url=' . $url . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					echo '<script>window.location.replace("' . $url . '");</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				}
			}
		}
		$svg_path = TET_NOTIFY_PATH . 'assets/ttrp-logo.svg';
		$icon     = file_exists( $svg_path )
			? 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $svg_path ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: 'dashicons-admin-plugins';
		add_menu_page( 'ttrp.gr Plugins', 'ttrp.gr Plugins', 'manage_options', 'ttrp-plugins', 'ttrp_brand_dashboard', $icon, 56 );
		add_action( 'admin_menu', function () {
			remove_submenu_page( 'ttrp-plugins', 'ttrp-plugins' );
		}, 999 );
	}

	public static function add_submenu(): void {
		self::$page_hook = (string) add_submenu_page(
			'ttrp-plugins',
			self::PLUGIN_TITLE,
			self::PLUGIN_TITLE,
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( ! self::$page_hook || $hook !== self::$page_hook ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'ttrp-admin', TET_NOTIFY_URL . 'assets/ttrp-admin.css', [], tet_notify_asset_ver( 'assets/ttrp-admin.css' ) );
		wp_enqueue_style( 'tet-notify-settings', TET_NOTIFY_URL . 'assets/css/settings.css', [], tet_notify_asset_ver( 'assets/css/settings.css' ) );
		if ( 'alerts' === self::current_tab() ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( 'tet-notify-settings', TET_NOTIFY_URL . 'assets/js/settings.js', [ 'jquery' ], tet_notify_asset_ver( 'assets/js/settings.js' ), true );
		wp_localize_script( 'tet-notify-settings', 'tetNotifySettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tet_notify_test' ),
			'i18n'    => [
				'sending'     => __( 'Sending…', 'tet-order-notifications' ),
				'on'          => __( 'On', 'tet-order-notifications' ),
				'off'         => __( 'Off', 'tet-order-notifications' ),
				'sent'        => __( 'Sent. Check the channel.', 'tet-order-notifications' ),
				'failed'      => __( 'Failed:', 'tet-order-notifications' ),
				'chooseSound' => __( 'Choose a sound file', 'tet-order-notifications' ),
				'useSound'    => __( 'Use this sound', 'tet-order-notifications' ),
			],
		] );
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'alerts'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, self::TABS, true ) ? $tab : 'alerts';
	}

	/* ── Page ───────────────────────────────────────────────── */

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = self::current_tab();

		if ( isset( $_POST['save_tet_notify'] ) && check_admin_referer( 'tet_notify_save_' . $tab ) ) {
			woocommerce_update_options( self::fields( $tab ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'tet-order-notifications' ) . '</p></div>';
		}

		$tabs = [
			'alerts'   => __( 'Admin alerts', 'tet-order-notifications' ),
			'channels' => __( 'Channels', 'tet-order-notifications' ),
			'message'  => __( 'Message', 'tet-order-notifications' ),
		];
		?>
		<div class="wrap woocommerce ttrp-wrap">
			<div class="ttrp-plugin-header">
				<h1><?php echo esc_html( self::PLUGIN_TITLE ); ?></h1>
				<span class="ttrp-plugin-version">v<?php echo esc_html( TET_NOTIFY_VERSION ); ?></span>
			</div>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" id="tet-notify-form">
				<?php
				wp_nonce_field( 'tet_notify_save_' . $tab );
				if ( 'channels' === $tab ) {
					self::render_channels();
				} else {
					woocommerce_admin_fields( self::fields( $tab ) );
				}
				if ( 'message' === $tab ) {
					self::render_tag_list();
				}
				?>
				<p class="submit">
					<input type="submit" name="save_tet_notify" class="button-primary" value="<?php esc_attr_e( 'Save settings', 'tet-order-notifications' ); ?>" />
				</p>
			</form>

			<div class="ttrp-settings-footer">
				<img src="<?php echo esc_url( TET_NOTIFY_URL . 'assets/ttrp.svg' ); ?>" alt="" />
				<span><?php echo esc_html( self::PLUGIN_TITLE ); ?> by <a href="https://ttrp.gr" target="_blank" rel="noopener">ttrp.gr</a></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Step-by-step setup guide per channel: [ 'steps' => string[], 'note' => string ].
	 * Strings may hold <a>, <code>, <strong> (see render_howto()).
	 */
	private static function channel_howto( string $id ): array {
		$link = static function ( string $url, string $text ): string {
			return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
		};
		switch ( $id ) {
			case 'telegram':
				return [
					'steps' => [
						/* translators: %s: link to @BotFather. */
						sprintf( __( 'In Telegram, open %s and send <code>/newbot</code>. Give the bot a name (e.g. "My shop orders") and then a username that ends in <code>bot</code>.', 'tet-order-notifications' ), $link( 'https://t.me/BotFather', '@BotFather' ) ),
						__( 'BotFather replies with a <strong>token</strong> like <code>123456789:AAH…</code>. Paste it in <strong>Bot token</strong> below.', 'tet-order-notifications' ),
						__( 'Choose where the messages go. <strong>Just you:</strong> open your new bot (the t.me/… link in BotFather\'s reply) and press <strong>Start</strong>. <strong>A group:</strong> add the bot to the group and send <code>/start@YourBotName</code> in it. <strong>A channel:</strong> add the bot to the channel as an administrator and post something.', 'tet-order-notifications' ),
						__( 'With the token typed below, click <strong>Find my chat ID</strong>. A Telegram page opens with text like <code>"chat":{"id":123456789</code>: that number is the chat ID. Groups and channels have a negative number (e.g. <code>-1001234567890</code>); keep the minus sign.', 'tet-order-notifications' ),
						__( 'Paste it in <strong>Chat IDs</strong> (several are fine, separated by commas), tick <strong>Enable</strong>, save, and press <strong>Send test message</strong>.', 'tet-order-notifications' ),
					],
					'note'  => __( 'If the page shows only <code>"result":[]</code>, send the bot a new message and reload it: Telegram keeps them there for about 24 hours. Keep the token private: anyone who has it can send messages as your bot.', 'tet-order-notifications' ),
				];
			case 'slack':
				return [
					'steps' => [
						/* translators: %s: link to api.slack.com/apps. */
						sprintf( __( 'Open %s, click <strong>Create New App</strong> → <strong>From scratch</strong>, name it (e.g. "Orders") and pick your workspace.', 'tet-order-notifications' ), $link( 'https://api.slack.com/apps', 'api.slack.com/apps' ) ),
						__( 'In the app\'s menu open <strong>Incoming Webhooks</strong> and switch <strong>Activate Incoming Webhooks</strong> on.', 'tet-order-notifications' ),
						__( 'Click <strong>Add New Webhook to Workspace</strong> at the bottom, choose the channel for the orders and click <strong>Allow</strong>.', 'tet-order-notifications' ),
						__( 'Copy the <strong>Webhook URL</strong> (it starts with <code>https://hooks.slack.com/services/</code>), paste it below, tick <strong>Enable</strong>, save, and press <strong>Send test message</strong>.', 'tet-order-notifications' ),
					],
					'note'  => __( 'You may need a Slack workspace admin to approve the app.', 'tet-order-notifications' ),
				];
			case 'discord':
				return [
					'steps' => [
						__( 'In Discord, open your server, hover over the channel for the orders and click the gear (<strong>Edit Channel</strong>).', 'tet-order-notifications' ),
						__( 'Open <strong>Integrations</strong> → <strong>Webhooks</strong> → <strong>New Webhook</strong>. You can rename it (e.g. "Orders") and change its picture.', 'tet-order-notifications' ),
						__( 'Click <strong>Copy Webhook URL</strong>, paste it below, tick <strong>Enable</strong>, save, and press <strong>Send test message</strong>.', 'tet-order-notifications' ),
					],
					'note'  => __( 'You need the "Manage Webhooks" permission on the server.', 'tet-order-notifications' ),
				];
			case 'webhook':
				return [
					'steps' => [
						__( 'In your automation tool, create a trigger that receives a webhook and copy its URL: in n8n a <strong>Webhook</strong> node (POST), in Make a <strong>Custom webhook</strong>, in Zapier <strong>Webhooks by Zapier → Catch Hook</strong>.', 'tet-order-notifications' ),
						__( 'Paste the URL below. Optionally enter a <strong>Secret</strong> to check that requests really come from your shop (the <code>X-Tet-Signature</code> header).', 'tet-order-notifications' ),
						__( 'Tick <strong>Enable</strong>, save, and press <strong>Send test message</strong>: the tool receives a sample order (header <code>X-Tet-Event: test</code>), so you can map its fields.', 'tet-order-notifications' ),
					],
					'note'  => __( 'The order is sent as JSON (POST), including the customer\'s email and phone. Addresses on your own server or local network are blocked for safety.', 'tet-order-notifications' ),
				];
		}
		return [ 'steps' => [], 'note' => '' ];
	}

	/** The guide inside a channel card; open until the channel is set up. */
	private static function render_howto( string $id, bool $configured ): void {
		$howto = self::channel_howto( $id );
		if ( ! $howto['steps'] ) {
			return;
		}
		$allowed = [
			'a'      => [ 'href' => true, 'target' => true, 'rel' => true ],
			'code'   => [],
			'strong' => [],
		];
		?>
		<details class="tet-notify-howto"<?php echo $configured ? '' : ' open'; ?>>
			<summary><?php esc_html_e( 'How to set it up', 'tet-order-notifications' ); ?></summary>
			<ol>
				<?php foreach ( $howto['steps'] as $step ) : ?>
					<li><?php echo wp_kses( $step, $allowed ); ?></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( 'telegram' === $id ) : ?>
				<p><a href="#" class="button tet-notify-getupdates" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Find my chat ID', 'tet-order-notifications' ); ?></a>
				<span class="description tet-notify-getupdates-hint"><?php esc_html_e( 'Type the bot token below first.', 'tet-order-notifications' ); ?></span></p>
			<?php endif; ?>
			<?php if ( $howto['note'] ) : ?>
				<p class="description"><?php echo wp_kses( $howto['note'], $allowed ); ?></p>
			<?php endif; ?>
		</details>
		<?php
	}

	/** Short line under each channel's name in the Channels tab. */
	private static function channel_summaries(): array {
		return [
			'telegram' => __( 'Message to a chat, group or channel', 'tet-order-notifications' ),
			'slack'    => __( 'Message to a Slack channel', 'tet-order-notifications' ),
			'discord'  => __( 'Message to a Discord channel', 'tet-order-notifications' ),
			'webhook'  => __( 'JSON to n8n, Make, Zapier or your own app', 'tet-order-notifications' ),
		];
	}

	/**
	 * Channels tab: the intro, then one collapsible card per channel (native
	 * <details>, so it works without JS and closed cards still submit their fields).
	 * Enabled channels start open (with the error inside when their settings can't work); the rest start closed.
	 */
	private static function render_channels(): void {
		$groups    = [];
		$current   = 'intro';
		foreach ( self::channel_fields() as $field ) {
			if ( 'title' === $field['type'] && 0 === strpos( $field['id'], 'tet_notify_' ) ) {
				$key = substr( $field['id'], strlen( 'tet_notify_' ) );
				if ( isset( Tet_Notify_Channels::labels()[ $key ] ) ) {
					$current = $key;
					unset( $field['title'] ); // Shown in the card header instead.
				}
			}
			$groups[ $current ][] = $field;
		}

		woocommerce_admin_fields( $groups['intro'] );

		$summaries = self::channel_summaries();
		echo '<div class="tet-notify-channels">';
		foreach ( Tet_Notify_Channels::labels() as $id => $label ) {
			$enabled = Tet_Notify_Channels::is_enabled( $id );
			$error   = $enabled ? Tet_Notify_Channels::config_error( $id, Tet_Notify_Channels::config( $id ) ) : '';
			if ( ! $enabled ) {
				$badge = [ 'neutral', __( 'Off', 'tet-order-notifications' ) ];
			} elseif ( $error ) {
				$badge = [ 'warning', __( 'Needs setup', 'tet-order-notifications' ) ];
			} else {
				$badge = [ 'success', __( 'On', 'tet-order-notifications' ) ];
			}
			?>
			<details class="tet-notify-channel" data-channel="<?php echo esc_attr( $id ); ?>"<?php echo $enabled ? ' open' : ''; ?>>
				<summary>
					<span class="tet-notify-channel-name"><?php echo esc_html( $label ); ?></span>
					<span class="tet-notify-channel-summary"><?php echo esc_html( $summaries[ $id ] ?? '' ); ?></span>
					<span class="ttrp-badge ttrp-badge--<?php echo esc_attr( $badge[0] ); ?> tet-notify-channel-badge"><?php echo esc_html( $badge[1] ); ?></span>
				</summary>
				<div class="tet-notify-channel-body">
					<?php
					if ( $error ) {
						echo '<div class="ttrp-notice ttrp-notice--warning"><p>' . esc_html( $error ) . '</p></div>';
					}
					self::render_howto( $id, $enabled && ! $error );
					woocommerce_admin_fields( $groups[ $id ] ?? [] );
					?>
				</div>
			</details>
			<?php
		}
		echo '</div>';
	}

	private static function render_tag_list(): void {
		echo '<h2>' . esc_html__( 'Template tags', 'tet-order-notifications' ) . '</h2>';
		echo '<p class="tet-notify-tags">';
		foreach ( self::tags() as $tag ) {
			echo '<code>{' . esc_html( $tag ) . '}</code> ';
		}
		echo '</p><p class="description">' . esc_html__( 'Lines left empty by a tag (e.g. no customer note) are removed. A link to the order is added to every message.', 'tet-order-notifications' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Customer details in the message (name, email, phone) are sent to the service of each channel. Include only what you need.', 'tet-order-notifications' ) . '</p>';
	}

	/* ── Fields ─────────────────────────────────────────────── */

	public static function fields( string $tab ): array {
		if ( 'message' === $tab ) {
			return self::message_fields();
		}
		if ( 'alerts' === $tab ) {
			return self::alert_fields();
		}
		return self::channel_fields();
	}

	private static function channel_fields(): array {
		return [
			[
				'type' => 'title',
				'id'   => 'tet_notify_channels_intro',
				'desc' => __( 'Each new order is sent to every channel you enable here. Messages are sent in the background, so a slow service never delays the checkout.', 'tet-order-notifications' ),
			],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_channels_intro' ],

			// Telegram.
			[
				'title' => 'Telegram',
				'type'  => 'title',
				'id'    => 'tet_notify_telegram',
			],
			[
				'title'   => __( 'Enable', 'tet-order-notifications' ),
				'desc'    => __( 'Send new orders to Telegram', 'tet-order-notifications' ),
				'id'      => 'tet_notify_telegram_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title' => __( 'Bot token', 'tet-order-notifications' ),
				'id'    => 'tet_notify_telegram_token',
				'type'  => 'password',
				'css'   => 'min-width:400px;',
				'custom_attributes' => [ 'autocomplete' => 'off' ],
			],
			[
				'title'    => __( 'Chat IDs', 'tet-order-notifications' ),
				'desc'     => __( 'One or more, separated by commas. Groups start with a minus sign; public channels can use @name.', 'tet-order-notifications' ),
				'id'       => 'tet_notify_telegram_chat_ids',
				'type'     => 'text',
				'css'      => 'min-width:400px;',
			],
			[ 'type' => 'tet_notify_test', 'id' => 'tet_notify_telegram_test', 'channel' => 'telegram', 'is_option' => false ],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_telegram' ],

			// Slack.
			[
				'title' => 'Slack',
				'type'  => 'title',
				'id'    => 'tet_notify_slack',
			],
			[
				'title'   => __( 'Enable', 'tet-order-notifications' ),
				'desc'    => __( 'Send new orders to Slack', 'tet-order-notifications' ),
				'id'      => 'tet_notify_slack_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title' => __( 'Webhook URL', 'tet-order-notifications' ),
				'id'    => 'tet_notify_slack_webhook',
				'type'  => 'password',
				'css'   => 'min-width:400px;',
				'placeholder' => 'https://hooks.slack.com/services/…',
				'custom_attributes' => [ 'autocomplete' => 'off' ],
			],
			[ 'type' => 'tet_notify_test', 'id' => 'tet_notify_slack_test', 'channel' => 'slack', 'is_option' => false ],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_slack' ],

			// Discord.
			[
				'title' => 'Discord',
				'type'  => 'title',
				'id'    => 'tet_notify_discord',
			],
			[
				'title'   => __( 'Enable', 'tet-order-notifications' ),
				'desc'    => __( 'Send new orders to Discord', 'tet-order-notifications' ),
				'id'      => 'tet_notify_discord_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title' => __( 'Webhook URL', 'tet-order-notifications' ),
				'id'    => 'tet_notify_discord_webhook',
				'type'  => 'password',
				'css'   => 'min-width:400px;',
				'placeholder' => 'https://discord.com/api/webhooks/…',
				'custom_attributes' => [ 'autocomplete' => 'off' ],
			],
			[ 'type' => 'tet_notify_test', 'id' => 'tet_notify_discord_test', 'channel' => 'discord', 'is_option' => false ],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_discord' ],

			// Webhook.
			[
				'title' => __( 'Webhook', 'tet-order-notifications' ),
				'type'  => 'title',
				'id'    => 'tet_notify_webhook',
			],
			[
				'title'   => __( 'Enable', 'tet-order-notifications' ),
				'desc'    => __( 'Send new orders to a webhook', 'tet-order-notifications' ),
				'id'      => 'tet_notify_webhook_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title' => __( 'URL', 'tet-order-notifications' ),
				'id'    => 'tet_notify_webhook_url',
				'type'  => 'text',
				'css'   => 'min-width:400px;',
				'placeholder' => 'https://',
			],
			[
				'title'    => __( 'Secret', 'tet-order-notifications' ),
				'desc'     => __( 'Optional. Each request then carries an X-Tet-Signature header: sha256= and the HMAC-SHA256 of the body with this secret.', 'tet-order-notifications' ),
				'id'       => 'tet_notify_webhook_secret',
				'type'     => 'password',
				'css'      => 'min-width:400px;',
				'custom_attributes' => [ 'autocomplete' => 'off' ],
			],
			[ 'type' => 'tet_notify_test', 'id' => 'tet_notify_webhook_test', 'channel' => 'webhook', 'is_option' => false ],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_webhook' ],
		];
	}

	private static function message_fields(): array {
		return [
			[
				'title' => __( 'When to notify', 'tet-order-notifications' ),
				'type'  => 'title',
				'id'    => 'tet_notify_triggers',
				'desc'  => __( 'An order is announced once, the first time it reaches one of these statuses. Orders awaiting payment (e.g. a card payment that was not completed) are not announced.', 'tet-order-notifications' ),
			],
			[
				'title'   => __( 'Order statuses', 'tet-order-notifications' ),
				'id'      => 'tet_notify_statuses',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'css'     => 'min-width:400px;',
				'options' => wc_get_order_statuses(),
				'default' => [ 'wc-processing', 'wc-on-hold' ],
			],
			[
				'title'   => __( 'Orders created in the admin', 'tet-order-notifications' ),
				'desc'    => __( 'Also announce orders created by hand in the admin (e.g. phone orders)', 'tet-order-notifications' ),
				'id'      => 'tet_notify_include_admin',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[
				'title'   => __( 'Debug log', 'tet-order-notifications' ),
				'desc'    => __( 'Log every message sent (WooCommerce → Status → Logs, source tet-order-notifications). Failures are always logged.', 'tet-order-notifications' ),
				'id'      => 'tet_notify_debug',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_triggers' ],

			[
				'title' => __( 'Message', 'tet-order-notifications' ),
				'type'  => 'title',
				'id'    => 'tet_notify_message',
				'desc'  => __( 'Used by Telegram, Slack and Discord. Leave empty for the default text.', 'tet-order-notifications' ),
			],
			[
				'title'       => __( 'Title', 'tet-order-notifications' ),
				'desc'        => __( 'Shown in bold as the first line.', 'tet-order-notifications' ),
				'id'          => 'tet_notify_title',
				'type'        => 'text',
				'css'         => 'min-width:400px;',
				'placeholder' => self::default_title(),
			],
			[
				'title'       => __( 'Body', 'tet-order-notifications' ),
				'id'          => 'tet_notify_body',
				'type'        => 'textarea',
				'css'         => 'min-width:400px;height:120px;',
				'placeholder' => self::default_body(),
			],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_message' ],
		];
	}

	private static function alert_fields(): array {
		$roles = [];
		foreach ( wp_roles()->get_names() as $key => $name ) {
			$roles[ $key ] = translate_user_role( $name );
		}

		return [
			[
				'title' => __( 'Alerts in the admin', 'tet-order-notifications' ),
				'type'  => 'title',
				'id'    => 'tet_notify_live',
				'desc'  => __( 'While someone is logged in to the admin, a new order shows a pop-up on every admin page and plays a sound. Each user can mute it from the bell in the admin bar.', 'tet-order-notifications' ),
			],
			[
				'title'   => __( 'Enable', 'tet-order-notifications' ),
				'desc'    => __( 'Show new orders in the admin as they arrive', 'tet-order-notifications' ),
				'id'      => 'tet_notify_live_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[
				'title'    => __( 'User roles', 'tet-order-notifications' ),
				'desc_tip' => __( 'Users with these roles get the alerts. They also need permission to edit orders.', 'tet-order-notifications' ),
				'id'       => 'tet_notify_live_roles',
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:400px;',
				'options'  => $roles,
				'default'  => [ 'administrator', 'shop_manager' ],
			],
			[
				'title'    => __( 'Check every', 'tet-order-notifications' ),
				'desc'     => __( 'seconds (10–120). In a background browser tab, browsers may check only once a minute.', 'tet-order-notifications' ),
				'id'       => 'tet_notify_live_interval',
				'type'     => 'number',
				'default'  => '15',
				'css'      => 'width:80px;',
				'custom_attributes' => [ 'min' => 10, 'max' => 120, 'step' => 1 ],
			],
			[
				'title'   => __( 'Desktop notifications', 'tet-order-notifications' ),
				'desc'    => __( 'Also show a system notification when the admin tab is in the background (each browser asks for permission once)', 'tet-order-notifications' ),
				'id'      => 'tet_notify_live_desktop',
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_live' ],

			[
				'title' => __( 'Sound', 'tet-order-notifications' ),
				'type'  => 'title',
				'id'    => 'tet_notify_sound',
				'desc'  => __( 'Browsers only play sound after you click somewhere on the page. Until then the bell in the admin bar shows "Click to enable sound".', 'tet-order-notifications' ),
			],
			[
				'title'   => __( 'Sound', 'tet-order-notifications' ),
				'id'      => 'tet_notify_live_sound',
				'type'    => 'select',
				'default' => 'voice',
				'options' => [
					'voice'  => __( 'Chime and spoken message', 'tet-order-notifications' ),
					'chime'  => __( 'Chime', 'tet-order-notifications' ),
					'custom' => __( 'My own sound file', 'tet-order-notifications' ),
					'none'   => __( 'No sound', 'tet-order-notifications' ),
				],
			],
			[
				'title'       => __( 'Spoken message', 'tet-order-notifications' ),
				'desc'        => __( 'Leave empty to use the recorded message that comes with the plugin (Greek and English). Your own text is read by the browser\'s voice for the admin language; if the computer has no voice for that language, only the chime plays.', 'tet-order-notifications' ),
				'id'          => 'tet_notify_live_voice_text',
				'type'        => 'text',
				'css'         => 'min-width:400px;',
				'placeholder' => self::default_voice_text(),
			],
			[
				'title' => __( 'Sound file', 'tet-order-notifications' ),
				'desc'  => __( 'An MP3, OGG or WAV from the media library, e.g. your own recording.', 'tet-order-notifications' ),
				'id'    => 'tet_notify_live_sound_url',
				'type'  => 'tet_notify_media',
			],
			[
				'title'   => __( 'Repeat', 'tet-order-notifications' ),
				'desc'    => __( 'Repeat the sound every 30 seconds until the pop-up is closed', 'tet-order-notifications' ),
				'id'      => 'tet_notify_live_repeat',
				'type'    => 'checkbox',
				'default' => 'no',
			],
			[ 'type' => 'sectionend', 'id' => 'tet_notify_sound' ],
		];
	}

	/* ── Custom field types ─────────────────────────────────── */

	public static function render_test_field( array $field ): void {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"></th>
			<td class="forminp">
				<button type="button" class="button tet-notify-test" data-channel="<?php echo esc_attr( $field['channel'] ); ?>"><?php esc_html_e( 'Send test message', 'tet-order-notifications' ); ?></button>
				<span class="tet-notify-test-result" aria-live="polite"></span>
				<p class="description"><?php esc_html_e( 'Uses the values above, even before you save, and your latest order (or sample data).', 'tet-order-notifications' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function render_media_field( array $field ): void {
		$value = (string) get_option( $field['id'], '' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
			<td class="forminp">
				<input type="url" name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" value="<?php echo esc_attr( $value ); ?>" style="min-width:400px;" />
				<button type="button" class="button tet-notify-media" data-target="<?php echo esc_attr( $field['id'] ); ?>"><?php esc_html_e( 'Choose file', 'tet-order-notifications' ); ?></button>
				<button type="button" class="button tet-notify-preview" data-target="<?php echo esc_attr( $field['id'] ); ?>"><?php esc_html_e( 'Play', 'tet-order-notifications' ); ?></button>
				<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
			</td>
		</tr>
		<?php
	}

	/* ── Test message (AJAX) ────────────────────────────────── */

	public static function ajax_test(): void {
		check_ajax_referer( 'tet_notify_test', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'tet-order-notifications' ) ], 403 );
		}
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! isset( Tet_Notify_Channels::labels()[ $channel ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown channel.', 'tet-order-notifications' ) ] );
		}

		// Values typed in the form (secret fields are rendered with the saved value).
		$posted = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Tet_Notify_Channels::config().
		$config = Tet_Notify_Channels::config( $channel, $posted );
		$error  = Tet_Notify_Channels::config_error( $channel, $config );
		if ( $error ) {
			wp_send_json_error( [ 'message' => $error ] );
		}

		$orders = wc_get_orders( [ 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ] );
		$data   = $orders ? Tet_Notify_Order_Data::from_order( $orders[0] ) : Tet_Notify_Order_Data::sample();
		$result = Tet_Notify_Channels::send( $channel, $config, $data, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success();
	}
}
