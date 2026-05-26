# Bullion Ops Helper

WordPress plugin used by [Bullion Media](https://bullionmedia.com.au) ops tooling. Exposes a small set of authenticated REST endpoints that programmatic clients can call to manage redirects, regenerate Elementor pages, and purge caches.

## Endpoints

All endpoints require an authenticated request from a user with `manage_options` capability (typical pattern: WordPress application password sent as HTTP Basic auth).

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/wp-json/bullion/v1/ping` | Sanity check: returns plugin version + detected dependencies (WP Rocket, Cloudflare, Rank Math, Elementor). |
| `GET`  | `/wp-json/bullion/v1/redirect?source=...` | List Rank Math redirects (optionally filtered by source path). |
| `POST` | `/wp-json/bullion/v1/redirect` | Create or update a Rank Math redirect. Body: `source`, `destination`, `type` (301/302/307/410/451), `comparison` (exact/contains/start/end/regex). |
| `DELETE` | `/wp-json/bullion/v1/redirect/{id}` | Delete a Rank Math redirect by id. |
| `POST` | `/wp-json/bullion/v1/cache/purge` | Purge WP Rocket (domain + minified + used-CSS) and Cloudflare. |
| `POST` | `/wp-json/bullion/v1/elementor/regenerate/{post_id}` | Force a single Elementor-managed page to rebuild its rendered content + per-post CSS, then run a full cache purge. Use after REST-API-driven Elementor page edits where the editor's `save_builder` hook never fires. |

## Auto-updates

The plugin self-updates from GitHub Releases via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker). When a new release is tagged on this repo, every WordPress site running the plugin sees an "Update Available" notice within ~12 hours (or instantly via Dashboard → Updates → Check Again) and can update with one click.

## Install

1. Download the latest release zip from [Releases](https://github.com/BullionMedia/bullion-ops-helper/releases).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip.
3. Activate.

After install, future updates come through WordPress's normal plugin update flow.

## Why this exists

Most ops automation against WordPress can be done with the standard `/wp/v2/*` REST API. Three specific things can't:

- **Rank Math redirects** have no REST endpoint of their own.
- **WP Rocket / Cloudflare cache purges** are hookable from PHP but not from REST.
- **Elementor pages edited via REST** don't trigger Elementor's editor-side regeneration, so the page renders stale even after a successful save.

This plugin fills those three gaps with a single, minimal REST surface.

## License

MIT. See [LICENSE](LICENSE).
