# Contributing to Order Notifications for WooCommerce

Thanks for helping. Bug reports, ideas, translations and pull requests are all welcome.

## Before you start

- **Security issues:** please don't open a public issue. Email **info@ttrp.gr** (see [SECURITY.md](../SECURITY.md)).
- **Bugs:** [open a bug report](https://github.com/tetterip/woo-tet-order-notifications/issues/new?template=bug_report.yml) with the plugin, WordPress, WooCommerce and PHP
  versions and the steps to reproduce it.
- **Bigger changes:** open an issue first, so we can agree on the approach before you spend time on it.

## Development setup

You need a WordPress site with WooCommerce (LocalWP, `wp-env`, [WordPress Playground](https://playground.wordpress.net/)
or similar). Clone this repository into `wp-content/plugins/` and activate the plugin. There is no Composer or npm step
to run it.

## Coding guidelines

- **PHP 7.4** is the minimum: no syntax or functions newer than that. Test on a current PHP too.
- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`), sanitise all input, and check a nonce and a
  capability on every form and AJAX action.
- Orders: use `$order->get_meta()` / `$order->update_meta_data()`, never `get_post_meta()`, so HPOS keeps working.
- Wrap every user-facing string in `__()` / `esc_html__()` with the text domain `tet-order-notifications`. Never build a translatable
  string by concatenation; use `sprintf()` with a `/* translators: */` comment.
- **Don't edit `ttrp-common/`.** It is a copy of a shared library that is synced from another repository, and any
  change there is overwritten.

## Translations

Source strings are English; the Greek translation is bundled. After adding or changing a string:

1. Add its Greek to `tools/i18n/el.mjs`.
2. Run `node tools/i18n/build.mjs`. It regenerates the `.pot`, `-el.po` and `-el.mo` in `languages/` and fails on a
   missing or unused translation.

New languages are welcome as a pull request with the `.po` and `.mo` files.

## Tests

```bash
php tests/run.php
```

Please add a test when you add or change pure logic (no WordPress calls).

A health check (PHP compatibility, WordPress security and performance sniffs) runs in GitHub Actions on every push to the
default branch and twice a month.

## Pull requests

- One topic per pull request; keep unrelated clean-ups out.
- Commit messages: a short imperative subject without a full stop, e.g. `Fix fee not removed when COD is deselected`.
- Add a line to `CHANGELOG.md` under an `Unreleased` heading. Version numbers and releases are handled by the maintainer.

## License

By contributing you agree that your contribution is licensed under the same license as the project, GPL-2.0-or-later
(see [LICENSE](../LICENSE)).
