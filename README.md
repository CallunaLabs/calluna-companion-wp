# Calluna Companion

WordPress-Bridge für [Calluna Dashboard](https://dashboard.callunalabs.ai) + [Calluna Content Pipe](https://content-pipe.calluna.ai).

Ein einziges Plugin, drei Aufgaben:

1. **SEO-Bridge** — normalisiert Yoast / RankMath / AIOSEO Felder hinter einem einheitlichen REST-Endpoint.
2. **Content-Bridge** — flacher Posts-Endpoint inkl. Featured Image, Categories, Tags. Für die Content Pipe.
3. **Maintenance-Bridge** — Health-Snapshots, Plugin-Updates, Multi-Layer Cache-Clear (WP Rocket + Elementor + …). Für das Dashboard.

## Installation

### Empfohlen: über Calluna Dashboard

1. Im Dashboard auf [/websites/kunden/new](https://dashboard.callunalabs.ai/websites/kunden/new) → „Mit WordPress verbinden" klicken.
2. Auf der WP-Site einmal das Plugin manuell installieren (Plugins → Hinzufügen → Plugin hochladen → ZIP aus [Releases](https://github.com/callunaLabs/calluna-companion-wp/releases)) und aktivieren.
3. Folgende Updates kommen **automatisch** über den WordPress-Update-Mechanismus (Plugin Update Checker pollt GitHub-Releases alle 12 h).

### Manuell

```bash
# ZIP aus dem neuesten Release runterladen
wget https://github.com/callunaLabs/calluna-companion-wp/releases/latest/download/calluna-companion.zip
# In WordPress hochladen über Plugins → Hochladen
```

## Endpoints

Namespace `calluna/v1`. Auth: WordPress Application Passwords (HTTP Basic).

### Content (Cap: `edit_posts`)

| Endpoint | Method | Zweck |
|---|---|---|
| `/info` | GET | Erkannte SEO-Plugins, Capabilities, Versionen |
| `/posts` | GET | Posts inkl. SEO-Meta + Featured Image (flat) |
| `/posts/{id}` | GET/POST | Read + Update Post inkl. SEO + Featured Image URL |

Außerdem wird `calluna_seo` als Field an `/wp/v2/posts` registriert (rückwärtskompatibel).

### Calluna Index (Cap: `manage_options`)

| Endpoint | Method | Zweck |
|---|---|---|
| `/index/build-konfig` | GET | Seitenprofil-Revision, effektive Module, Consent-Konfiguration für den Frontend-Build (siehe unten) |

### Maintenance (Cap: `manage_options` bzw. `update_plugins`)

| Endpoint | Method | Cap | Zweck |
|---|---|---|---|
| `/maintenance/health` | GET | `manage_options` | WP-/PHP-Version, debug.log-Tail (max 8KB), Update-Counts, Cache-Provider-Detection |
| `/maintenance/plugins` | GET | `update_plugins` | Plugin-Inventory mit `update_available` + `new_version` |
| `/maintenance/cache/clear` | POST | `manage_options` | Multi-Layer-Flush: Core Object → WP Rocket → Elementor → W3TC → Super-Cache → Autoptimize → OPcache |
| `/maintenance/plugins/{slug}/update` | POST | `update_plugins` | `Plugin_Upgrader->upgrade()` mit Automatic_Upgrader_Skin |

## Calluna Index (Seitenprofil + Module)

Seit dem Branch `index-module` (unveröffentlicht, vorgeschlagen **0.10.0**) ist der Companion auch das **Calluna Index Plugin**: Er holt das Seitenprofil der Seite aus dem Calluna Index (Vertrag v1, `calluna-index/docs/vertrag-seitenprofil.md`), schaltet danach Module ein/aus und gibt dem Frontend-Build alles in einem Aufruf.

Ohne Einrichtung passiert nichts: alle Module sind aus, kein zusätzlicher Cron, keine zusätzlichen Anfragen. Der Companion-Kern (Content Pipe, Dashboard, SEO-Mapping, Token-Auth, Auto-Update) ist kein Modul und bleibt immer an.

### Einstellungen → Calluna Index

Gleiche Adresse wie bisher (`options-general.php?page=calluna-companion`), zwei Reiter:

- **Index & Module**: Site-ID (`[a-z0-9-]{2,40}`), Site-Token (Passwortfeld; angezeigt werden nur die letzten 4 Zeichen und ein SHA-256-Anfang), Index-URL (Standard `https://index.calluna.ai`; nur https, http nur für localhost/`*.test`), Knopf „Profil jetzt abrufen", Revision / Zeitpunkte / letzter Fehler, Profil als JSON, Modul-Tabelle mit Schalter und Einstellungen.
- **Companion-Token**: der bisherige `X-Calluna-Token` (unverändert).

Fest vorgeben (z. B. aus der `.env` eines Tenants) geht auch in `wp-config.php`:

```php
define('CALLUNA_INDEX_SITE_ID', 'paqato');
define('CALLUNA_INDEX_SITE_TOKEN', '…');
define('CALLUNA_INDEX_URL', 'https://index.calluna.ai');   // optional
```

**Profil-Abruf**: `GET {Index-URL}/api/v1/sites/{siteId}/profil` mit `Authorization: Bearer <Site-Token>` und `If-None-Match`, stündlich per WP-Cron (nur auf eingerichteten Seiten) und beim Speichern. Weiterleitungen werden nicht verfolgt (Token bleibt beim Index). Geprüft wird `vertrag === 1` und `site.id`. Fehler (401, 404, Weiterleitung/Cloudflare Access, kaputtes JSON, falscher Vertrag) stehen nur auf der Einstellungsseite und in `build-konfig.profilFehler`; das zuletzt gültige Profil bleibt aktiv.

Optionen: `calluna_index_einstellungen` (autoload, klein), `calluna_index_profil` (Profil als JSON-Text, kein autoload), `calluna_index_stand`, `calluna_index_site_token`, `calluna_index_geheim` (alle ohne autoload).

### Module

Zustand je Modul: **Vorgabe aus Index** (Standard) / **an** / **aus**. Effektiv gilt: lokaler Schalter, sonst `profil.module.<name>.aktiv` (nur echtes `true`), sonst aus. Leere Einstellungsfelder übernehmen den Wert aus `profil.module.<name>`, sonst den Standard.

| Modul | Stand | Zweck |
|---|---|---|
| `consent` | fertig | Keine Ausgabe im CMS. Liefert `profil.consent` unverändert in `build-konfig` (sonst `null`). Hinweis, wenn an, aber kein Profil. |
| `headless` | fertig | Generischer Ersatz für die Projekt-mu-plugins (paqato/calluna.ai): 302-Umleitung aufs Frontend, „Ansehen"-Links aufs Frontend, Build-Auslöser, Knopf „↻ Seite neu bauen", Build-Status. |
| `seo` | vorbereitet | Ersatz für Rank Math — Konzept: [`docs/seo-modul.md`](docs/seo-modul.md). |
| `weiterleitungen` | vorbereitet | Weiterleitungsregeln zentral. |
| `indexnow` | vorbereitet | Änderungen per IndexNow melden. |

Vorbereitete Module haben nur Einstellungen; sie bleiben auch bei „an" effektiv aus („kommt nach dem Livegang").

#### `headless` im Detail

| Einstellung | Profil-Schlüssel | Standard |
|---|---|---|
| Frontend-Adresse | `frontendUrl` | — (ohne gültige https-Adresse, oder bei gleichem Host wie das CMS, keine Umleitung) |
| Umleitung | — | an |
| Pfad-Feld (Meta-Schlüssel, z. B. `paqato_pfad`) | `pfadFeld` | — |
| Nicht umleiten (Pfad-Anfänge) | — | — |
| CMS nicht indexieren (nur solange umgeleitet wird) | — | an |
| Build-Hook | `buildHook` | — |
| Hook-Art: `webhook` / `github` / `datei` | `hookArt` | `webhook` |
| Hook-Token (Bearer, nur lokal, eigene Option) | — | — |
| GitHub-Ereignis | `githubEreignis` | `cms-publish` |
| Entprellung (Sekunden) | — | 30 |
| Inhaltstypen | — | alle öffentlichen außer Medien + `wp_block` + `wp_navigation` |
| Neu bauen bei neuer Profil-Revision | — | an |

- **Umleitung**: öffentliche CMS-Seiten → `302 {frontendUrl}{pfad}`. Nie umgeleitet: Vorschau (`?preview`, `?p=`, `?page_id=`), wp-admin, Login, REST, AJAX, Cron, XML-RPC, GraphQL, robots.txt. WordPress im Unterverzeichnis wird berücksichtigt.
- **Links**: `get_permalink()` zeigt im Admin und in der GET-Abfrage des Block-Editors (`/wp/v2/…?context=edit`) aufs Frontend (veröffentlichte Dokumente; Entwürfe und Vorschau bleiben im CMS). **Nicht** in `/calluna/v1/*` und nicht in Schreibantworten — die Content Pipe bekommt dieselben Links wie bisher.
- **Build**: ausgelöst bei Veröffentlichen/Aktualisieren/Zurückziehen/Löschen/geplantem Veröffentlichen, Begriffen öffentlicher Taxonomien, Menüs, Schreibzugriffen der Content Pipe auf veröffentlichte Beiträge (`/calluna/v1/posts|hreflang|terms`) und neuer Profil-Revision. Entprellt: der erste Auslöser baut am Ende der Anfrage, alle weiteren innerhalb der Sperrzeit ergeben genau einen Nachlauf (WP-Cron).
  - `webhook`: `POST` JSON `{quelle:"calluna-index", siteId, cms, grund, zeitpunkt}`, optional `Authorization: Bearer <Hook-Token>`; 2xx = ok. Liefert die Antwort JSON mit `stand`/`status`/`message`/`id`/`url`…, wird das als Build-Status angezeigt.
  - `github`: `POST https://api.github.com/repos/OWNER/REPO/dispatches` mit `{event_type, client_payload}`; Hook-Token Pflicht.
  - `datei`: schreibt `<Verzeichnis>/auftrag` (wie `paqato-ausspielen.php`), zeigt `<Verzeichnis>/stand.txt` als „Letzter Bau".
- **Verträglich mit den mu-plugins**: Ist `paqato-headless.php` bzw. `calluna-frontend.php` aktiv, bleibt die Umleitung des Moduls aus; ist `paqato-ausspielen.php` bzw. `calluna-ausspielen.php` aktiv, bleibt der Build-Auslöser aus. Die Einstellungsseite sagt es. Eigene Projekte können das über die Filter `calluna_index_headless_fremde_umleitung` / `calluna_index_headless_fremder_bau` melden.

Filter/Aktionen: `calluna_index_module` (eigene Module), `calluna_index_headless_umleiten`, `calluna_index_headless_ziel`, `calluna_index_headless_inhaltstypen`, `calluna_index_profil_geaendert`, `calluna_index_bau_ausgeloest`.

### Für den Build: `build-konfig`

```
GET /wp-json/calluna/v1/index/build-konfig[?aktualisieren=1]
Auth: Anwendungspasswort eines Nutzers mit manage_options  ODER  X-Calluna-Token
```

```jsonc
{
  "vertrag": 1,
  "profilRevision": 7,                       // null ohne Profil
  "module": {
    "headless":        { "aktiv": true,  "quelle": "index", "frontendUrl": "https://www.paqato.com" },
    "consent":         { "aktiv": true,  "quelle": "index" },
    "seo":             { "aktiv": false, "quelle": "index", "vorbereitet": true },
    "weiterleitungen": { "aktiv": false, "quelle": "standard", "vorbereitet": true },
    "indexnow":        { "aktiv": false, "quelle": "lokal", "vorbereitet": true }
  },
  "consent": { /* profil.consent unverändert, null wenn Modul consent aus */ },
  // zusätzlich (nicht im Vertrag v1, rein additiv):
  "siteId": "paqato", "profilAbgerufen": "2026-11-06T10:00:00+00:00", "profilFehler": null,
  "plugin": { "name": "calluna-companion", "version": "0.9.0" }
}
```

`quelle`: `lokal` (Schalter an/aus), `index` (Profil), `standard` (kein Profilwert → aus). Geheime Werte (Build-Hook, Tokens) stehen nie darin. `?aktualisieren=1` fragt vorher das Profil beim Index nach (mit ETag). Antwort mit `Cache-Control: no-store`. Kein neuer REST-Namespace — die Plugin-Erkennung der Content Pipe ändert sich nicht.

## Auto-Update

Powered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5.7.

Beim Aktivieren registriert sich das Plugin als Update-Quelle für sich selbst:
- WP-Cron prüft alle 12 h `api.github.com/repos/callunaLabs/calluna-companion-wp/releases/latest`
- Bei neuem `tag_name` (z. B. `v0.4.1`) erscheint das Update in **WP-Admin → Plugins** mit „Update verfügbar"-Hinweis
- 1-Click-Update via WP-Standard-UI **oder** Remote-Trigger via `POST /maintenance/plugins/calluna-companion/update` aus dem Dashboard

## Release-Workflow (Maintainer)

```bash
# 1. Version bumpen (Plugin-Header + readme.txt + Konstante)
# 2. Commit + Tag
git commit -am "release: v0.4.1"
git tag -a v0.4.1 -m "v0.4.1"
git push --follow-tags

# 3. ZIP bauen (Top-Level-Folder muss "calluna-companion/" heißen!)
cd ..
zip -r calluna-companion.zip calluna-companion-wp \
  -x "*.git*" "*.DS_Store" "*.github/*"
# Oder besser: Top-Level umbenennen vor ZIP, damit WP-Upload nicht klagt
mkdir -p _build/calluna-companion
cp -R calluna-companion-wp/* _build/calluna-companion/
(cd _build && zip -r ../calluna-companion.zip calluna-companion -x "*.git*")

# 4. GitHub-Release erstellen
gh release create v0.4.1 calluna-companion.zip \
  --title "v0.4.1" --notes "Changelog siehe readme.txt"
```

Wichtig: Das ZIP muss **`index/` und `module/`** enthalten (sonst lädt das Plugin fail-soft ohne Calluna Index, der Kern läuft weiter). Und: **das ZIP muss einen Top-Level-Folder `calluna-companion/` enthalten** (nicht `calluna-companion-wp/` oder `calluna-companion-0.4.1/`), sonst legt WordPress beim Update einen neuen Plugin-Ordner an statt den alten zu überschreiben — und das Plugin ist dann doppelt installiert + die Hooks brechen.

## Sicherheit

- Alle Endpoints verlangen WordPress-Application-Password (Basic Auth)
- Posts-Endpoints: `edit_posts`
- Maintenance-Endpoints: `manage_options` (Health, Cache) bzw. `update_plugins` (Plugin-Inventory + Updates)
- `cache/clear` ist bewusst **nicht** auf `edit_posts` heruntergesetzt — kein Redakteur soll versehentlich/böswillig Cache flushen

## Lizenz

GPL-2.0+ (gleiche Lizenz wie WordPress Core und Plugin Update Checker).

## Repo

- Source: https://github.com/callunaLabs/calluna-companion-wp
- Issues: https://github.com/callunaLabs/calluna-companion-wp/issues
- Releases: https://github.com/callunaLabs/calluna-companion-wp/releases
