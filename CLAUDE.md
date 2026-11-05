# Order Notifications for WooCommerce — Claude Code Context

## Idea

Tells the shop about every new order the moment it arrives: on Telegram, Slack, Discord, by email or a JSON
webhook, and with a real-time pop-up + sound inside wp-admin («Έχετε νέα online παραγγελία»). v1 covers **new
orders only**; other events (cancellations, return requests, low stock) can reuse the same channels later.

Everything is sent straight from the shop's site to each service (no relay server), unlike "Got A Sale".

## Architecture

```
woo-tet-order-notifications.php        Bootstrap: constants, compat declarations, activation, requires, init
uninstall.php                          Drops the events table, tet_notify_* options, user meta, scheduled jobs
includes/
  class-tet-notify-message.php         Pure (no WP): template tags, per-channel formatting, payloads, validation, should_notify()
  class-tet-notify-order-data.php      WC_Order → flat plain-text array used by templates, channels and admin alerts; sample data
  class-tet-notify-channels.php        Channel config (options), config_error(), send() per channel via wp_remote_post / wp_mail
  class-tet-notify-events.php          Table {prefix}tet_notify_events: atomic "announce once" + cursor for the admin alerts
  class-tet-notify-dispatcher.php      Status hooks → should_notify → claim → Action Scheduler job → channels
  class-tet-notify-live.php            Admin alerts: REST endpoint, script data, admin bar bell, mute + nonce AJAX
  class-tet-notify-settings.php        ttrp.gr Plugins → Order Notifications (tabs: channels / message / alerts), option getters, test AJAX
assets/
  js/live.js                           Admin alerts (plain JS): polling, pop-ups, WebAudio chime, speechSynthesis, desktop notifications, bell
  js/settings.js                       "Send test message" + media picker (jQuery)
  css/live.css, css/settings.css
  icon-256.png                         Desktop notification icon (same artwork as the update server icon)
  ttrp-admin.css, ttrp-logo.svg, ttrp.svg   Shared ttrp.gr admin design system + icons
languages/                             tet-order-notifications.pot + Greek -el.po / -el.mo (generated)
tools/i18n/                            el.mjs (Greek), extract.mjs, build.mjs; not in the release zip
tests/                                 run.php (zero-dependency runner) + test-message.php
ttrp-common/                           Shared ttrp.gr library (synced from the update server repo; never edit)
```

No build step.

## Key decisions

- **When an order is announced** (`Tet_Notify_Message::should_notify()`): the first time it reaches a selected
  status (default `processing`, `on-hold`; never `pending`), created after first activation (`tet_notify_since`),
  not older than 7 days, and not created in the admin unless `tet_notify_include_admin`. Hooks:
  `woocommerce_order_status_changed` and `woocommerce_new_order` (orders created directly in a paid status).
- **Once, atomically**: `Tet_Notify_Events::claim()` is `INSERT IGNORE` on a UNIQUE `order_id`, so a gateway
  callback and the thank-you page changing status at once can't both announce. Order meta `_tet_notify_sent` is a
  second guard after the 30-day table cleanup.
- **Background sending**: `as_enqueue_async_action( 'tet_notify_send' )`; the checkout never waits. Failures go to
  the WC log (source `tet-order-notifications`) and an order note; successes are logged only with debug on.
- **Admin alerts poll, not push**: `GET /wp-json/tet-notify/v1/events?after=<id>` every `tet_notify_live_interval`
  seconds (default 15) from every admin page of allowed users (role in `tet_notify_live_roles` + `edit_shop_orders`).
  The cursor is the events table id, set at page load, so only orders after the page loaded pop up. Not the WP
  Heartbeat: it slows down in unfocused tabs. Browsers still throttle background tabs to ~1/min.
- **Expired REST nonce** (tab open > 12–24 h): on 401/403 the script asks `admin-ajax.php?action=tet_notify_nonce`
  for a new one; if that fails too, the user is logged out and the bell says so.
- **Sound**: browsers block audio until a click on the page, so the bell shows "Click to enable sound" until the
  first pointerdown/keydown. Chime = WebAudio oscillators (no file); voice = `speechSynthesis` with a voice for the
  user locale (else chime only); custom = a media-library URL. Several open tabs: `localStorage.tetNotifyHeard`
  makes only the first tab play the sound.
- **Secrets** (bot token, Slack/Discord webhook URLs, webhook secret) are plain options rendered in password
  fields. Telegram errors never include the request URL (it contains the token).
- **Webhook** uses `wp_safe_remote_post` (no private/local IPs) unless `tet_notify_webhook_safe` returns false.
- **Empty texts**: title, body and spoken message store `''`; getters fall back to the translated defaults, which
  are also the placeholders. The default body is tags only, so it isn't translatable.

## Options

| Option | Default | Notes |
|---|---|---|
| `tet_notify_{telegram,slack,discord,email,webhook}_enabled` | no | |
| `tet_notify_telegram_token`, `_chat_ids` | '' | Chat IDs comma separated (`-100…` groups, `@channel`) |
| `tet_notify_slack_webhook`, `tet_notify_discord_webhook` | '' | |
| `tet_notify_email_recipients` | '' | Comma separated |
| `tet_notify_webhook_url`, `_secret` | '' | Secret → `X-Tet-Signature: sha256=<hmac>` |
| `tet_notify_statuses` | `[wc-processing, wc-on-hold]` | |
| `tet_notify_include_admin` | no | |
| `tet_notify_title`, `tet_notify_body` | '' | Empty = default |
| `tet_notify_debug` | no | |
| `tet_notify_live_enabled` | yes | |
| `tet_notify_live_roles` | `[administrator, shop_manager]` | |
| `tet_notify_live_interval` | 15 | 10–120 s |
| `tet_notify_live_desktop` | yes | |
| `tet_notify_live_sound` | voice | voice / chime / custom / none |
| `tet_notify_live_voice_text`, `_sound_url` | '' | |
| `tet_notify_live_repeat` | no | Every 30 s while a pop-up is open |
| `tet_notify_since`, `tet_notify_db_version` | | Internal |

User meta `tet_notify_muted` (yes/no). Order meta `_tet_notify_sent` (timestamp).

## Hooks provided

`tet_notify_order_data`, `tet_notify_template_tags`, `tet_notify_message`, `tet_notify_should_notify`,
`tet_notify_webhook_safe` (filters); `tet_notify_new_order` (action). See README *Developers*.

## Conventions

- WordPress Coding Standards; escape all output, sanitise all input; nonces on every form/AJAX.
- PHP 7.4+ (no union types, `match`, nullsafe, `str_contains`).
- The admin plugin title `Tet_Notify_Settings::PLUGIN_TITLE` ("Order Notifications") is **not** translated (suite rule).
  Service names (Telegram, Slack, Discord) aren't either.
- Text domain `tet-order-notifications`, loaded on `init`. After changing strings, update `tools/i18n/el.mjs` and run
  `node tools/i18n/build.mjs`. Greek: order = «παραγγελία», notification = «ειδοποίηση», admin = «διαχειριστικό».
- `TET_NOTIFY_VERSION` comes from the header; bump `Version:`, README and `CHANGELOG.md` together.
- Releasing: push a `vX.Y.Z` tag (`/release`), or **Actions → Release → Run workflow** with the version (from cloud
  sessions, which can't push tags).

## Testing

- `php tests/run.php` (pure logic: tags, formatting, escaping, validation, should_notify).
- `assets/js/live.js` was checked in headless Chromium against a mocked endpoint (unlock, pop-up, title count, dedupe,
  mute). **Not yet run in a real WordPress** (the cloud session that wrote it couldn't reach wordpress.org): do the
  checklist below in `/playground` before the first release.

## Manual testing checklist

- [ ] Activate → table `tet_notify_events` exists; settings page has three tabs; `desc_tip` icons show text
- [ ] Each channel's "Send test message" with real credentials (Telegram group + private chat, Slack, Discord, email, webhook.site)
- [ ] Wrong token / URL → clear error under the button and a warning on the Channels tab
- [ ] Classic and block checkout with COD → one message per channel, one admin pop-up; bank transfer (on-hold) too
- [ ] Card payment left pending → nothing; completing it later → announced once
- [ ] Two admin tabs open → both show the pop-up, only one plays the sound
- [ ] Mute from the bell survives a reload; "Play the sound" works; voice in Greek on Windows/macOS
- [ ] Desktop notification with the tab in the background; clicking it opens the order
- [ ] Shop manager sees alerts; a role not selected doesn't; REST endpoint returns 401/403 for them
- [ ] Order created in the admin → only with the setting on
- [ ] Debug log shows sends; a failing channel adds an order note
- [ ] Uninstall removes table, options, user meta

## Roadmap / ideas (not scheduled)

- More events: cancelled / failed orders, return requests (`woo-tet-returns`), low stock, COD refused.
- Per-channel status filters and templates; minimum order total.
- ntfy.sh / Pushover push; SMS via a Greek provider (Yuboto, Routee).
- Telegram inline buttons ("Mark completed") via a webhook back to the site.
