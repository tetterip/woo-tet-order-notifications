## What and why

<!-- What does this change, and why? Link the issue it fixes: "Fixes #123". -->

## How it was tested

<!-- WordPress / WooCommerce / PHP versions, classic or block checkout, steps you followed. -->

## Checklist

- [ ] `php tests/run.php` passes
- [ ] Works on PHP 7.4 (no newer syntax or functions)
- [ ] Output escaped, input sanitised, nonce + capability checks on forms and AJAX
- [ ] New or changed strings are translated (`node tools/i18n/build.mjs`)
- [ ] `CHANGELOG.md` has a line under `Unreleased`
- [ ] Nothing changed inside `ttrp-common/`
