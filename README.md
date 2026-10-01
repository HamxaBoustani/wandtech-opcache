# WandTech OPcache (MU-Plugin)

**High-performance, in-place OPcache monitor and flush controller for WordPress Admin Bar.**

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://github.com/wandtech)
[![Requires PHP](https://img.shields.io/badge/PHP-8.1%2B-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPLv2%2B-green.svg)](LICENSE)

---

## Overview

**WandTech OPcache** is a single-file Must-Use (MU) plugin designed for administrators and DevOps engineers. It surfaces real-time PHP OPcache memory consumption and cached script counters directly in the WordPress Admin Bar (Toolbar) and provides a secure, in-place AJAX cache flush action without reloading or disrupting the administrator's workflow.

Unlike traditional plugins that execute heavy OPcache inspections synchronously during page generation or perform destructive flushes via unprotected `GET` requests, WandTech OPcache is engineered with strict off-critical-path execution, dual-tier client/server caching, and zero speculative-prefetch exposure.

---

## Architectural Highlights

* **Off-Critical-Path Rendering:** Initial page loads render lightweight, static Admin Bar placeholders. The real OPcache inspection occurs asynchronously via non-blocking JavaScript `fetch()`.
* **Zero-Reload In-Place Flush:** The flush action executes via an asynchronous `POST` AJAX request. Status badges, cached file counts, and memory indicators refresh immediately in the current view without screen flashes, lost form data, or page redirects.
* **Prefetch & Prerender Immune:** Eliminates state mutation via `GET` query strings (`admin-post.php` or direct links). Modern browser engines (Chrome Speculation Rules) and link prefetching extensions cannot inadvertently trigger OPcache purges.
* **Dual-Tier State Caching (Stale-While-Revalidate):**
  * **Server-Side (`wp_cache`):** Caches status summaries for 5 seconds to eliminate Cache Stampedes when multiple administrators browse simultaneously.
  * **Client-Side (`sessionStorage`):** Prevents unnecessary network requests during rapid admin navigation within the TTL window.
* **Double-Escaping Prevention:** Employs plain translations (`__()`) consumed exclusively through DOM `.textContent` assignments, preventing HTML entity leakage (e.g., `&#039;`) in localized strings while maintaining complete XSS immunity.
* **Defense-in-Depth Security:** Employs distinct cryptographic nonces for read (`status`) versus write (`flush`) operations, strictly gated behind `manage_options` permissions and delivering RFC-compliant HTTP 403 JSON payloads.

---

## Zend Engine & Sysadmin Caveats

Understanding how PHP OPcache operates at the process level is essential when operating this plugin:

### 1. PHP-FPM Shared Memory (SHM)
In standard PHP-FPM configurations, all worker processes within a pool share a single Shared Memory (SHM) segment. Executing an in-memory reset via `opcache_reset()` schedules a restart across that entire pool. All workers attached to that SHM segment will invalidate and recompile scripts.

### 2. The `opcache.file_cache` Limitation
If your PHP configuration employs secondary disk caching via `opcache.file_cache`:
> **Notice:** The native PHP `opcache_reset()` function resets **in-memory SHM cache only**. It does **not** prune precompiled `.bin` bytecode files written to disk. If workers reload bytecode from the file cache, an operating system-level directory purge or PHP-FPM service reload (`systemctl reload php-fpm`) is required.

### 3. Concurrency & Race-Condition Handling
When multiple flush requests arrive concurrently, the PHP engine sets internal flags (`restart_pending` / `restart_in_progress`), causing secondary calls to `opcache_reset()` to return `false`. WandTech OPcache inspects these internal Zend engine flags directly, preventing false-negative "Failed to flush!" error notices when a restart is already successfully progressing.

### 4. Clustered & Multi-Server Environments
Because `opcache_reset()` operates within the scope of the local server's memory, invoking a flush from the WordPress dashboard resets only the specific web node handling that HTTP request. In load-balanced or multi-container clusters, orchestrate cluster-wide invalidations via your deployment pipeline, webhooks, or centralized cache tools.

---

## Installation

Because this is a Must-Use plugin, it requires no manual activation in the WordPress admin panel:

1. Connect to your web server via SFTP, SSH, or your control panel file manager.
2. Navigate to your WordPress `wp-content` directory.
3. If it does not exist, create the `mu-plugins` directory:
   ```bash
   mkdir -p wp-content/mu-plugins
   ```
4. Copy `wandtech-opcache.php` directly into `wp-content/mu-plugins/`:
   ```bash
   cp wandtech-opcache.php /path/to/wordpress/wp-content/mu-plugins/
   ```

To uninstall, simply delete the file from `wp-content/mu-plugins/`.

---

## Thresholds & Configuration

The plugin is designed to operate with zero configuration out of the box. However, advanced users can modify internal constants within `wandtech-opcache.php`:

| Constant | Default | Description |
| :--- | :--- | :--- |
| `WARNING_THRESHOLD` | `80` | Memory usage percentage at which the badge color switches to warning (Orange). |
| `CRITICAL_THRESHOLD` | `95` | Memory usage percentage at which the badge color switches to critical (Red). |
| `STATUS_CACHE_TTL` | `5` | Server and client cache window (in seconds) to prevent redundant status polling. |
| `CACHE_GROUP` | `'wandtech-opcache'` | Cache group utilized by `wp_cache_*` functions. |

---

## Security Specifications

* **Capability Guard:** Only authenticated users satisfying `current_user_can('manage_options')` can fetch status metrics, trigger flushes, or view admin nodes.
* **Separated Nonce Verification:** Read and mutate actions utilize independent nonces (`wandtech_opcache_status_nonce` vs `wandtech_opcache_flush_nonce`).
* **Non-terminating Nonce Validation:** Nonces are validated via `check_ajax_referer( ..., ..., false )`. Failed tokens return structured JSON error payloads with `403 Forbidden` HTTP response codes rather than standard WordPress `-1` termination strings.
* **Zero Output Parsing Hazards:** All dynamic UI variables are populated using `Node.textContent`, completely isolating the client from DOM-based XSS vectors.

---

## Technical Requirements

* **PHP:** 8.1 or higher (PHP 8.2+ and 8.3 fully supported).
* **PHP Extensions:** `Zend OPcache` enabled (`opcache_get_status` and `opcache_reset` must not be restricted via `disable_functions` or `opcache.restrict_api`).
* **WordPress:** 6.0 or higher.
* **Browser Compatibility:** Any modern browser supporting ECMAScript 6+ (`fetch`, `sessionStorage`, `URLSearchParams`).

---

## License

This project is licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
