# Site0 Ticketing

Support ticketing for the Site0 WordPress multisite network.

- **Tenants** open and track tickets from their own subsite's dashboard (**Tickets** menu).
- **Network admins** view and answer every site's tickets from the network dashboard (**Tickets** menu).
- Tenants see an unread bubble on the subsite **Tickets** menu whenever a ticket gets a new reply.

## Requirements

- WordPress 5.0+ with multisite enabled (subdomain/subdirectory)
- PHP 7.4+
- Network-activated plugin

## Features

- Custom network DB tables (`wp_s0_tickets`, `wp_s0_ticket_replies`) — no per-site data, no blog switching.
- Ticket stages: **waiting**, **answered**, **closed**.
  - Tenant replies move a ticket back to *waiting*.
  - Network admin replies set it to *answered* and flag it **unread for the tenant**.
- Unread bubbles in both the tenant menu and the network admin menu.
- Per-site tenant access control (a tenant can only see tickets for their own site).
- Network admin: search, status filtering, bulk close / mark-waiting / delete, single reply, close, reopen, delete.
- RTL-aware styles, translatable strings (text domain `site0-ticketing`), inherits the Site0 IRANSansX admin font.

## Installation

1. Activate the plugin from **Network Admin → Plugins** (network activation required).
2. Tables are created automatically on activation.

## Usage

### Tenant (per subsite)
- Dashboard → **Tickets** → **Open a new ticket**.
- **Your tickets** lists your tickets; **Last Update** and badges reflect stage.
- Open a ticket to read the thread and reply; tenant replies set it to *waiting*.
- Close a ticket with the **Close Ticket** button.

### Network admin
- Network Dashboard → **Tickets**.
- The menu bubble shows how many tickets are waiting for a reply.
- Open a ticket to see its site/author context and the full thread, then reply.
- Use the top/bottom bulk actions to close, mark waiting, or delete selected tickets.

## Data & Cleanup

- Deactivation **never** deletes data.
- On **uninstall**, the ticket tables are **kept by default**. To drop them, a network admin must enable **Settings → Delete all ticket data when the plugin is uninstalled** on the network Tickets screen (network option `site0_ticketing_delete_on_uninstall`) before uninstalling.
- The plugin can only be **network-activated** — single-site activation attempts are rejected — and it is hidden from subsite plugin lists, so tenants cannot disable it or touch the data.

## Support

For issues, check `wp-content/debug.log` and `WP_DEBUG`. Custom development for the Site0 WordPress multisite network.
