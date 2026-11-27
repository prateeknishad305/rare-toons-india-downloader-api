=== RareToons Downloader API ===
Contributors: raretoons
Tags: api, json, rest-api, anime, downloader
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A JSON API for the catalogue: series, episodes, quality variants, download links, and host pages resolved into direct file URLs.

== Description ==

Turns the site into a machine readable API.

* Every series, with its info block: name, season, episode count, year, runtime, genres, languages, qualities and synopsis.
* Every episode, with the quality variants the site publishes (Hindi Dub, Hindi Sub, FanDub, Tamil, Telugu and so on).
* Every download link, classified by host.
* Resolution: HubCloud, Pixeldrain, Argon and MEGA links are followed to the real file URL, with the filename, the exact size and the expiry time.

Two deployments are included. The plugin reads the posts from the database, so it is fast and never scrapes the site. The standalone service reads the same catalogue through the public WordPress REST API and can run on any LAMP host.

= Endpoints =

* `GET /wp-json/rai/v1/series` — list, with paging, search and category filters
* `GET /wp-json/rai/v1/series/{slug}` — one series with every episode and link
* `GET /wp-json/rai/v1/series/{slug}/episodes` — episodes only
* `GET /wp-json/rai/v1/series/{slug}/episodes/{number}` — one episode
* `GET /wp-json/rai/v1/series/{slug}/episodes/{number}/links` — one episode, every link resolved
* `GET /wp-json/rai/v1/resolve?url=` — resolve any single host URL
* `GET /wp-json/rai/v1/search?q=` — search
* `GET /wp-json/rai/v1/categories` — categories
* `GET /wp-json/rai/v1/stats` — statistics
* `GET /wp-json/rai/v1/health` — liveness check

= Authentication =

Optional, and switched on in Settings. The key travels in the `X-RAI-Key` header or as an `api_key` query parameter.

= Caching =

Parsed series are cached for an hour, resolved links for thirty minutes, because a HubCloud signature is only valid for about eight hours.

== Installation ==

1. Upload the plugin through Plugins → Add New → Upload Plugin, or copy the `rareanimes-downloader-api` folder into `wp-content/plugins/`.
2. Activate it.
3. Open Settings → Downloader API to set the key, the CORS origin and the cache times.

The plugin can also be dropped into `wp-content/mu-plugins/` if you cannot use the admin installer.

== Frequently Asked Questions ==

= Does the API host any files? =

No. It returns links to the third party hosts that the site already indexes.

= Why is a MEGA link flagged as requires_client? =

MEGA stores the payload encrypted with a key that only a MEGA aware client applies. The API still returns the direct URL, the size and the file key.

= Can I use the API from my own app? =

Yes. Point it at `https://www.rareanimes.foo/wp-json/rai/v1`, send your key, and read the JSON.

== Changelog ==

= 1.0.0 =
* First release.
