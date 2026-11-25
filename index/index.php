<?php
/**
 * Calluna Companion — Module (Lader).
 *
 * Seit 0.10.0 enthält das Release nur das Modul headless (Vorschau im
 * Frontend-Design, Build-Auslöser). Seitenprofil, build-konfig und die Module
 * consent, sprachen, seo, weiterleitungen, indexnow sind auf dem Branch
 * modul-vorschau geparkt. Funktions- und Optionsnamen (calluna_index_*) bleiben,
 * damit bestehende Einstellungen und Filter weiter gelten.
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
require_once __DIR__ . '/admin.php';

require_once dirname(__DIR__) . '/module/headless.php';

calluna_index_starten();

// Erst jetzt ist alles da; der Kern wählt danach die Einstellungsseite (sonst alte Token-Seite).
define('CALLUNA_INDEX_GELADEN', true);
