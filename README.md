# Order Notifications for WooCommerce

Get told about every new order the moment it arrives: on Telegram, Slack, Discord or through a webhook, and with a pop-up and sound inside the WordPress admin. HPOS and block checkout compatible.

## Features

- **Channels**: Telegram (bot, one or more chats or groups), Slack (incoming webhook), Discord (webhook) and a generic JSON webhook for n8n, Make, Zapier and the like (optional `X-Tet-Signature` HMAC header).
- **Sent straight from your site** to each service. No third-party relay; nothing is stored anywhere else.
- **Background sending** (Action Scheduler): a slow or unreachable service never delays the checkout. Failures are logged and noted on the order.
- **Once per order**, the first time it reaches a status you choose (default Processing and On hold). Card payments that were never completed are not announced.
- **Your own message**: title and body templates with tags such as `{order_number}`, `{total}`, `{payment_method}`, `{customer_name}`, `{city}`, `{items}`.
- **Test button** per channel.
- **Alerts in the admin**: a pop-up on every admin page with the total, customer and items, a chime and a spoken message («Έχετε νέα online παραγγελία») or your own sound file, optional repeat until closed, desktop notifications when the tab is in the background, and the number of new orders in the tab title. Choose which roles get them; each user can mute them from the bell in the admin bar.
- Translation-ready; Greek (`el`) included.

## Requirements

| Requirement | Value |
|---|---|
| PHP | 7.4+ |
| WordPress | 6.0+ |
| WooCommerce | 7.1+ |

## Setup

1. Go to **ttrp.gr Plugins → Order Notifications**.
2. **Channels**: enable the ones you want, fill in their details and press **Send test message**.
3. **Message**: pick the order statuses and adjust the text if you like.
4. **Admin alerts**: on by default for administrators and shop managers.

### About sound in the browser

Browsers only play sound on a page after you have clicked somewhere on it. Until then the bell in the admin bar shows
"Click to enable sound". The default spoken message is a recording that comes with the plugin (Greek and English). If you
write your own text, it is read by the voice your computer has for the admin language; without one, only the chime plays.
The recordings were made with an AI voice (OpenAI text-to-speech). In a background tab, browsers may check for new orders only about once a minute. For alerts that must
never be missed, also enable Telegram (or another channel) on your phone.

## Privacy

Messages go directly from your site to the services you enable (Telegram, Slack, Discord, your webhook URL). Whatever the message template includes is sent to them; the default includes the customer's name and city
but not their email or phone. The webhook payload includes the email and phone. Mention the services you use in your
privacy policy.

## Updates

This plugin gets its updates from the ttrp.gr update server (`https://plugins.ttrp.gr/`), not from WordPress.org.
When WordPress checks for updates, and when you open **ttrp.gr Plugins → All Plugins**, your site sends that server:

- the plugin's slug and installed version,
- the WordPress version and the site's address (in the request's `User-Agent` header),
- the admin language (All Plugins page only).

The server logs these requests, with the IP address, for 180 days and keeps a list of which sites run which plugin
version. No order, customer or other personal data is sent. Questions: info@ttrp.gr.

## Developers

- `tet_notify_order_data` (filter): the data array for an order; add keys and list them with `tet_notify_template_tags` to use them as tags.
- `tet_notify_template_tags` (filter): allowed template tags.
- `tet_notify_message` (filter): `['title', 'body']` per channel before sending.
- `tet_notify_should_notify` (filter): whether an order is announced now.
- `tet_notify_webhook_safe` (filter): return `false` to let the webhook reach local / private addresses.
- `tet_notify_new_order` (action): fires once per announced order.

## Security

Please report vulnerabilities privately to **info@ttrp.gr**, not in a public issue. See [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
