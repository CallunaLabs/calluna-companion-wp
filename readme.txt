=== Calluna Companion ===
Contributors: callunalabs
Tags: rest-api, seo, content-pipe, headless, maintenance, monitoring, dashboard
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.8.6
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
  → OPcache → Raidboxes Server-Cache (wenn erkannt). Liefert geleerte Layer als
  Array + `raidboxes`-Detail zurück.
* `POST /wp-json/calluna/v1/maintenance/cache/raidboxes`
  Dedizierter Raidboxes Varnish/Nginx-Purge (Action-Hook → Plugin-Funktion →
  HTTP PURGE → HTTP BAN). Gibt `{ok, attempts}` zurück. Liefert 400 wenn Site
  nicht auf Raidboxes läuft.
* `POST /wp-json/calluna/v1/maintenance/plugins/{slug}/update`
  Triggert `Plugin_Upgrader->upgrade()` für das Plugin mit diesem Slug
  (= erstes Pfadsegment vom Plugin-File, z. B. `wp-rocket`). Liefert
  `from_version` + `to_version` + Upgrader-Messages zurück.

Außerdem wird das Feld `calluna_seo` an `/wp-json/wp/v2/posts` registriert,
sodass es ohne Plugin-Pfad gelesen und geschrieben werden kann.

== Changelog ==

= 0.8.6 =
* Entfernt: Calluna-Index-Connector (`lib/index-connector.php`) — Feedback-Overlay + `reise_feedback`-CPT + `reise/v1`-REST-Bridge (Feedback/Site/Brand/Categories) + „Calluna Index"-Token-Settings. Feature ersatzlos gestrichen.

= 0.8.5 =
* Neuer Endpoint `POST /calluna/v1/maintenance/critical-css/regenerate` — löst WP-Rocket Critical-CSS-Neugenerierung aus (clean + rocket_generate_critical_css). Fällt auf action hook zurück wenn Funktion nicht existiert; liefert 400 wenn WP-Rocket nicht aktiv.
* Neuer Endpoint `GET /calluna/v1/maintenance/pages` — Kuratierte URL-Liste für den Monitor-Health-Fanout: `/` + alle Pages (menu_order) + Zufalls-Sample Posts. Default 12, max 20.

= 0.8.4 =
* `/info` `i18n`-Block liefert jetzt eine generische `languages`-Liste (Polylang ODER WPML-Sprachcodes).
* Neuer Endpoint `GET /calluna/v1/i18n/overview` — Übersetzungs-Library: pro Quell-Post die vorhandenen Sprachversionen (WPML via trid, Polylang via translations), paginiert. Basis für die Translate-Library.

= 0.8.3 =
* Calluna-Logo (Wortmarke, Brand-Purple) im Kopf der Companion-Einstellungsseite.

= 0.8.2 =
* `POST /calluna/v1/i18n/link` — same-site-Verlinkung plugin-bewusst: setzt je Post die Sprache und hängt die Posts als Übersetzungsgruppe zusammen. Polylang (`pll_set_post_language` + `pll_save_post_translations`) UND WPML (`wpml_set_element_language_details` + gemeinsame `trid`). Body: `{ source_lang, posts: [{lang, post_id}] }`.

= 0.8.1 =
* `/info` liefert jetzt einen `i18n`-Block: erkennt Polylang (free/pro), WPML, TranslatePress und Weglot, plus `active` (primär erkanntes Plugin) und `polylang_languages`. Translate nutzt das, um zu erkennen, auf welchem Mehrsprachigkeits-Plugin es aufsetzen kann.

= 0.8.0 =
* Translate-Modul: `POST /calluna/v1/hreflang/{postId}` speichert den von der Content-Pipe gepushten, reziproken Alternates-Satz als Post-Meta.
* `wp_head` gibt daraus `<link rel="alternate" hreflang="…">`-Tags inkl. `x-default` aus — funktioniert auch Cross-Domain (getrennte WP-Installationen), da die Pipe Source of Truth ist.
* Shortcode `[calluna_language_switcher]` rendert einen sichtbaren Sprachumschalter aus demselben Meta (topologie-agnostisch, ab 2 Sprachen).

= 0.6.0 =
* Detects Raidboxes hosting via multiple signals (constants, plugin slugs, hostname pattern, filesystem markers).
* Purges Raidboxes server-side Varnish/Nginx cache via action hook, known function names, HTTP PURGE, and HTTP BAN as fallback. Best-effort, fail-soft.
* `/maintenance/cache/clear` now includes a `raidboxes` field in the response (null if not Raidboxes-hosted, `{ok, attempts}` otherwise).
* New dedicated endpoint `POST /maintenance/cache/raidboxes` for targeted Raidboxes-only purge.
* `/maintenance/health` now reports `raidboxes` in the `caches_available` map.

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
