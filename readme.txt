=== Calluna Companion ===
Contributors: callunalabs
Tags: rest-api, seo, content-pipe, headless, maintenance, monitoring, dashboard
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.5.0
License: GPLv2 or later
Plugin URI: https://github.com/callunaLabs/calluna-companion-wp

Bridge zwischen WordPress und Calluna (Content Pipe + Dashboard). Bündelt SEO-
Felder (Yoast/RankMath/AIOSEO), Featured-Image-Sideload, flachen Posts-Endpoint
und Maintenance-Layer (Health, Plugin-Updates, Multi-Cache-Clear inkl. WP Rocket
+ Elementor).

== Beschreibung ==

Dieses Plugin macht aus deiner WordPress-Installation einen normalisierten
Content- und Maintenance-Endpoint, den Calluna nutzen kann, um:

* Artikel inkl. SEO-Meta zu lesen und zu schreiben
* Featured Images per URL hochzuladen
* Kategorien und Tags inkl. Primary-Term zu verwalten
* Plugin-Aktivierungsstatus auszulesen (Yoast, RankMath, AIOSEO, ACF, etc.)
* Health-Snapshots zu liefern (WP-/PHP-Version, debug.log-Tail, Update-Counts)
* Plugin-Updates inkl. Versions-Diff fernzusteuern
* Multi-Layer Cache-Clears auszulösen (Core + WP Rocket + Elementor + W3TC +
  Super-Cache + Autoptimize + OPcache)

Alle Endpoints liegen unter `/wp-json/calluna/v1/`. Authentifizierung läuft
über Application-Passwords. Maintenance-Endpoints erfordern `manage_options`
bzw. `update_plugins`.

== Installation ==

Empfohlen (Calluna Dashboard):

1. Im Calluna Dashboard auf `/websites/kunden/new` „Mit WordPress verbinden"
   klicken — der Authorize-Flow generiert das App-Password automatisch.
2. Plugin via ZIP-Upload (aus GitHub Release v0.4.0+) oder dieses Plugin
   einmal manuell installieren.
3. Folgeupdates kommen automatisch über den WP-Update-Mechanismus (Plugin
   Update Checker pollt GitHub-Releases alle 12 h).

Manuell (Content Pipe oder Headless):

1. ZIP aus GitHub-Releases laden, in `wp-content/plugins/` entpacken.
2. In WordPress unter „Plugins" aktivieren.
3. Application-Password unter Users → Profile → Application Passwords
   erstellen und in Calluna eintragen.

== Endpoints ==

Content Pipe:

* `GET  /wp-json/calluna/v1/info`
* `GET  /wp-json/calluna/v1/posts?page=1&per_page=20&search=...&status=any`
* `GET  /wp-json/calluna/v1/posts/{id}`
* `POST /wp-json/calluna/v1/posts/{id}` (Update inkl. SEO + Featured Image)

Dashboard Maintenance:

* `GET  /wp-json/calluna/v1/maintenance/health`
  WP-/PHP-Version, debug.log-Tail (max 8KB), Update-Counts, erkannte Cache-Provider,
  Versionen kritischer Plugins (Elementor, WP-Rocket, WooCommerce, SEO).
* `GET  /wp-json/calluna/v1/maintenance/plugins`
  Komplettes Plugin-Inventory mit `update_available` + `new_version`.
* `POST /wp-json/calluna/v1/maintenance/cache/clear`
  Multi-Layer-Flush. Reihenfolge: Core Object → WP Rocket (Domain + Minify +
  Critical-CSS + Advanced-Cache) → Elementor → W3TC → Super-Cache → Autoptimize
  → OPcache. Liefert geleerte Layer als Array zurück.
* `POST /wp-json/calluna/v1/maintenance/plugins/{slug}/update`
  Triggert `Plugin_Upgrader->upgrade()` für das Plugin mit diesem Slug
  (= erstes Pfadsegment vom Plugin-File, z. B. `wp-rocket`). Liefert
  `from_version` + `to_version` + Upgrader-Messages zurück.

Außerdem wird das Feld `calluna_seo` an `/wp-json/wp/v2/posts` registriert,
sodass es ohne Plugin-Pfad gelesen und geschrieben werden kann.

== Changelog ==

= 0.5.0 =
* Plugin pings monitor.calluna.ai on activation and daily via WP-Cron — enables auto-discovery of sites with the companion installed.
* Heartbeat URL is overridable via `calluna_monitor_heartbeat_url` filter. Returning a falsy value disables the heartbeat.
* Optional shared secret via `CALLUNA_MONITOR_REGISTER_TOKEN` constant in `wp-config.php`.

= 0.4.0 =
* Plugin in eigenes Repo `callunaLabs/calluna-companion-wp` ausgelagert
  (vorher in `content-pipe/wp-plugin/`).
* Umbenennung: „Calluna Content Pipe Companion" → „Calluna Companion".
* Auto-Update via Plugin Update Checker v5.7 + GitHub-Releases. Updates
  erscheinen ab dieser Version automatisch in WP-Admin → Plugins, wie bei
  Plugins aus dem WP.org-Verzeichnis.
* Slug + REST-Namespace (`calluna/v1`) unverändert — kein Breaking Change
  für bestehende Konsumenten (Content Pipe + Dashboard).

= 0.3.0 =
* Maintenance-Layer für Calluna Dashboard:
  - `/maintenance/health` — Health-Snapshot + debug.log-Tail
  - `/maintenance/plugins` — Plugin-Inventory + Update-Status
  - `/maintenance/cache/clear` — Multi-Layer-Flush (WP Rocket + Elementor + …)
  - `/maintenance/plugins/{slug}/update` — Plugin-Upgrade per Plugin_Upgrader
* `critical_plugins`-Detection erweitert (WP Rocket, Elementor Pro, WPSeo,
  Rank Math, WP Super Cache, W3TC, Autoptimize).

= 0.2.0 =
* Public `/health` Endpoint für Onboarding-v2 Plugin-Detection.

= 0.1.0 =
* Initial release: SEO-Bridge, erweiterter Posts-Endpoint, Featured-Image-Sideload.
