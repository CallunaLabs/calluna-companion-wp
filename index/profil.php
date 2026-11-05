<?php
/**
 * Calluna Index — Seitenprofil abrufen (Vertrag v1, Abschnitt 1).
 *
 *   GET {indexUrl}/api/v1/sites/{siteId}/profil
 *   Authorization: Bearer <Site-Token>, If-None-Match: <ETag>
 *   → 200 Profil-JSON · 304 unverändert · 401 · 404
 *
 * Stündlich per WP-Cron und beim Speichern der Einstellungen. Fehler landen
 * nur in calluna_index_stand und werden auf der Einstellungsseite angezeigt —
 * das alte Profil bleibt gültig, die Seite selbst merkt nichts.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CALLUNA_INDEX_PROFIL_OPTION = 'calluna_index_profil';
const CALLUNA_INDEX_STAND_OPTION  = 'calluna_index_stand';
const CALLUNA_INDEX_PROFIL_MAX    = 524288; // 512 KB

/** Profil als assoziatives Array (null = noch keins). */
function calluna_index_profil(): ?array {
    $roh = get_option(CALLUNA_INDEX_PROFIL_OPTION, '');
    if (!is_string($roh) || $roh === '') {
        return null;
    }
    $daten = json_decode($roh, true);
    return is_array($daten) ? $daten : null;
}

/**
 * Profil als Objektbaum (stdClass) — für die Ausgabe in build-konfig, damit
 * leere Objekte `{}` nicht zu leeren Listen `[]` werden.
 */
function calluna_index_profil_objekt() {
    $roh = get_option(CALLUNA_INDEX_PROFIL_OPTION, '');
    if (!is_string($roh) || $roh === '') {
        return null;
    }
    $daten = json_decode($roh, false);
    return is_object($daten) ? $daten : null;
}

function calluna_index_stand(): array {
    $s = get_option(CALLUNA_INDEX_STAND_OPTION, []);
    if (!is_array($s)) {
        $s = [];
    }
    return $s + [
        'etag'      => '',
        'abgerufen' => 0,    // letzter erfolgreicher Abruf (200 oder 304)
        'geaendert' => 0,    // letzter Abruf mit neuem Inhalt (200)
        'geprueft'  => 0,    // letzter Versuch
        'http'      => 0,
        'fehler'    => '',
        'revision'  => null,
    ];
}

function calluna_index_stand_speichern(array $s): void {
    update_option(CALLUNA_INDEX_STAND_OPTION, $s, false);
}

function calluna_index_profil_url(): string {
    $site = calluna_index_site_id();
    if (!calluna_index_site_id_gueltig($site)) {
        return '';
    }
    return calluna_index_url() . '/api/v1/sites/' . rawurlencode($site) . '/profil';
}

/** Profil und ETag verwerfen (z. B. wenn die Site-ID wechselt). */
function calluna_index_profil_verwerfen(): void {
    delete_option(CALLUNA_INDEX_PROFIL_OPTION);
    $s = calluna_index_stand();
    $s['etag'] = '';
    $s['revision'] = null;
    calluna_index_stand_speichern($s);
    $e = calluna_index_einstellungen();
    $e['vorgaben'] = [];
    calluna_index_einstellungen_speichern($e);
}

function calluna_index_profil_cron(): void {
    if (calluna_index_eingerichtet()) {
        calluna_index_profil_abrufen(false);
    }
}

/**
 * Holt das Profil. $erzwingen = ohne If-None-Match.
 *
 * @return array Stand nach dem Abruf (siehe calluna_index_stand()).
 */
function calluna_index_profil_abrufen(bool $erzwingen = false): array {
    $stand = calluna_index_stand();
    $stand['geprueft'] = time();

    if (!calluna_index_eingerichtet()) {
        $stand['fehler'] = 'Nicht eingerichtet: Site-ID und Site-Token fehlen.';
        $stand['http'] = 0;
        calluna_index_stand_speichern($stand);
        return $stand;
    }
    $url = calluna_index_profil_url();
    if ($url === '') {
        $stand['fehler'] = 'Site-ID ungültig.';
        calluna_index_stand_speichern($stand);
        return $stand;
    }

    $kopf = [
        'Authorization' => 'Bearer ' . calluna_index_site_token(),
        'Accept'        => 'application/json',
        'User-Agent'    => 'CallunaCompanion/' . (defined('CALLUNA_COMPANION_VERSION') ? CALLUNA_COMPANION_VERSION : '0') . '; ' . home_url('/'),
    ];
    $vorhanden = calluna_index_profil();
    if (!$erzwingen && $vorhanden !== null && $stand['etag'] !== '') {
        $kopf['If-None-Match'] = $stand['etag'];
    }

    $antwort = wp_remote_get($url, [
        'timeout'     => 10,
        'redirection' => 0,   // Token nie an ein Weiterleitungsziel schicken
        'headers'     => $kopf,
    ]);

    if (is_wp_error($antwort)) {
        $stand['http'] = 0;
        $stand['fehler'] = 'Index nicht erreichbar: ' . $antwort->get_error_message();
        calluna_index_stand_speichern($stand);
        return $stand;
    }

    $code = (int) wp_remote_retrieve_response_code($antwort);
    $stand['http'] = $code;

    if ($code === 304) {
        if ($vorhanden !== null) {
            $stand['abgerufen'] = time();
            $stand['fehler'] = '';
        } else {
            // 304 ohne gespeichertes Profil: ETag passt zu nichts mehr.
            $stand['etag'] = '';
            $stand['fehler'] = 'Index meldet „unverändert", aber es liegt kein Profil vor — bitte erneut abrufen.';
        }
        calluna_index_stand_speichern($stand);
        return $stand;
    }

    if ($code !== 200) {
        if ($code === 401 || $code === 403) {
            $stand['fehler'] = 'Site-Token abgelehnt (HTTP ' . $code . ').';
        } elseif ($code === 404) {
            $stand['fehler'] = 'Seite „' . calluna_index_site_id() . '" ist im Index nicht bekannt (HTTP 404).';
        } elseif ($code >= 300 && $code < 400) {
            $stand['fehler'] = 'Index antwortet mit einer Weiterleitung (HTTP ' . $code . ') — ist die API-Route von Cloudflare Access ausgenommen?';
        } else {
            $stand['fehler'] = 'Unerwartete Antwort vom Index (HTTP ' . $code . ').';
        }
        calluna_index_stand_speichern($stand);
        return $stand;
    }

    $roh = (string) wp_remote_retrieve_body($antwort);
    if (strlen($roh) > CALLUNA_INDEX_PROFIL_MAX) {
        $stand['fehler'] = 'Profil ist zu groß (' . size_format(strlen($roh)) . ').';
        calluna_index_stand_speichern($stand);
        return $stand;
    }
    $daten = json_decode($roh, true);
    $problem = calluna_index_profil_pruefen($daten, calluna_index_site_id());
    if ($problem !== null) {
        $stand['fehler'] = 'Profil nicht übernommen: ' . $problem;
        calluna_index_stand_speichern($stand);
        return $stand;
    }

    $alte_revision = $vorhanden !== null && isset($vorhanden['revision']) ? $vorhanden['revision'] : null;
    // Unverändert als JSON-Text speichern (Treue für build-konfig, siehe calluna_index_profil_objekt()).
    update_option(CALLUNA_INDEX_PROFIL_OPTION, $roh, false);

    $e = calluna_index_einstellungen();
    $e['vorgaben'] = calluna_index_vorgaben_aus_profil($daten);
    calluna_index_einstellungen_speichern($e);

    $etag = wp_remote_retrieve_header($antwort, 'etag');
    $stand['etag'] = is_string($etag) ? trim($etag) : '';
    $stand['abgerufen'] = time();
    $stand['geaendert'] = time();
    $stand['fehler'] = '';
    $stand['revision'] = isset($daten['revision']) && is_scalar($daten['revision']) ? $daten['revision'] : null;
    calluna_index_stand_speichern($stand);

    calluna_index_uebergaenge_pruefen();

    if ($stand['revision'] !== $alte_revision) {
        /**
         * Neue Profil-Revision übernommen (auch beim ersten Abruf).
         * Das Headless-Modul stößt darüber einen Build an (Consent-Texte o. ä. geändert).
         */
        do_action('calluna_index_profil_geaendert', $daten, $alte_revision);
    }
    return $stand;
}

/**
 * Prüft das Profil gegen Vertrag v1. Liefert eine Fehlermeldung oder null.
 */
function calluna_index_profil_pruefen($daten, string $site_id): ?string {
    if (!is_array($daten)) {
        return 'Antwort ist kein JSON-Objekt.';
    }
    if (!isset($daten['vertrag']) || !is_numeric($daten['vertrag'])) {
        return 'Feld „vertrag" fehlt.';
    }
    $vertrag = (int) $daten['vertrag'];
    if ($vertrag !== CALLUNA_INDEX_VERTRAG) {
        return $vertrag > CALLUNA_INDEX_VERTRAG
            ? 'Profil nutzt Vertrag v' . $vertrag . ', dieses Plugin kennt v' . CALLUNA_INDEX_VERTRAG . ' — bitte Plugin aktualisieren.'
            : 'Profil nutzt veralteten Vertrag v' . $vertrag . ' (erwartet v' . CALLUNA_INDEX_VERTRAG . ').';
    }
    if (isset($daten['site']['id']) && (string) $daten['site']['id'] !== $site_id) {
        return 'Profil gehört zur Seite „' . sanitize_text_field((string) $daten['site']['id']) . '", eingestellt ist „' . $site_id . '".';
    }
    if (isset($daten['module']) && !is_array($daten['module'])) {
        return '„module" ist kein Objekt.';
    }
    if (isset($daten['consent']) && !is_array($daten['consent'])) {
        return '„consent" ist kein Objekt.';
    }
    return null;
}

/**
 * Bereinigte Kopie von profil.module: nur skalare Werte, Texte gekürzt,
 * `aktiv` nur als echtes Boolean (der String "false" ist nicht „an").
 */
function calluna_index_vorgaben_aus_profil(array $profil): array {
    $aus = [];
    if (empty($profil['module']) || !is_array($profil['module'])) {
        return $aus;
    }
    foreach ($profil['module'] as $kennung => $werte) {
        if (!is_string($kennung) || !preg_match('/^[a-z0-9_-]{1,40}$/', $kennung) || !is_array($werte)) {
            continue;
        }
        $sauber = [];
        foreach ($werte as $schluessel => $wert) {
            if (!is_string($schluessel) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $schluessel)) {
                continue;
            }
            if ($schluessel === 'aktiv') {
                $sauber['aktiv'] = ($wert === true);
                continue;
            }
            if (is_bool($wert) || is_int($wert) || is_float($wert)) {
                $sauber[$schluessel] = $wert;
            } elseif (is_string($wert)) {
                $sauber[$schluessel] = substr(trim(wp_strip_all_tags($wert)), 0, 2000);
            }
        }
        $aus[$kennung] = $sauber;
    }
    return $aus;
}
