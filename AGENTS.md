# AGENTS.md — Site0 Ticketing

## Build / Lint / Test Commands

- **Lint**: `find . -name "*.php" -not -path "./node_modules/*" -exec php -l {} \;`
- No build step or automated test suite. Plugin is PHP loaded directly by WordPress.
- Manual verification is done via WP-CLI against a running Local by Flywheel site DB.

## Code Style Guidelines

Follow the same standards as the surrounding `mu-plugins` (see `wp-content/mu-plugins/AGENTS.md`):

- WordPress Coding Standards; 4-space indentation; braces on the same line.
- Classes: `PascalCase` with a `Site0_Ticketing_` prefix. File names `class-*.php`.
- Functions: `snake_case`. Constants: `UPPER_CASE`.
- Proper PHPDoc blocks on every class and public method.
- Use namespaces only if adding new vendor-free classes; current classes use prefixed class names instead (matches `mu-plugins` convention).
- Import WordPress classes with `use` where helpful.

### Security
- Sanitize all inputs (`sanitize_text_field`, `intval`, `wp_kses_post`, etc.).
- Escape all outputs (`esc_html`, `esc_url`, `esc_attr`).
- Use nonces on every form with a `wp_verify_nonce` guard.
- Validate capabilities before privileged operations.

### WordPress Integration
- Use `$wpdb->base_prefix` for the network ticket tables — never `$wpdb->prefix` (which changes per site).
- All ticket access must go through `Site0_Ticketing_Capabilities`.
- Internationalize with `__()` / `esc_html__()` and the `site0-ticketing` text domain.
- Comment only where logic is non-obvious; keep code self-documenting.

## Architecture Notes

- Schema: `site0_tickets` and `site0_ticket_replies` created by `Site0_Ticketing_Install::create_tables()` (dbDelta) on network activation; upgraded via `maybe_upgrade()`.
- Tenant UI: `Site0_Ticketing_Tenant_Page` (subsite admin menu + list table).
- Network UI: `Site0_Ticketing_Network_Page` (network admin menu + list table).
- Unread bubbles are built into each side's menu registration (`register_menu()`); there is no separate notices class.
- Status transitions are centralized in `Site0_Ticketing_Tickets` — do not write raw status values elsewhere.

## Development Guidelines

- Do not modify plugin files on the live site directly. If a change must be applied through WordPress tooling, route it through the `site0-companion.php` mu-plugin unless instructed otherwise.
- Run `php -l` on every file after editing.
