<?php
/**
 * Weiterleitungen für die Content Pipe (seit 0.10.0).
 *
 *   POST /calluna/v1/redirects   { von, nach, code? }  → 301 (bzw. 302) von alter auf neue Adresse
 *
 * Ändert die Pipe die Adresse eines Beitrags, einer Kategorie oder eines
 * Schlagworts, legt sie hier die Weiterleitung an. Der Companion speichert sie
 * dort, wo die Website ihre Weiterleitungen ohnehin pflegt — er führt KEINE
 * eigenen aus:
 *
 *   rank-math      Rank Math, Modul „Weiterleitungen“ aktiv (Tabelle rank_math_redirections)
 *   redirection    Plugin „Redirection“ (über dessen REST-API, intern per rest_do_request)
 *   yoast-premium  Yoast SEO Premium (WPSEO_Redirect_Manager)
 *   none           nichts davon → 409 `kein_weiterleitungs_plugin`
 *
 * Regeln:
 *   - Beide Adressen müssen auf diese Website zeigen (gleicher Host wie home_url).
 *   - Gibt es für `von` schon eine Regel (z. B. von Rank Maths Auto-Weiterleitung),
 *     zeigt sie danach auf `nach` (Antwort `vorhanden` bzw. `aktualisiert`).
 *   - Regeln, deren Quelle `nach` ist, werden abgeschaltet: sonst entstünde bei
 *     A→B und später B→A eine Schleife.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Welcher Weg Weiterleitungen speichert ('none' = keiner). */
function calluna_pipe_weiterleitung_weg(): string {
    if (class_exists('\\RankMath\\Helper') && method_exists('\\RankMath\\Helper', 'is_module_active')
        && \RankMath\Helper::is_module_active('redirections')) {
        return 'rank-math';
    }
    if (defined('REDIRECTION_VERSION')) {
        return 'redirection';
    }
    if (class_exists('WPSEO_Redirect_Manager') && class_exists('WPSEO_Redirect')) {
        return 'yoast-premium';
    }
    return 'none';
}

/**
 * Adresse → Pfad dieser Website mit führendem Schrägstrich (ohne Query).
 * null, wenn die Adresse auf einen anderen Host zeigt oder leer ist.
 */
function calluna_pipe_pfad(string $eingabe): ?string {
    $eingabe = trim($eingabe);
    if ($eingabe === '') return null;
    $home = wp_parse_url(home_url('/'));
    $u = wp_parse_url($eingabe);
    if ($u === false) return null;
    if (isset($u['host'])) {
        $ohne_www = static function ($h) { return preg_replace('/^www\./i', '', strtolower((string) $h)); };
        if ($ohne_www($u['host']) !== $ohne_www($home['host'] ?? '')) return null;
    }
    $pfad = isset($u['path']) && $u['path'] !== '' ? $u['path'] : '/';
    return '/' . ltrim($pfad, '/');
}

/** Pfad relativ zum WordPress-Stamm (für Installationen in einem Unterordner). */
function calluna_pipe_pfad_relativ(string $pfad): string {
    $basis = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? ''), '/');
    if ($basis !== '' && strpos($pfad, $basis . '/') === 0) {
        $pfad = substr($pfad, strlen($basis));
    }
    return $pfad;
}

// ── Rank Math ────────────────────────────────────────────────────────────

function calluna_pipe_rm_regeln_mit_quelle(string $muster): array {
    global $wpdb;
    $tabelle = $wpdb->prefix . 'rank_math_redirections';
    $like = '%' . $wpdb->esc_like('"' . $muster . '"') . '%';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, sources, url_to, header_code, status FROM {$tabelle} WHERE status <> 'trashed' AND sources LIKE %s", $like));
    $treffer = [];
    foreach ((array) $rows as $row) {
        $quellen = maybe_unserialize($row->sources);
        if (!is_array($quellen)) continue;
        foreach ($quellen as $q) {
            if (is_array($q) && ($q['comparison'] ?? '') === 'exact' && trim((string) ($q['pattern'] ?? ''), '/') === $muster) {
                $row->quellen = $quellen;
                $treffer[] = $row;
                break;
            }
        }
    }
    return $treffer;
}

function calluna_pipe_rm_cache_leeren(int $id): void {
    global $wpdb;
    $cache = $wpdb->prefix . 'rank_math_redirections_cache';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cache)) === $cache) {
        $wpdb->delete($cache, ['redirection_id' => $id]);
    }
}

function calluna_pipe_weiterleitung_rank_math(string $von, string $nach, int $code): array {
    global $wpdb;
    $tabelle = $wpdb->prefix . 'rank_math_redirections';
    $muster_von = trim(calluna_pipe_pfad_relativ($von), '/');
    $muster_nach = trim(calluna_pipe_pfad_relativ($nach), '/');
    $ziel = home_url(calluna_pipe_pfad_relativ($nach));
    $jetzt = current_time('mysql');

    // Schleifen vermeiden: Regeln mit Quelle = neue Adresse abschalten bzw. die Quelle entfernen.
    $abgeschaltet = 0;
    foreach (calluna_pipe_rm_regeln_mit_quelle($muster_nach) as $row) {
        $rest = array_values(array_filter($row->quellen, static function ($q) use ($muster_nach) {
            return !(is_array($q) && ($q['comparison'] ?? '') === 'exact' && trim((string) ($q['pattern'] ?? ''), '/') === $muster_nach);
        }));
        if ($rest) {
            $wpdb->update($tabelle, ['sources' => maybe_serialize($rest), 'updated' => $jetzt], ['id' => (int) $row->id]);
        } else {
            $wpdb->update($tabelle, ['status' => 'inactive', 'updated' => $jetzt], ['id' => (int) $row->id]);
        }
        calluna_pipe_rm_cache_leeren((int) $row->id);
        $abgeschaltet++;
    }

    $vorhanden = calluna_pipe_rm_regeln_mit_quelle($muster_von);
    if ($vorhanden) {
        $row = $vorhanden[0];
        if (untrailingslashit((string) $row->url_to) === untrailingslashit($ziel) && $row->status === 'active') {
            return ['status' => 'vorhanden', 'abgeschaltet' => $abgeschaltet];
        }
        $wpdb->update($tabelle, ['url_to' => $ziel, 'header_code' => (string) $code, 'status' => 'active', 'updated' => $jetzt], ['id' => (int) $row->id]);
        calluna_pipe_rm_cache_leeren((int) $row->id);
        return ['status' => 'aktualisiert', 'abgeschaltet' => $abgeschaltet];
    }

    $regel = [
        'sources'     => [['pattern' => $muster_von, 'comparison' => 'exact', 'ignore' => '']],
        'url_to'      => $ziel,
        'header_code' => (string) $code,
        'status'      => 'active',
    ];
    if (class_exists('\\RankMath\\Redirections\\DB') && method_exists('\\RankMath\\Redirections\\DB', 'add')) {
        $ok = \RankMath\Redirections\DB::add($regel);
    } else {
        $regel['sources'] = maybe_serialize($regel['sources']);
        $regel['created'] = $jetzt;
        $regel['updated'] = $jetzt;
        $ok = $wpdb->insert($tabelle, $regel);
    }
    if (!$ok) {
        return ['fehler' => 'Rank Math hat die Weiterleitung nicht gespeichert.'];
    }
    return ['status' => 'angelegt', 'abgeschaltet' => $abgeschaltet];
}

// ── Redirection (über dessen eigene REST-API) ─────────────────────────────

function calluna_pipe_redirection_rest(string $methode, string $route, array $daten = []): array {
    $r = new WP_REST_Request($methode, $route);
    if ($methode === 'GET') {
        $r->set_query_params($daten);
    } else {
        $r->set_header('content-type', 'application/json');
        $r->set_body(wp_json_encode($daten));
    }
    $res = rest_do_request($r);
    return [(int) $res->get_status(), $res->get_data()];
}

function calluna_pipe_weiterleitung_redirection(string $von, string $nach, int $code): array {
    $gleich = static function ($a, $b) { return untrailingslashit((string) $a) === untrailingslashit((string) $b); };
    $suche = static function (string $pfad) use ($gleich): array {
        [$s, $d] = calluna_pipe_redirection_rest('GET', '/redirection/v1/redirect', ['filterBy' => ['url' => $pfad], 'per_page' => 100]);
        if ($s >= 400 || !is_array($d) || !isset($d['items']) || !is_array($d['items'])) return [];
        return array_values(array_filter($d['items'], static function ($i) use ($pfad, $gleich) {
            return is_array($i) && empty($i['regex']) && $gleich($i['url'] ?? '', $pfad);
        }));
    };

    $abgeschaltet = 0;
    foreach ($suche($nach) as $i) {
        if (!empty($i['enabled'])) {
            calluna_pipe_redirection_rest('POST', '/redirection/v1/bulk/disable', ['items' => (string) $i['id']]);
            $abgeschaltet++;
        }
    }

    $vorhanden = $suche($von);
    if ($vorhanden) {
        $i = $vorhanden[0];
        $ziel = is_array($i['action_data'] ?? null) ? ($i['action_data']['url'] ?? '') : '';
        if (!empty($i['enabled']) && $gleich($ziel, $nach)) {
            return ['status' => 'vorhanden', 'abgeschaltet' => $abgeschaltet];
        }
        [$s, $d] = calluna_pipe_redirection_rest('POST', '/redirection/v1/redirect/' . (int) $i['id'], [
            'url'         => $i['url'],
            'match_type'  => 'url',
            'action_type' => 'url',
            'action_code' => $code,
            'action_data' => ['url' => $nach],
            'group_id'    => (int) ($i['group_id'] ?? 1),
            'regex'       => false,
            'title'       => (string) ($i['title'] ?? ''),
        ]);
        if ($s >= 400) return ['fehler' => 'Redirection hat die Weiterleitung nicht geändert (HTTP ' . $s . ').'];
        if (empty($i['enabled'])) calluna_pipe_redirection_rest('POST', '/redirection/v1/bulk/enable', ['items' => (string) $i['id']]);
        return ['status' => 'aktualisiert', 'abgeschaltet' => $abgeschaltet];
    }

    [$s, $d] = calluna_pipe_redirection_rest('GET', '/redirection/v1/group', ['per_page' => 100]);
    $gruppe = 0;
    if ($s < 400 && is_array($d) && isset($d['items']) && is_array($d['items'])) {
        foreach ($d['items'] as $g) {
            if (is_array($g) && (int) ($g['module_id'] ?? 0) === 1 && !empty($g['enabled'])) {
                $gruppe = (int) $g['id'];
                break;
            }
        }
    }
    if ($gruppe === 0) return ['fehler' => 'Im Plugin Redirection gibt es keine aktive Gruppe für WordPress-Weiterleitungen.'];
    [$s, $d] = calluna_pipe_redirection_rest('POST', '/redirection/v1/redirect', [
        'url'         => $von,
        'match_type'  => 'url',
        'action_type' => 'url',
        'action_code' => $code,
        'action_data' => ['url' => $nach],
        'group_id'    => $gruppe,
        'regex'       => false,
        'title'       => 'Content Pipe',
    ]);
    if ($s >= 400) return ['fehler' => 'Redirection hat die Weiterleitung nicht gespeichert (HTTP ' . $s . ').'];
    return ['status' => 'angelegt', 'abgeschaltet' => $abgeschaltet];
}

// ── Yoast SEO Premium ─────────────────────────────────────────────────────

function calluna_pipe_weiterleitung_yoast(string $von, string $nach, int $code): array {
    $format = class_exists('WPSEO_Redirect_Formats') ? WPSEO_Redirect_Formats::PLAIN : 'plain';
    $manager = new WPSEO_Redirect_Manager($format);
    $quelle = ltrim(calluna_pipe_pfad_relativ($von), '/');
    $ziel = ltrim(calluna_pipe_pfad_relativ($nach), '/');
    $abgeschaltet = 0;
    if (method_exists($manager, 'get_redirect')) {
        $schleife = $manager->get_redirect($ziel);
        if ($schleife instanceof WPSEO_Redirect && method_exists($manager, 'delete_redirects')) {
            $manager->delete_redirects([$schleife]);
            $abgeschaltet++;
        }
        $alt = $manager->get_redirect($quelle);
        if ($alt instanceof WPSEO_Redirect) {
            if (untrailingslashit((string) $alt->get_target()) === untrailingslashit($ziel)) {
                return ['status' => 'vorhanden', 'abgeschaltet' => $abgeschaltet];
            }
            if (method_exists($manager, 'update_redirect')) {
                $manager->update_redirect($alt, new WPSEO_Redirect($quelle, $ziel, $code, $format));
                return ['status' => 'aktualisiert', 'abgeschaltet' => $abgeschaltet];
            }
        }
    }
    $ok = $manager->create_redirect(new WPSEO_Redirect($quelle, $ziel, $code, $format));
    if ($ok === false) return ['fehler' => 'Yoast SEO Premium hat die Weiterleitung nicht gespeichert.'];
    return ['status' => 'angelegt', 'abgeschaltet' => $abgeschaltet];
}

// ── Endpunkt ──────────────────────────────────────────────────────────────

function calluna_pipe_rest_weiterleitung(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (!is_array($body)) $body = [];
    $code = (int) ($body['code'] ?? 301);
    if (!in_array($code, [301, 302], true)) {
        return new WP_Error('bad_request', 'Nur 301 oder 302.', ['status' => 400]);
    }
    $von = calluna_pipe_pfad((string) ($body['von'] ?? ''));
    $nach = calluna_pipe_pfad((string) ($body['nach'] ?? ''));
    if ($von === null || $nach === null) {
        return new WP_Error('fremde_adresse', 'Beide Adressen müssen auf diese Website zeigen.', ['status' => 400]);
    }
    if (untrailingslashit($von) === untrailingslashit($nach)) {
        return new WP_Error('gleiche_adresse', 'Alte und neue Adresse sind gleich.', ['status' => 400]);
    }
    if ($von === '/') {
        return new WP_Error('startseite', 'Die Startseite wird nicht weitergeleitet.', ['status' => 400]);
    }
    $weg = calluna_pipe_weiterleitung_weg();
    if ($weg === 'none') {
        return new WP_Error(
            'kein_weiterleitungs_plugin',
            'Auf dieser Website ist kein Plugin für Weiterleitungen aktiv (Rank Math mit Modul Weiterleitungen, Redirection oder Yoast SEO Premium).',
            ['status' => 409]
        );
    }
    if ($weg === 'rank-math') $r = calluna_pipe_weiterleitung_rank_math($von, $nach, $code);
    elseif ($weg === 'redirection') $r = calluna_pipe_weiterleitung_redirection($von, $nach, $code);
    else $r = calluna_pipe_weiterleitung_yoast($von, $nach, $code);

    if (isset($r['fehler'])) {
        return new WP_Error('weiterleitung_fehlgeschlagen', $r['fehler'], ['status' => 502, 'weg' => $weg]);
    }
    return new WP_REST_Response([
        'ok'           => true,
        'weg'          => $weg,
        'von'          => $von,
        'nach'         => $nach,
        'code'         => $code,
        'status'       => $r['status'],
        'abgeschaltet' => (int) ($r['abgeschaltet'] ?? 0),
    ], 200);
}

add_action('rest_api_init', function () {
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/redirects', [
        'methods'             => 'POST',
        'callback'            => 'calluna_pipe_rest_weiterleitung',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});

add_filter('calluna_companion_features', function (array $features): array {
    $features[] = 'redirects';
    return $features;
});

add_filter('calluna_companion_info', function (array $info): array {
    $info['redirects'] = calluna_pipe_weiterleitung_weg();
    return $info;
});
