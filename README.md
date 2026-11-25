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

### Maintenance (Cap: `manage_options` bzw. `update_plugins`)

| Endpoint | Method | Cap | Zweck |
|---|---|---|---|
| `/maintenance/health` | GET | `manage_options` | WP-/PHP-Version, debug.log-Tail (max 8KB), Update-Counts, Cache-Provider-Detection |
| `/maintenance/plugins` | GET | `update_plugins` | Plugin-Inventory mit `update_available` + `new_version` |
| `/maintenance/cache/clear` | POST | `manage_options` | Multi-Layer-Flush: Core Object → WP Rocket → Elementor → W3TC → Super-Cache → Autoptimize → OPcache |
| `/maintenance/plugins/{slug}/update` | POST | `update_plugins` | `Plugin_Upgrader->upgrade()` mit Automatic_Upgrader_Skin |

## Module (Einstellungen → Calluna Companion → Module)

Seit 0.10.0 gibt es ein Modul: **headless** (Headless-Vorschau und Build-Auslöser). Es ist standardmäßig aus; der Companion-Kern (Content Pipe, Dashboard, SEO-Mapping, Token-Auth, Auto-Update) ist kein Modul und bleibt immer an.

Gespeichert wird in den Optionen `calluna_index_einstellungen` (Schalter und Werte) und `calluna_index_geheim` (geheime Felder, kein autoload). Die Namen stammen aus der Vorversion und bleiben, damit bestehende Einstellungen weiter gelten.

Geparkt auf dem Branch `modul-vorschau` (nicht im Release): Seitenprofil aus index.calluna.ai, `GET /calluna/v1/index/build-konfig`, Module `consent`, `sprachen`, `seo`, `weiterleitungen`, `indexnow`.

### `headless` im Detail

| Einstellung | Standard |
|---|---|
| Frontend-Adresse | — (ohne gültige https-Adresse, oder bei gleichem Host wie das CMS, keine Umleitung) |
| CMS-Seiten aufs Frontend umleiten | aus |
| Pfad-Feld (Meta-Schlüssel, z. B. `paqato_pfad`) | — |
| Nicht umleiten (Pfad-Anfänge) | — |
| CMS für Suchmaschinen sperren (nur solange umgeleitet wird) | aus |
| Build-Hook | — |
| Hook-Art: `webhook` / `github` / `datei` | `webhook` |
| Hook-Token (Bearer, nur lokal, eigene Option) | — |
| GitHub-Ereignis | `cms-publish` |
| Entprellung (Sekunden) | 30 |
| Inhaltstypen | alle öffentlichen außer Medien + `wp_block` + `wp_navigation` |
| Vorschau im Frontend-Design: `aus` / `build` / `ssr` | `aus` |

- **Umleitung**: öffentliche CMS-Seiten → `302 {frontendUrl}{pfad}`. Nie umgeleitet: Vorschau (`?preview`, `?p=`, `?page_id=`), wp-admin, Login, REST, AJAX, Cron, XML-RPC, GraphQL, robots.txt. WordPress im Unterverzeichnis wird berücksichtigt.
- **Links**: `get_permalink()` zeigt im Admin und in der GET-Abfrage des Block-Editors (`/wp/v2/…?context=edit`) aufs Frontend (veröffentlichte Dokumente; Entwürfe und Vorschau bleiben im CMS). **Nicht** in `/calluna/v1/*` und nicht in Schreibantworten — die Content Pipe bekommt dieselben Links wie bisher.
- **Build**: ausgelöst bei Veröffentlichen/Aktualisieren/Zurückziehen/Löschen/geplantem Veröffentlichen, Begriffen öffentlicher Taxonomien, Menüs, Schreibzugriffen der Content Pipe auf veröffentlichte Beiträge (`/calluna/v1/posts|hreflang|terms`) Entprellt: der erste Auslöser baut am Ende der Anfrage, alle weiteren innerhalb der Sperrzeit ergeben genau einen Nachlauf (WP-Cron).
  - `webhook`: `POST` JSON `{quelle:"calluna-index", siteId, cms, grund, zeitpunkt}`, optional `Authorization: Bearer <Hook-Token>`; 2xx = ok. Liefert die Antwort JSON mit `stand`/`status`/`message`/`id`/`url`…, wird das als Build-Status angezeigt.
  - `github`: `POST https://api.github.com/repos/OWNER/REPO/dispatches` mit `{event_type, client_payload}`; Hook-Token Pflicht.
  - `datei`: schreibt `<Verzeichnis>/auftrag` (wie `paqato-ausspielen.php`), zeigt `<Verzeichnis>/stand.txt` als „Letzter Bau".
- **Vorschau im Frontend-Design** (Einstellung „Vorschau", Standard **aus** — ohne Schalter ändert sich nichts; für Seiten mit eigenem Theme nicht nötig): Der Knopf „Vorschau" im Editor zeigt Entwürfe und ungespeicherte Änderungen so, wie sie auf dem Frontend aussehen. Läuft unabhängig davon, ob Umleitung/Build hier oder in einem Projekt-mu-plugin stecken. Vorschau-Links zeigen dafür immer ins CMS (`?p=…&preview=true`), dort fängt das Modul sie ab (nur mit `edit_post`-Recht; `&calluna_wp_vorschau=1` zeigt die WordPress-Vorschau).
  - `build` (Vorschau-Build): Auftrag mit Code (32 Hex, 30 Minuten gültig). Hook-Art „Auftragsdatei": `<Build-Hook-Verzeichnis>/vorschau/<code>.json` = `{vertrag:1, art:"vorschau", code, id, typ, status, pfad, url, cms, siteId, erstellt, gueltigBis, dokument}` (`dokument` wie `/wp/v2/<typ>/<id>?context=view`, Titel/Inhalt/Auszug aus der Autospeicherung, Filter `calluna_index_vorschau_dokument($doc, $post, $autosave)` für eigene REST-Felder). Das Frontend schreibt daneben `<code>.fertig` oder `<code>.fehler` (Text = Meldung). Webhook: nur mit eigenem **Vorschau-Hook** (nie der Deploy-Hook), POST derselben Felder ohne `dokument`, dafür `abruf` = `GET /calluna/v1/vorschau/<code>` (der Code ist der Zugang); fertig ist die Vorschau, sobald die Vorschau-Adresse 200 liefert. GitHub-Dispatch gibt es für die Vorschau bewusst nicht (würde mit dem Deploy-Lauf konkurrieren). Die Warteseite im CMS fragt alle 2 s nach und leitet weiter; nach 3 Minuten gibt sie auf.
  - `ssr` (SSR-Vorschau): Weiterleitung auf `{frontend}/preview/?id={id}&exp={exp}&token={token}` mit `token = hash_hmac('sha256', "id.exp", Vorschau-Geheimnis)`, 15 Minuten gültig — das Format der calluna.ai-Route `src/pages/preview.astro`.
  - Vorschau-Adresse frei einstellbar (Platzhalter `{frontend}`, `{code}`, `{id}`, `{exp}`, `{token}`); Standard `{frontend}/_vorschau/{code}/` bzw. die SSR-Form oben. Das Frontend sorgt für noindex/no-store unter dieser Adresse.
- **Verträglich mit den mu-plugins**: Ist `paqato-headless.php` bzw. `calluna-frontend.php` aktiv, bleibt die Umleitung des Moduls aus; ist `paqato-ausspielen.php` bzw. `calluna-ausspielen.php` aktiv, bleibt der Build-Auslöser aus. Die Einstellungsseite sagt es. Eigene Projekte können das über die Filter `calluna_index_headless_fremde_umleitung` / `calluna_index_headless_fremder_bau` melden.

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

Wichtig: Das ZIP muss **`index/` und `module/`** enthalten (sonst lädt das Plugin fail-soft ohne Module, der Kern läuft weiter). Das Release-Asset heißt `calluna-companion.zip` (ohne Version), damit `releases/latest/download/calluna-companion.zip` funktioniert. Und: **das ZIP muss einen Top-Level-Folder `calluna-companion/` enthalten** (nicht `calluna-companion-wp/` oder `calluna-companion-0.4.1/`), sonst legt WordPress beim Update einen neuen Plugin-Ordner an statt den alten zu überschreiben — und das Plugin ist dann doppelt installiert + die Hooks brechen.

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
