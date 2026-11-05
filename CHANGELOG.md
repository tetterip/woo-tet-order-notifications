# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-05

### Added

- New-order notifications to Telegram (one or more chats), Slack, Discord, extra email addresses and a generic JSON webhook (with optional HMAC signature). Sent in the background with Action Scheduler; failures are logged and added as an order note.
- One order is announced once, the first time it reaches a selected status (default: Processing, On hold). Orders awaiting payment, orders from before activation, orders older than 7 days and (optionally) orders created in the admin are skipped.
- Message title and body templates with tags (`{order_number}`, `{total}`, `{payment_method}`, `{customer_name}`, `{items}`…).
- "Send test message" per channel, using the values typed in the form and the latest order.
- Real-time alerts in wp-admin: pop-up on every admin page, chime, spoken message in the admin language or your own sound file, optional repeat, desktop notifications, order count in the tab title. Per-role access and per-user mute from a bell in the admin bar.
- Greek translation.
