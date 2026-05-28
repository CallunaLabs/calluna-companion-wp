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

Wichtig: **das ZIP muss einen Top-Level-Folder `calluna-companion/` enthalten** (nicht `calluna-companion-wp/` oder `calluna-companion-0.4.1/`), sonst legt WordPress beim Update einen neuen Plugin-Ordner an statt den alten zu überschreiben — und das Plugin ist dann doppelt installiert + die Hooks brechen.

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
