# CLAUDE.md — AuraWP

This file provides context and conventions for AI assistants working in this repository.

---

## Project Overview

**AuraWP** is a WordPress plugin that acts as a remote site management agent for the [Aura dashboard](https://my-aura.app). It exposes secure REST API endpoints that allow Aura to monitor site health, apply updates (core, plugins, themes, translations), and perform database maintenance.

- **Language:** PHP 7.4+
- **Platform:** WordPress 6.2+
- **Auth:** Three-layer (WordPress Application Password + Aura Site Token + optional IP Whitelist)
- **REST Namespace:** `aura/v1`
- **License:** GPLv2 or later
- **Text Domain:** `aura-worker`

---

## Repository Structure

```
AuraWP/
├── aura-worker.php                          # Plugin entry point, activation/deactivation hooks
├── uninstall.php                            # Cleanup on uninstall (removes all options)
├── readme.txt                               # WordPress.org plugin readme
├── README.md                                # GitHub readme
├── aura_logotype.png                        # Logo asset
└── includes/
    ├── class-aura-worker.php                # Main orchestrator — admin menu, settings, wiring
    ├── class-aura-worker-api.php            # REST API route registration and handlers
    ├── class-aura-worker-updater.php        # Update operations (core, plugins, themes, translations, DB)
    └── class-aura-worker-security.php       # Three-layer authentication and permission callbacks
```

---

## Architecture

### Class Responsibilities

| Class | File | Role |
|-------|------|------|
| `Aura_Worker` | `class-aura-worker.php` | Orchestrator — creates Security and API instances, registers admin menu and settings |
| `Aura_Worker_API` | `class-aura-worker-api.php` | Registers all REST routes under `aura/v1`, handles request/response logic |
| `Aura_Worker_Updater` | `class-aura-worker-updater.php` | Wraps WordPress Upgrader classes for core/plugin/theme/translation/DB updates |
| `Aura_Worker_Security` | `class-aura-worker-security.php` | Implements IP whitelist, site token verification, and capability checks |

### Initialization Flow

1. `aura-worker.php` defines constants and loads all class files
2. `aura_worker_init()` runs on `plugins_loaded` — creates `Aura_Worker` and calls `init()`
3. `init()` creates `Aura_Worker_Security`, passes it to `Aura_Worker_API`
4. `Aura_Worker_API` internally creates its own `Aura_Worker_Updater` instance
5. REST routes are registered on `rest_api_init`
6. Admin settings page is registered on `admin_menu` / `admin_init` (admin only)

### Security Layers

Every REST request passes through three checks in order:

1. **IP Whitelist** (`check_ip_whitelist`) — If IPs are configured in settings, the client IP must match. Uses proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`) before `REMOTE_ADDR`.
2. **Aura Site Token** (`check_aura_token`) — `X-Aura-Token` header must match the stored token. Comparison uses `hash_equals()` for timing safety.
3. **WordPress Capability** — Read endpoints require `manage_options`, write endpoints require `update_plugins`.

---

## REST API Endpoints

All routes are under `/wp-json/aura/v1/`.

### Read Endpoints (require `manage_options`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/status` | Full site health: WP/PHP/MySQL versions, plugins, themes, disk usage, DB info |
| `GET` | `/updates` | Available updates for core, plugins, themes, translations. Add `?refresh=1` to force fresh check |

### Write Endpoints (require `update_plugins`)

| Method | Endpoint | Parameters | Description |
|--------|----------|------------|-------------|
| `POST` | `/update/core` | — | Update WordPress core |
| `POST` | `/update/plugin` | `plugin` (required, string) | Update a specific plugin by file path (e.g. `akismet/akismet.php`) |
| `POST` | `/update/theme` | `theme` (required, string) | Update a specific theme by slug |
| `POST` | `/update/translations` | — | Bulk update all translations |
| `POST` | `/update/database` | — | Run `wp_upgrade()` / `dbDelta()` |

---

## WordPress Options

| Option Key | Description |
|------------|-------------|
| `aura_worker_site_token` | 32-char alphanumeric token for API auth |
| `aura_worker_allowed_ips` | Newline-separated IP whitelist (empty = allow all) |
| `aura_worker_activated` | Activation timestamp |
| `aura_worker_version` | Plugin version at activation |

All options are cleaned up in `uninstall.php`.

---

## Code Conventions

### PHP Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- Tabs for indentation (not spaces)
- Yoda conditions are acceptable but not required
- All files must start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard
- Use WordPress i18n functions (`__()`, `esc_html_e()`) with text domain `aura-worker`

### Naming

| Kind | Convention | Example |
|------|-----------|---------|
| Classes | `Aura_Worker_*` prefix | `Aura_Worker_Security` |
| Files | `class-aura-worker-*.php` | `class-aura-worker-api.php` |
| Functions (global) | `aura_worker_*` prefix | `aura_worker_activate` |
| Options | `aura_worker_*` prefix | `aura_worker_site_token` |
| Constants | `AURA_WORKER_*` | `AURA_WORKER_VERSION` |
| REST namespace | `aura/v1` | — |
| Settings group | `aura_worker_settings` | — |

### Security Rules

- **Never store secrets in plaintext** — site tokens and credentials should be hashed before storage
- **Always use `$wpdb->prepare()`** for any SQL query with dynamic values, even trusted ones like `$wpdb->prefix`
- **Always use `sanitize_text_field()`** or appropriate sanitizer on user input
- **Always use `esc_attr()`, `esc_html()`, `esc_url()`** for output escaping
- **Use `hash_equals()`** for all token/secret comparisons (timing-safe)
- **Validate WordPress Upgrader return values thoroughly** — `Plugin_Upgrader::upgrade()` can return `true`, `false`, `null`, `WP_Error`, or an array depending on the outcome. Always check for `is_wp_error()`, `false === $result`, and `null === $result` before assuming success.
- **Use `wp_unslash()` before `sanitize_*()`** on `$_SERVER` values

### Error Handling

- Return structured arrays from updater methods: `array( 'success' => bool, 'message' => string )` or `array( 'success' => false, 'error' => string )`
- REST handlers wrap results in `WP_REST_Response` with appropriate HTTP status codes (200, 404, 500)
- Use `WP_Error` objects in security/permission callbacks — WordPress REST API will convert these to proper error responses

### Dependency Loading

- Use `require_once` for WordPress admin includes (they are not always loaded in REST context)
- Always check `function_exists()` before requiring admin files (e.g., `get_plugins`, `get_core_updates`)
- The `load_upgrade_dependencies()` method in the Updater class centralizes all upgrade-related includes

---

## Known Issues

These are known issues identified during code review that should be addressed:

1. **IP whitelist bypass** — `get_client_ip()` trusts spoofable proxy headers (`X-Forwarded-For`, `X-Real-IP`) before `REMOTE_ADDR`. Should only use `REMOTE_ADDR` or make proxy trust opt-in.
2. **`update_core()` missing `false` check** — `Core_Upgrader::upgrade()` returns `false` on filesystem failure, but only `is_wp_error()` is checked. Also passes `$result` (an array) to `sprintf()` instead of `$updates[0]->version`.
3. **`update_plugin()`/`update_theme()` treat `null` as success** — Upgraders can return `null` when no update is available; this falls through to the success branch.
4. **`update_translations()` dead error check** — `Language_Pack_Upgrader::bulk_upgrade()` never returns `WP_Error`, so the `is_wp_error()` check is unreachable. `false` return is silently reported as success.
5. **Raw SQL in `get_status()`** — `$wpdb->prefix` is interpolated into a `SHOW TABLES LIKE` query without `$wpdb->prepare()` or `$wpdb->esc_like()`.
6. **Token stored in plaintext** — The site token is stored as-is in `wp_options`. Should store a hash.
7. **Duplicate `require_once`** in `update_core()` — `class-wp-upgrader.php` is loaded by both `load_upgrade_dependencies()` and an explicit `require_once` on the next line.

---

## Testing

There are currently no automated tests. When adding tests:

- Use [WP_Mock](https://github.com/10up/wp_mock) or WordPress's `WP_UnitTestCase` for unit/integration tests
- Test each updater method with mock return values (`true`, `false`, `null`, `WP_Error`)
- Test security layers independently (IP check, token check, capability check)
- Test REST endpoint registration and response shapes

---

## Relationship to Aura

AuraWP is the WordPress-side companion to the [Aura Infrastructure Hub](https://my-aura.app) (Next.js dashboard). Aura manages cloud resources across Cloudways, Hostinger VPS, Cloudflare, and Bunny.net. AuraWP extends that reach into individual WordPress installations, allowing Aura to monitor and update sites remotely.

The communication flow is:
```
Aura Dashboard → HTTP REST → WordPress (AuraWP plugin)
                  ↑
          Application Password + X-Aura-Token header
```
