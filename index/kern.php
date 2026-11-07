<?php
/**
 * Calluna Index — Kern: Einstellungen, Modul-Register, effektiver Zustand, Start.
 *
 * Optionen:
 *   calluna_index_einstellungen  (autoload) Site-ID, Index-URL, lokale Modul-
 *                                Schalter/-Werte und eine Kopie von
 *                                profil.module (`vorgaben`) — klein, wird bei
 *                                jeder Anfrage gebraucht (Headless-Umleitung).
 *   calluna_index_site_token     (kein autoload) Site-Token für die Profil-API.
 *   calluna_index_geheim         (kein autoload) geheime Modulfelder.
 *   calluna_index_profil         (kein autoload) das Profil als JSON-Text, wie geliefert.
 *   calluna_index_stand          (kein autoload) ETag, Zeitpunkte, letzter Fehler.
 *
 * wp-config.php kann Site-ID, Token und Index-URL fest vorgeben:
 *   CALLUNA_INDEX_SITE_ID, CALLUNA_INDEX_SITE_TOKEN, CALLUNA_INDEX_URL
 */

if (!defined('ABSPATH')) {
    exit;
}

const CALLUNA_INDEX_OPTION        = 'calluna_index_einstellungen';
const CALLUNA_INDEX_TOKEN_OPTION  = 'calluna_index_site_token';
const CALLUNA_INDEX_GEHEIM_OPTION = 'calluna_index_geheim';
const CALLUNA_INDEX_PROFIL_CRON   = 'calluna_index_profil_abruf';

/* ---------------------------------------------------------------- Einstellungen */

function calluna_index_einstellungen_standard(): array {
    return [
        'site_id'       => '',
        'index_url'     => '',   // '' = CALLUNA_INDEX_URL_STANDARD
        'module'        => [],   // kennung => ['zustand' => 'vorgabe'|'an'|'aus', 'werte' => [feld => wert]]
        'vorgaben'      => [],   // Kopie von profil.module (bereinigt)
        'zuletzt_aktiv' => [],   // kennung => bool, für aktivieren()/deaktivieren()
    ];
}

function calluna_index_einstellungen(): array {
    $gespeichert = get_option(CALLUNA_INDEX_OPTION, null);
    if (!is_array($gespeichert)) {
        $gespeichert = [];
    }
    $e = $gespeichert + calluna_index_einstellungen_standard();
    foreach (['module', 'vorgaben', 'zuletzt_aktiv'] as $liste) {
        if (!is_array($e[$liste])) {
            $e[$liste] = [];
        }
    }
    return $e;
}

function calluna_index_einstellungen_speichern(array $e): void {
    update_option(CALLUNA_INDEX_OPTION, $e, true);
}

function calluna_index_site_id(): string {
    if (defined('CALLUNA_INDEX_SITE_ID')) {
        return strtolower(trim((string) CALLUNA_INDEX_SITE_ID));
    }
    $e = calluna_index_einstellungen();
    return (string) $e['site_id'];
}

function calluna_index_site_token(): string {
    if (defined('CALLUNA_INDEX_SITE_TOKEN')) {
        return trim((string) CALLUNA_INDEX_SITE_TOKEN);
    }
    $t = get_option(CALLUNA_INDEX_TOKEN_OPTION, '');
    return is_string($t) ? $t : '';
}

function calluna_index_url(): string {
    if (defined('CALLUNA_INDEX_URL')) {
        $u = calluna_index_url_pruefen((string) CALLUNA_INDEX_URL);
        return $u !== '' ? $u : CALLUNA_INDEX_URL_STANDARD;
    }
    $e = calluna_index_einstellungen();
    $u = (string) $e['index_url'];
    return $u !== '' ? $u : CALLUNA_INDEX_URL_STANDARD;
}

function calluna_index_site_id_gueltig(string $id): bool {
    return (bool) preg_match('/^[a-z0-9-]{2,40}$/', $id);
}

function calluna_index_eingerichtet(): bool {
    return calluna_index_site_id_gueltig(calluna_index_site_id()) && calluna_index_site_token() !== '';
}

/** Hosts, für die http:// erlaubt ist (lokale Tests). Sonst nur https://. */
function calluna_index_lokaler_host(string $host): bool {
    $host = strtolower(trim($host, '[]'));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || (bool) preg_match('/\.(test|local|localhost)$/', $host);
}

/** Prüft eine Index-/Frontend-URL. Liefert sie ohne Schlussstrich oder '' wenn ungültig. */
function calluna_index_url_pruefen(string $url): string {
    $url = trim($url);
    if ($url === '' || preg_match('/\s/', $url)) {
        return '';
    }
    $teile = wp_parse_url($url);
    if (!is_array($teile) || empty($teile['scheme']) || empty($teile['host'])) {
        return '';
    }
    $schema = strtolower($teile['scheme']);
    if ($schema !== 'https' && !($schema === 'http' && calluna_index_lokaler_host($teile['host']))) {
        return '';
    }
    $sauber = esc_url_raw($url, ['http', 'https']);
    return $sauber === '' ? '' : untrailingslashit($sauber);
}

/* ---------------------------------------------------------------- Module */

/** @return Calluna_Index_Modul[] kennung => Modul */
function calluna_index_module(): array {
    static $module = null;
    if ($module !== null) {
        return $module;
    }
    $module = [];
    $klassen = [
        'Calluna_Index_Modul_Consent',
        'Calluna_Index_Modul_Headless',
        'Calluna_Index_Modul_Seo',
        'Calluna_Index_Modul_Weiterleitungen',
        'Calluna_Index_Modul_Indexnow',
        'Calluna_Index_Modul_Sprachen',
    ];
    foreach ($klassen as $klasse) {
        if (class_exists($klasse)) {
            $m = new $klasse();
            $module[$m->kennung()] = $m;
        }
    }
    // Erweiterbar (z. B. projektspezifische Module als eigenes Plugin).
    $erweitert = apply_filters('calluna_index_module', $module);
    if (is_array($erweitert)) {
        foreach ($erweitert as $kennung => $m) {
            if ($m instanceof Calluna_Index_Modul && preg_match('/^[a-z0-9_-]{1,40}$/', (string) $kennung)) {
                $module[$kennung] = $m;
            }
        }
    }
    return $module;
}

function calluna_index_modul(string $kennung): ?Calluna_Index_Modul {
    $module = calluna_index_module();
    return isset($module[$kennung]) ? $module[$kennung] : null;
}

/**
 * Effektiver Zustand eines Moduls.
 *
 * @return array{aktiv: bool, quelle: string, lokal: string, vorgabe: ?bool, blockiert: ?string}
 *   quelle: 'lokal' (Schalter an/aus), 'index' (profil.module.<x>.aktiv) oder 'standard' (aus)
 */
function calluna_index_modul_zustand(string $kennung): array {
    $e = calluna_index_einstellungen();
    $lokal = isset($e['module'][$kennung]['zustand']) ? (string) $e['module'][$kennung]['zustand'] : 'vorgabe';
    if (!in_array($lokal, ['vorgabe', 'an', 'aus'], true)) {
        $lokal = 'vorgabe';
    }
    $vorgabe = null;
    if (isset($e['vorgaben'][$kennung]['aktiv']) && is_bool($e['vorgaben'][$kennung]['aktiv'])) {
        $vorgabe = $e['vorgaben'][$kennung]['aktiv'];
    }

    if ($lokal === 'an') {
        $aktiv = true;
        $quelle = 'lokal';
    } elseif ($lokal === 'aus') {
        $aktiv = false;
        $quelle = 'lokal';
    } elseif ($vorgabe !== null) {
        $aktiv = $vorgabe;
        $quelle = 'index';
    } else {
        $aktiv = false;
        $quelle = 'standard';
    }

    $blockiert = null;
    $modul = calluna_index_modul($kennung);
    if ($aktiv && $modul) {
        $blockiert = $modul->blockiert();
        if ($blockiert !== null) {
            $aktiv = false;
        }
    }

    return [
        'aktiv'     => $aktiv,
        'quelle'    => $quelle,
        'lokal'     => $lokal,
        'vorgabe'   => $vorgabe,
        'blockiert' => $blockiert,
    ];
}

/**
 * Effektiver Wert eines Modulfeldes: lokale Einstellung → profil.module.<x>.<profil-schlüssel> → Standard.
 * Geheime Felder kommen nur aus der lokalen Geheim-Option.
 */
function calluna_index_modul_wert(string $kennung, string $feld): string {
    $modul = calluna_index_modul($kennung);
    if (!$modul) {
        return '';
    }
    $felder = $modul->felder();
    if (!isset($felder[$feld])) {
        return '';
    }
    $def = $felder[$feld];

    if (!empty($def['geheim'])) {
        $geheim = get_option(CALLUNA_INDEX_GEHEIM_OPTION, []);
        $wert = is_array($geheim) && isset($geheim[$kennung][$feld]) ? (string) $geheim[$kennung][$feld] : '';
        return $wert !== '' ? $wert : (string) ($def['standard'] ?? '');
    }

    $e = calluna_index_einstellungen();
    if (isset($e['module'][$kennung]['werte'][$feld])) {
        $lokal = (string) $e['module'][$kennung]['werte'][$feld];
        if ($lokal !== '') {
            return $lokal;
        }
    }
    $vorgabe = calluna_index_modul_vorgabe($kennung, $feld);
    if ($vorgabe !== null) {
        return $vorgabe;
    }
    return (string) ($def['standard'] ?? '');
}

/** Wert eines Feldes aus dem Seitenprofil (null = Profil sagt nichts). */
function calluna_index_modul_vorgabe(string $kennung, string $feld): ?string {
    $modul = calluna_index_modul($kennung);
    if (!$modul) {
        return null;
    }
    $felder = $modul->felder();
    if (empty($felder[$feld]['profil']) || !empty($felder[$feld]['geheim'])) {
        return null;
    }
    $schluessel = (string) $felder[$feld]['profil'];
    $e = calluna_index_einstellungen();
    if (!isset($e['vorgaben'][$kennung][$schluessel])) {
        return null;
    }
    $v = $e['vorgaben'][$kennung][$schluessel];
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_scalar($v)) {
        $v = (string) $v;
        return $v === '' ? null : $v;
    }
    return null;
}

/**
 * Nach Speichern/Profil-Abruf: aktivieren()/deaktivieren() für Module, deren
 * effektiver Zustand gewechselt hat.
 */
function calluna_index_uebergaenge_pruefen(): void {
    $e = calluna_index_einstellungen();
    $vorher = $e['zuletzt_aktiv'];
    $jetzt = [];
    foreach (calluna_index_module() as $kennung => $modul) {
        $z = calluna_index_modul_zustand($kennung);
        $jetzt[$kennung] = $z['aktiv'];
        $war = !empty($vorher[$kennung]);
        try {
            if ($z['aktiv'] && !$war) {
                $modul->aktivieren();
            } elseif (!$z['aktiv'] && $war) {
                $modul->deaktivieren();
            }
        } catch (\Throwable $fehler) {
            calluna_index_protokoll('Modul ' . $kennung . ': ' . $fehler->getMessage());
        }
    }
    if ($jetzt !== $vorher) {
        $e = calluna_index_einstellungen();
        $e['zuletzt_aktiv'] = $jetzt;
        calluna_index_einstellungen_speichern($e);
    }
}

function calluna_index_protokoll(string $text): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Calluna Index] ' . $text);
    }
}

/* ---------------------------------------------------------------- Start */

function calluna_index_starten(): void {
    add_action('plugins_loaded', 'calluna_index_module_laden', 20);
    add_action('init', 'calluna_index_init');
    add_action(CALLUNA_INDEX_PROFIL_CRON, 'calluna_index_profil_cron');
    add_action('rest_api_init', 'calluna_index_rest_registrieren');
    if (is_admin()) {
        calluna_index_admin_starten();
    }
    register_deactivation_hook(CALLUNA_INDEX_HAUPTDATEI, 'calluna_index_deaktivierung');
}

function calluna_index_module_laden(): void {
    foreach (calluna_index_module() as $kennung => $modul) {
        $z = calluna_index_modul_zustand($kennung);
        if (!$z['aktiv']) {
            continue;
        }
        try {
            $modul->laden();
        } catch (\Throwable $fehler) {
            calluna_index_protokoll('Modul ' . $kennung . ' nicht geladen: ' . $fehler->getMessage());
        }
    }
}

function calluna_index_init(): void {
    // Option einmalig mit autoload anlegen, damit die Abfrage auf Seiten ohne
    // Einrichtung nicht bei jeder Anfrage die Datenbank trifft.
    if (get_option(CALLUNA_INDEX_OPTION, null) === null) {
        add_option(CALLUNA_INDEX_OPTION, calluna_index_einstellungen_standard(), '', true);
    }
    // Stündlicher Profil-Abruf nur auf eingerichteten Seiten (selbstheilend,
    // weil register_activation_hook bei Updates nicht läuft).
    $geplant = wp_next_scheduled(CALLUNA_INDEX_PROFIL_CRON);
    if (calluna_index_eingerichtet()) {
        if (!$geplant) {
            wp_schedule_event(time() + 120, 'hourly', CALLUNA_INDEX_PROFIL_CRON);
        }
    } elseif ($geplant) {
        wp_clear_scheduled_hook(CALLUNA_INDEX_PROFIL_CRON);
    }
}

function calluna_index_deaktivierung(): void {
    wp_clear_scheduled_hook(CALLUNA_INDEX_PROFIL_CRON);
    wp_clear_scheduled_hook('calluna_index_bau_nachlauf');
}
