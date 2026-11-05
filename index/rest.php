<?php
/**
 * Calluna Index — REST für den Build (Vertrag v1, Abschnitt 2).
 *
 *   GET /wp-json/calluna/v1/index/build-konfig[?aktualisieren=1]
 *   Auth: Anwendungspasswort eines Nutzers mit manage_options
 *         oder X-Calluna-Token (meldet den Companion als Admin an, siehe Kern).
 *   → { vertrag, profilRevision, module: { <kennung>: { aktiv, quelle, … } }, consent }
 *
 * Bewusst im bestehenden Namespace calluna/v1: kein neuer Namespace, damit die
 * Plugin-Erkennung der Content Pipe (REST-Namespaces) unverändert bleibt.
 */

if (!defined('ABSPATH')) {
    exit;
}

function calluna_index_rest_registrieren(): void {
    register_rest_route('calluna/v1', '/index/build-konfig', [
        'methods'             => 'GET',
        'callback'            => 'calluna_index_rest_build_konfig',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args'                => [
            'aktualisieren' => [
                'description' => 'Vorher das Seitenprofil beim Index nachfragen (mit ETag, kostet nur bei Änderung).',
                'type'        => 'boolean',
                'default'     => false,
            ],
        ],
    ]);
}

function calluna_index_rest_build_konfig(WP_REST_Request $anfrage): WP_REST_Response {
    if ($anfrage->get_param('aktualisieren') && calluna_index_eingerichtet()) {
        calluna_index_profil_abrufen(false);
    }
    $antwort = new WP_REST_Response(calluna_index_build_konfig(), 200);
    $antwort->header('Cache-Control', 'no-store, private');
    return $antwort;
}

/**
 * Die Build-Konfiguration. Felder nach Vertrag v1: vertrag, profilRevision,
 * module (effektiv), consent. Zusätzlich (rein additiv, nicht im Vertrag):
 * siteId, profilAbgerufen, profilFehler, plugin.
 */
function calluna_index_build_konfig(): array {
    $profil = calluna_index_profil();
    $stand = calluna_index_stand();

    $module = [];
    foreach (calluna_index_module() as $kennung => $modul) {
        $z = calluna_index_modul_zustand($kennung);
        $eintrag = [
            'aktiv'  => $z['aktiv'],
            'quelle' => $z['quelle'],
        ];
        if ($modul->vorbereitet()) {
            $eintrag['vorbereitet'] = true;
        }
        if ($z['aktiv']) {
            try {
                $eintrag += $modul->build_konfig();
            } catch (\Throwable $fehler) {
                calluna_index_protokoll('build-konfig ' . $kennung . ': ' . $fehler->getMessage());
            }
        }
        $module[$kennung] = $eintrag;
    }

    // Consent nur, wenn das Modul effektiv an ist — sonst baut das Frontend keinen Banner.
    $consent = null;
    if (!empty($module['consent']['aktiv'])) {
        $objekt = calluna_index_profil_objekt();
        if ($objekt !== null && isset($objekt->consent) && is_object($objekt->consent)) {
            $consent = $objekt->consent;
        }
    }

    return [
        'vertrag'         => CALLUNA_INDEX_VERTRAG,
        'profilRevision'  => $profil !== null && isset($profil['revision']) ? $profil['revision'] : null,
        'module'          => (object) $module,
        'consent'         => $consent,
        'siteId'          => calluna_index_site_id() !== '' ? calluna_index_site_id() : null,
        'profilAbgerufen' => $stand['abgerufen'] ? gmdate('c', (int) $stand['abgerufen']) : null,
        'profilFehler'    => $stand['fehler'] !== '' ? $stand['fehler'] : null,
        'plugin'          => [
            'name'    => 'calluna-companion',
            'version' => defined('CALLUNA_COMPANION_VERSION') ? CALLUNA_COMPANION_VERSION : null,
        ],
    ];
}
