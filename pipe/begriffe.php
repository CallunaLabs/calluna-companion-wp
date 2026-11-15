<?php
/**
 * Kategorien und Schlagwörter für die Content Pipe (seit 0.10.0).
 *
 * Name, Adresse und Löschen gehen über die WordPress-Core-REST-API
 * (/wp/v2/categories, /wp/v2/tags) und brauchen hier nichts. Dieses Modul
 * liefert, was Core nicht kann:
 *
 *   GET  /calluna/v1/terms/{taxonomy}/{id}/seo     SEO-Titel und -Beschreibung der Archivseite lesen
 *   POST /calluna/v1/terms/{taxonomy}/{id}/seo     … schreiben; Antwort = was danach gespeichert ist
 *   GET  /calluna/v1/terms/{taxonomy}/uebersicht   für die Liste: je Begriff SEO-Titel, -Beschreibung,
 *                                                  Datum des ersten und letzten veröffentlichten Beitrags
 *
 * SEO-Plugins:
 *   Yoast SEO  speichert Begriffs-SEO NICHT als Term-Meta, sondern in der Option
 *              `wpseo_taxonomy_meta` ([taxonomy][term_id][wpseo_title|wpseo_desc|…]).
 *              Wir gehen über die Klasse WPSEO_Taxonomy_Meta (set_values/get_term_meta),
 *              sonst direkt über die Option. Bis 0.9.0 schrieb der Companion bei Yoast
 *              in Term-Meta — das hat Yoast nie gelesen.
 *   Rank Math  Term-Meta `rank_math_title`, `rank_math_description`.
 *   AIOSEO     Begriffs-SEO gibt es nur in AIOSEO Pro (eigene Tabelle) → `supported: false`.
 *   keins      `supported: false`.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Welche SEO-Plugins Begriffs-SEO können, die wir lesen und schreiben. */
function calluna_pipe_term_seo_supported(string $plugin): bool {
    return $plugin === 'yoast' || $plugin === 'rank-math';
}

/** Yoast-Werte eines Begriffs (Schlüssel mit Präfix `wpseo_`). */
function calluna_pipe_yoast_term_meta(int $term_id, string $taxonomy): array {
    if (class_exists('WPSEO_Taxonomy_Meta') && method_exists('WPSEO_Taxonomy_Meta', 'get_term_meta')) {
        $meta = WPSEO_Taxonomy_Meta::get_term_meta($term_id, $taxonomy);
        if (is_array($meta)) {
            return $meta;
        }
    }
    $opt = get_option('wpseo_taxonomy_meta');
    if (is_array($opt) && isset($opt[$taxonomy][$term_id]) && is_array($opt[$taxonomy][$term_id])) {
        return $opt[$taxonomy][$term_id];
    }
    return [];
}

/** SEO-Titel und -Beschreibung eines Begriffs lesen. */
function calluna_pipe_term_seo_lesen(int $term_id, string $taxonomy, ?string $plugin = null): array {
    $plugin = $plugin ?? calluna_companion_detect_seo_plugin();
    $titel = '';
    $beschreibung = '';
    if ($plugin === 'yoast') {
        $meta = calluna_pipe_yoast_term_meta($term_id, $taxonomy);
        $titel = isset($meta['wpseo_title']) ? (string) $meta['wpseo_title'] : '';
        $beschreibung = isset($meta['wpseo_desc']) ? (string) $meta['wpseo_desc'] : '';
    } elseif ($plugin === 'rank-math') {
        $titel = (string) get_term_meta($term_id, 'rank_math_title', true);
        $beschreibung = (string) get_term_meta($term_id, 'rank_math_description', true);
    }
    return [
        'plugin'      => $plugin,
        'supported'   => calluna_pipe_term_seo_supported($plugin),
        'title'       => $titel,
        'description' => $beschreibung,
    ];
}

/**
 * SEO-Titel und/oder -Beschreibung eines Begriffs schreiben. "" leert das Feld
 * (dann gilt die Vorlage des SEO-Plugins). Nicht gesendete Felder bleiben.
 * Robots wie bisher (0.9.0) für Rank Math, Yoast (nur noindex) und AIOSEO.
 */
function calluna_companion_write_term_seo(int $term_id, array $seo, string $taxonomy = 'category'): void {
    $plugin = calluna_companion_detect_seo_plugin();
    $titel = array_key_exists('title', $seo) && is_string($seo['title']) ? sanitize_text_field($seo['title']) : null;
    $beschreibung = array_key_exists('description', $seo) && is_string($seo['description'])
        ? sanitize_textarea_field($seo['description'])
        : null;
    $robots = isset($seo['robots']) && is_array($seo['robots'])
        ? array_values(array_unique(array_map('sanitize_key', $seo['robots'])))
        : null;

    if ($plugin === 'yoast') {
        $werte = [];
        if ($titel !== null) $werte['wpseo_title'] = $titel;
        if ($beschreibung !== null) $werte['wpseo_desc'] = $beschreibung;
        if ($robots !== null) $werte['wpseo_noindex'] = in_array('noindex', $robots, true) ? 'noindex' : 'default';
        if (!$werte) return;
        if (class_exists('WPSEO_Taxonomy_Meta') && method_exists('WPSEO_Taxonomy_Meta', 'set_values')) {
            // set_values() übernimmt nicht gesendete Schlüssel nur teilweise aus dem alten
            // Stand (wpseo_canonical u. a. fielen auf den Standard zurück). Darum den
            // ganzen bisherigen Stand mitgeben und nur die eigenen Felder überschreiben.
            $bisher = calluna_pipe_yoast_term_meta($term_id, $taxonomy);
            WPSEO_Taxonomy_Meta::set_values($term_id, $taxonomy, array_merge($bisher, $werte));
            return;
        }
        $opt = get_option('wpseo_taxonomy_meta');
        if (!is_array($opt)) $opt = [];
        if (!isset($opt[$taxonomy]) || !is_array($opt[$taxonomy])) $opt[$taxonomy] = [];
        $bisher = isset($opt[$taxonomy][$term_id]) && is_array($opt[$taxonomy][$term_id]) ? $opt[$taxonomy][$term_id] : [];
        $opt[$taxonomy][$term_id] = array_merge($bisher, $werte);
        update_option('wpseo_taxonomy_meta', $opt);
        return;
    }

    if ($plugin === 'rank-math') {
        foreach (['rank_math_title' => $titel, 'rank_math_description' => $beschreibung] as $key => $wert) {
            if ($wert === null) continue;
            if ($wert === '') delete_term_meta($term_id, $key);
            else update_term_meta($term_id, $key, $wert);
        }
        if ($robots !== null) update_term_meta($term_id, 'rank_math_robots', $robots);
        return;
    }

    if ($plugin === 'aioseo' && $robots !== null) {
        update_term_meta($term_id, '_aioseo_robots_noindex', in_array('noindex', $robots, true) ? '1' : '0');
        update_term_meta($term_id, '_aioseo_robots_nofollow', in_array('nofollow', $robots, true) ? '1' : '0');
    }
}

/** Begriff aus der Anfrage holen; WP_Error 404, wenn es ihn nicht gibt. */
function calluna_pipe_term_aus_anfrage(WP_REST_Request $req) {
    $taxonomy = sanitize_key($req['taxonomy']);
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('not_found', 'Taxonomie nicht gefunden', ['status' => 404]);
    }
    $term = get_term((int) $req['id'], $taxonomy);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('not_found', 'Term nicht gefunden', ['status' => 404]);
    }
    return $term;
}

function calluna_pipe_rest_term_seo_get(WP_REST_Request $req) {
    $term = calluna_pipe_term_aus_anfrage($req);
    if (is_wp_error($term)) return $term;
    return new WP_REST_Response(
        array_merge(['term_id' => $term->term_id, 'taxonomy' => $term->taxonomy], calluna_pipe_term_seo_lesen($term->term_id, $term->taxonomy)),
        200
    );
}

function calluna_pipe_rest_term_seo_post(WP_REST_Request $req) {
    $term = calluna_pipe_term_aus_anfrage($req);
    if (is_wp_error($term)) return $term;
    $body = $req->get_json_params();
    if (!is_array($body) || !isset($body['seo']) || !is_array($body['seo'])) {
        return new WP_Error('bad_request', 'Body needs { seo: { ... } }', ['status' => 400]);
    }
    $plugin = calluna_companion_detect_seo_plugin();
    $nur_robots = !array_key_exists('title', $body['seo']) && !array_key_exists('description', $body['seo']);
    if (!$nur_robots && !calluna_pipe_term_seo_supported($plugin)) {
        // Nichts schreiben und das ehrlich sagen (bis 0.9.0 kam hier `ok: true`).
        return new WP_Error(
            'seo_plugin_unsupported',
            $plugin === 'none'
                ? 'Kein SEO-Plugin aktiv (Yoast SEO oder Rank Math).'
                : 'Dieses SEO-Plugin speichert keinen eigenen Titel für Kategorieseiten, den der Companion schreiben kann.',
            ['status' => 409, 'plugin' => $plugin]
        );
    }
    calluna_companion_write_term_seo($term->term_id, $body['seo'], $term->taxonomy);
    // Antwort = gespeicherter Stand, damit die Pipe prüfen kann, dass es angekommen ist.
    return new WP_REST_Response(
        array_merge(
            ['ok' => true, 'term_id' => $term->term_id, 'taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug],
            calluna_pipe_term_seo_lesen($term->term_id, $term->taxonomy, $plugin)
        ),
        200
    );
}

/**
 * Liste für die Pipe: alle Begriffe einer Taxonomie (seitenweise, je 500) mit
 * SEO-Titel, -Beschreibung und dem Datum des ersten und letzten veröffentlichten
 * Beitrags (WordPress speichert für Begriffe kein eigenes Datum).
 */
function calluna_pipe_rest_term_uebersicht(WP_REST_Request $req) {
    global $wpdb;
    $taxonomy = sanitize_key($req['taxonomy']);
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('not_found', 'Taxonomie nicht gefunden', ['status' => 404]);
    }
    $per_page = max(1, min(500, (int) ($req->get_param('per_page') ?: 500)));
    $page = max(1, (int) ($req->get_param('page') ?: 1));
    $ids = array_values(array_filter(array_map('absint', explode(',', (string) $req->get_param('ids')))));

    $args = ['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC'];
    if ($ids) {
        $args['include'] = array_slice($ids, 0, 500);
    } else {
        $args['number'] = $per_page;
        $args['offset'] = ($page - 1) * $per_page;
    }
    $terms = get_terms($args);
    if (is_wp_error($terms)) return $terms;
    $gesamt = $ids ? count($terms) : (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

    $term_ids = array_map(static function ($t) { return (int) $t->term_id; }, $terms);
    $daten = [];
    if ($term_ids) {
        $platzhalter = implode(',', array_fill(0, count($term_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Platzhalter oben gebaut
        $sql = $wpdb->prepare(
            "SELECT tt.term_id, MIN(p.post_date_gmt) AS erster, MAX(p.post_date_gmt) AS letzter
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = %s AND p.post_status = 'publish' AND p.post_type = 'post' AND tt.term_id IN ($platzhalter)
             GROUP BY tt.term_id",
            array_merge([$taxonomy], $term_ids)
        );
        foreach ((array) $wpdb->get_results($sql) as $row) {
            $daten[(int) $row->term_id] = [$row->erster, $row->letzter];
        }
        if (function_exists('update_termmeta_cache')) update_termmeta_cache($term_ids);
    }

    $plugin = calluna_companion_detect_seo_plugin();
    $iso = static function ($gmt) {
        return $gmt && $gmt !== '0000-00-00 00:00:00' ? gmdate('c', strtotime($gmt . ' UTC')) : null;
    };
    $items = [];
    foreach ($terms as $t) {
        $seo = calluna_pipe_term_seo_lesen((int) $t->term_id, $taxonomy, $plugin);
        $d = $daten[(int) $t->term_id] ?? [null, null];
        $items[] = [
            'id'          => (int) $t->term_id,
            'count'       => (int) $t->count,
            'title'       => $seo['title'],
            'description' => $seo['description'],
            'first_post'  => $iso($d[0]),
            'last_post'   => $iso($d[1]),
        ];
    }
    return new WP_REST_Response([
        'plugin'      => $plugin,
        'supported'   => calluna_pipe_term_seo_supported($plugin),
        'items'       => $items,
        'total'       => $gesamt,
        'total_pages' => $ids ? 1 : (int) max(1, ceil($gesamt / $per_page)),
    ], 200);
}

add_action('rest_api_init', function () {
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)/seo', [
        [
            'methods'             => 'GET',
            'callback'            => 'calluna_pipe_rest_term_seo_get',
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'calluna_pipe_rest_term_seo_post',
            'permission_callback' => fn() => current_user_can('manage_categories'),
        ],
    ]);
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/terms/(?P<taxonomy>[a-z0-9_-]+)/uebersicht', [
        'methods'             => 'GET',
        'callback'            => 'calluna_pipe_rest_term_uebersicht',
        'permission_callback' => fn() => current_user_can('edit_posts'),
    ]);
});

/** /info: Fähigkeiten und was die Pipe für Adressänderungen wissen muss. */
add_filter('calluna_companion_features', function (array $features): array {
    $features[] = 'term_seo';
    $features[] = 'term_overview';
    return $features;
});

add_filter('calluna_companion_info', function (array $info): array {
    $info['permalink_structure'] = (string) get_option('permalink_structure');
    $info['default_category'] = (int) get_option('default_category');
    return $info;
});
