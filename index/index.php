<?php
/**
 * Calluna Index — Lader.
 *
 * Macht aus dem Companion das „Calluna Index Plugin": Seitenprofil aus
 * index.calluna.ai holen (Vertrag v1, calluna-index/docs/vertrag-seitenprofil.md),
 * Module ein-/ausschalten, dem Build alles über
 * GET /wp-json/calluna/v1/index/build-konfig geben.
 *
 * Grundsätze:
 *  - Der Companion-Kern (Content Pipe, Dashboard, SEO-Mapping, Token-Auth,
 *    Auto-Update) ist kein Modul und wird hier nicht angefasst.
 *  - Ohne Einrichtung (keine Site-ID/kein Token, kein lokaler Schalter) ist
 *    jedes Modul aus — bestehende Kundenseiten merken vom Update nichts.
 *  - Alle Seiteneffekte (Hooks) entstehen erst in calluna_index_starten(),
 *    also nur, wenn alle Dateien fehlerfrei geladen wurden.
 *  - PHP 7.4 kompatibel (Plugin-Header „Requires PHP: 7.4").
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CALLUNA_INDEX_VERTRAG')) {
    define('CALLUNA_INDEX_VERTRAG', 1);
}
if (!defined('CALLUNA_INDEX_URL_STANDARD')) {
    define('CALLUNA_INDEX_URL_STANDARD', 'https://index.calluna.ai');
}
if (!defined('CALLUNA_INDEX_HAUPTDATEI')) {
    define('CALLUNA_INDEX_HAUPTDATEI', dirname(__DIR__) . '/calluna-companion.php');
}

require_once __DIR__ . '/modul.php';
require_once __DIR__ . '/kern.php';
require_once __DIR__ . '/profil.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/admin.php';

require_once dirname(__DIR__) . '/module/consent.php';
require_once dirname(__DIR__) . '/module/headless.php';
require_once dirname(__DIR__) . '/module/seo.php';
require_once dirname(__DIR__) . '/module/weiterleitungen.php';
require_once dirname(__DIR__) . '/module/indexnow.php';

calluna_index_starten();

// Erst jetzt ist alles da; der Kern wählt danach die Einstellungsseite (sonst alte Token-Seite).
define('CALLUNA_INDEX_GELADEN', true);
