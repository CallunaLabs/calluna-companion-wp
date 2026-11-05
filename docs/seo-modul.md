# Konzept: Modul `seo` (Ersatz für Rank Math)

Stand 06.11.2026 · Status: **vorbereitet** (Gerüst in `module/seo.php`, ohne Funktion). Umsetzung nach dem paqato-Livegang.

## Ziel

Headless-Seiten (paqato, calluna.ai, später weitere) brauchen Rank Math nur noch als Eingabemaske: ausgegeben wird der Kopf vom Astro-Build, nicht von WordPress. Rank Math bringt dafür viel Gewicht mit (Admin-UI, Sitemaps, Schema, Weiterleitungen, Telemetrie, Lizenz-Upsell) und liefert seine Felder per REST nicht von selbst aus (paqato registriert sie deshalb in `paqato-headless.php` von Hand).

Das Modul `seo` ersetzt die Maske durch eine eigene, schlanke, speichert in den Feldern, die der Companion-Kern **heute schon** als „ohne SEO-Plugin“-Ziel nutzt (`_calluna_seo_*`), und gibt dem Frontend einen fertig aufgelösten SEO-Kopf.

Nicht-Ziele (erste Ausbaustufe): Sitemaps (macht der Build), Weiterleitungen (eigenes Modul `weiterleitungen`), Schema-Generator (JSON-LD kommt aus Pipe/Build), Inhaltsanalyse/Lesbarkeit.

## 1. Editor-Seitenleiste (Block-Editor)

- `registerPlugin('calluna-seo', …)` mit `PluginSidebar` + `PluginDocumentSettingPanel` (Kurzfassung im Dokument-Reiter), Skript ohne Build-Kette (`wp.element`, `wp.components`, `wp.data`), liegt in `module/seo/editor.js`.
- Felder: **Titel** (mit Zeichen-/Pixelzähler, Platzhalter aus Titel-Muster), **Beschreibung** (Zähler 120–160), **Fokus-Keyword**, **Robots** (noindex, nofollow, noarchive, nosnippet, noimageindex, max-snippet etc.), **Canonical** (leer = selbst), **Open Graph** (Titel, Beschreibung, Bild aus Mediathek), **Twitter/X** (Titel, Beschreibung, Bild, Karte `summary_large_image`/`summary`; „wie Open Graph“ als Standard).
- Snippet-Vorschau (Google, Social-Karte) mit der Frontend-Adresse aus dem Headless-Modul.
- Speicherung über `register_post_meta(…, ['show_in_rest' => true, 'auth_callback' => edit_post])` → der Editor speichert mit dem Beitrag, kein eigener Endpunkt.
- Klassischer Editor: dieselben Felder als Meta-Box (Fallback, z. B. für Elementor-Seiten).

## 2. Term-SEO

- Kategorien, Schlagwörter und öffentliche eigene Taxonomien: Felder auf der Bearbeiten-Seite des Begriffs (`{taxonomy}_edit_form_fields`), gespeichert als **Term-Meta mit denselben Schlüsseln** (`_calluna_seo_*`), `register_term_meta` mit `show_in_rest`.
- Kern-Anpassung (additiv): `calluna_companion_write_term_seo()` hat heute keinen Zweig für „kein SEO-Plugin“ — schreibt dann gar nichts. Bei aktivem Modul schreibt sie nach `_calluna_seo_*` (Term-Meta). Bestehende Zweige (Rank Math/Yoast/AIOSEO) bleiben unverändert.

## 3. Speicherung

| Feld | Post-/Term-Meta | heute schon vom Kern genutzt |
|---|---|---|
| Titel | `_calluna_seo_title` | ja (`write_seo`/`read_seo`, Plugin „none“) |
| Beschreibung | `_calluna_seo_description` | ja |
| Fokus-Keyword | `_calluna_seo_focus_keyword` | ja |
| Robots | `_calluna_seo_robots` (Array von Direktiven) | ja |
| Canonical | `_calluna_seo_canonical` | neu |
| OG Titel / Beschreibung | `_calluna_seo_og_title`, `_calluna_seo_og_description` | neu |
| OG Bild | `_calluna_seo_og_image_id` (Anhang-ID), `_calluna_seo_og_image` (URL, Fallback für externe Bilder) | neu |
| Twitter | `_calluna_seo_twitter_title`, `_calluna_seo_twitter_description`, `_calluna_seo_twitter_image_id`, `_calluna_seo_twitter_card` | neu |

Seitenweite Standards (Titel-Muster, Trennzeichen, Standard-Bild, Archiv-Robots) stehen in den Modul-Einstellungen bzw. im Seitenprofil (`profil.module.seo.*`) und gehen per `build-konfig` an den Build.

Wichtig: Der Kern entscheidet das Mapping über `calluna_companion_detect_seo_plugin()`. Solange Rank Math/Yoast/AIOSEO aktiv ist, schreibt die Content Pipe in **deren** Felder. Das Modul darf deshalb erst **nach** Abschalten des Fremd-Plugins wirksam werden (Modul sperrt sich mit `blockiert()`, solange eines erkannt wird) — sonst zwei Wahrheiten.

## 4. Migration (WP-CLI)

```
wp calluna seo migrieren [--von=auto|rank-math|yoast] [--typ=post,page,…] [--begriffe]
                         [--ueberschreiben] [--trockenlauf]
```

- `--von=auto`: Quelle je Dokument nach vorhandenen Schlüsseln. `--trockenlauf` zählt nur und zeigt Stichproben; ohne `--ueberschreiben` bleiben vorhandene `_calluna_seo_*`-Werte unangetastet. Läuft in Stapeln (`--stapel=200`), idempotent, protokolliert pro Dokument.
- Zuordnung Beiträge/Seiten:

| Rank Math | Yoast | → Calluna |
|---|---|---|
| `rank_math_title` | `_yoast_wpseo_title` | `_calluna_seo_title` |
| `rank_math_description` | `_yoast_wpseo_metadesc` | `_calluna_seo_description` |
| `rank_math_focus_keyword` (kommagetrennt, erstes) | `_yoast_wpseo_focuskw` | `_calluna_seo_focus_keyword` |
| `rank_math_robots` (Array) | `_yoast_wpseo_meta-robots-noindex` = `1`, `…-nofollow` = `1`, `…-adv` | `_calluna_seo_robots` |
| `rank_math_canonical_url` | `_yoast_wpseo_canonical` | `_calluna_seo_canonical` |
| `rank_math_facebook_title/_description/_image(_id)` | `_yoast_wpseo_opengraph-title/-description/-image(-id)` | `_calluna_seo_og_*` |
| `rank_math_twitter_title/_description/_image(_id)`, `rank_math_twitter_use_facebook` | `_yoast_wpseo_twitter-title/-description/-image(-id)` | `_calluna_seo_twitter_*` |

- Begriffe: Rank Math speichert als Term-Meta (`rank_math_*`), **Yoast nicht** — Yoast legt Begriffs-SEO in der Option `wpseo_taxonomy_meta[taxonomy][term_id]` (`wpseo_title`, `wpseo_desc`, `wpseo_noindex` …) ab. Die Migration liest dort.
- Platzhalter: Rank Math `%title% %sep% %sitename%`, Yoast `%%title%% %%sep%% %%sitename%%` → eigene (`%titel%`, `%trenner%`, `%seite%`); bekannte werden umgeschrieben, unbekannte gemeldet (nicht still aufgelöst, damit sich der Titel später mit dem Muster ändert).
- Danach: Rank Math deaktivieren (nicht löschen — Rückweg), Modul `seo` an, Stichprobe im Frontend.
- Auffällig beim Lesen des Kerns: `calluna_companion_write_term_seo()` schreibt für Yoast Term-Meta `wpseo_title`/`wpseo_desc`/`wpseo_noindex`, Yoast liest Begriffs-SEO aber aus `wpseo_taxonomy_meta` (s. o.). Bitte separat prüfen — hier bewusst nicht geändert.

## 5. REST-Ausgabe fürs Frontend

- Neues Feld **`calluna_seo_kopf`** (nur lesen) an Beiträgen, Seiten, öffentlichen eigenen Inhaltstypen und Begriffen, fertig aufgelöst: `title` (Muster angewandt), `description`, `robots` (String und Liste), `canonical` (absolute **Frontend**-URL, aus Headless-`frontendUrl` + Pfad/Pfad-Feld), `og` {`title`,`description`,`image`:{`url`,`width`,`height`,`alt`},`type`,`url`}, `twitter` {…}, `fokusKeyword` nur mit `context=edit`.
- Das bestehende Feld `calluna_seo` (Content Pipe, Schreib-Bestätigung) bleibt **unverändert** in Form und Bedeutung.
- Seitenweite Standards in `build-konfig` → `module.seo` (`titelMuster`, `trenner`, `ogBild`, `archiveNoindex`).
- Für paqato: der Build liest heute `rank_math_*` direkt aus `meta` (Registrierung in `paqato-headless.php`). Umstellung im Astro-Build auf `calluna_seo_kopf` **vor** dem Abschalten von Rank Math; die mu-plugin-Registrierung kann danach entfallen.

## 6. Folgen für die Content Pipe

Die Pipe erkennt Plugins über die REST-Namespaces der Site (`scanPlugins()` in `src/lib/cms/wordpress.ts`):

- `hasRankMath = namespaces.some(n => n.startsWith("rank-math/"))`, `hasYoast = namespaces.includes("yoast/v1")`, `hasCallunaCompanion = namespaces.includes("calluna/v1")`.
- Nach dem Umstieg ist `hasRankMath`/`hasYoast` **false**. Das ist für das Schreiben richtig: Die Pipe schreibt SEO über `/calluna/v1/posts/{id}` bzw. `calluna_seo`, der Kern mappt dann automatisch auf `_calluna_seo_*` (`seo_plugin: "none"` in `/info`).

Anzupassen in der Pipe (vor dem ersten Umstieg):

1. **Erkennung**: Das Modul registriert **keinen** eigenen Namespace (bleibt unter `calluna/v1`). `/info` bekommt additiv `features: [..., "seo-modul"]` und ein Objekt `seo_modul: { aktiv, felder: [...] }`; `seo_plugin` bleibt `"none"` (keine Bedeutungsänderung für bestehende Leser). Pipe: neues Flag `hasCallunaSeo` aus `/info` statt aus Namespaces.
2. **`seoPluginSchema.ts`**: `seoPlugin` ist heute `yoast | rank_math | null`; bei `null` geht die Pipe von „kein SEO-Plugin“ aus und gibt ihr eigenes Artikel-Schema aus (seit Companion 0.9.0 im `<head>`). Für Headless egal (Kopf macht der Build), für klassische Seiten mit Modul `seo` muss entschieden werden, wer Artikel-Schema ausgibt — Vorschlag: Pipe wie bei „kein Plugin“, Modul gibt kein eigenes Artikel-Schema aus.
3. **Editor-UI** (`ArticleEditor.tsx`): Anzeige „Calluna SEO erkannt“ analog „RankMath erkannt“; Felder Canonical/OG/Twitter nur anbieten, wenn `hasCallunaSeo` oder Rank Math/Yoast.
4. **`writePostSeo`**: Payload um `canonical`, `og_*`, `twitter_*` erweitern; der Kern nimmt sie (additiv) im Zweig „none“ an. Unbekannte Schlüssel ignoriert der Kern schon heute — alte Pipe-Versionen brechen nicht.
5. **Term-SEO** (`/calluna/v1/terms/{tax}/{id}/seo`): funktioniert nach Kern-Anpassung aus 2. ohne Pipe-Änderung.

## 7. Reihenfolge / Risiken

1. Modul fertig bauen, standardmäßig aus; Tests gegen Wegwerf-WordPress mit Rank-Math- und Yoast-Daten.
2. Pipe-Anpassungen 1.–4. ausrollen (abwärtskompatibel, wirken nur bei `hasCallunaSeo`).
3. paqato-Staging: `wp calluna seo migrieren --trockenlauf`, dann echt; Astro auf `calluna_seo_kopf`; Rank Math aus; Vergleich der `<head>`-Ausgabe alt/neu für alle Seiten (Skript).
4. Danach calluna.ai, dann klassische Kundenseiten nur auf Wunsch.

Risiken: verlorene Rank-Math-Funktionen (Weiterleitungen, 404-Monitor, Sitemaps, lokale SEO-Schemas) vorher inventarisieren; Rank-Math-Platzhalter in Titeln; Begriffs-SEO bei Yoast (Option statt Term-Meta).
