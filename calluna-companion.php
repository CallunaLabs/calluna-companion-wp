<?php
/**
 * Plugin Name:       Calluna Companion
 * Plugin URI:        https://github.com/callunaLabs/calluna-companion-wp
 * Description:       WordPress-Bridge für Calluna Dashboard + Content Pipe. Normalisiert SEO-Felder (Yoast/RankMath/AIOSEO), bietet flachen Posts-Endpoint, Maintenance-Layer (Health, Plugin-Updates, Multi-Layer Cache-Clear inkl. WP Rocket + Elementor + Raidboxes Server-Cache), Auto-Updates via GitHub-Releases und selbstständige Registrierung beim Calluna Monitor (Heartbeat).
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Calluna Labs
 * Author URI:        https://calluna.ai
 * License:           GPL-2.0+
 * Text Domain:       calluna-companion
 * Update URI:        https://github.com/callunaLabs/calluna-companion-wp
 *
 * Endpoints (Content Pipe):
 *   GET  /wp-json/calluna/v1/info                          Übersicht erkannter Plugins + verfügbarer SEO-Felder
 *   GET  /wp-json/calluna/v1/posts                         Posts inkl. SEO-Meta, Categories, Tags, Featured Image (flat)
 *   POST /wp-json/calluna/v1/posts/{id}                    Update Posts inkl. SEO-Felder (Yoast/RankMath/AIOSEO automatisch)
 *
 * Endpoints (Dashboard Maintenance):
 *   GET  /wp-json/calluna/v1/maintenance/health            WP-/PHP-Version, debug.log-Tail, Cache-Provider-Detection, Update-Counts
 *   GET  /wp-json/calluna/v1/maintenance/plugins           Plugin-Inventory inkl. update_available + new_version
 *   POST /wp-json/calluna/v1/maintenance/cache/clear       Multi-Layer-Flush: Core + WP Rocket + Elementor + W3TC + Super-Cache + Autoptimize + OPcache + Raidboxes Server-Cache
 *   POST /wp-json/calluna/v1/maintenance/cache/raidboxes   Dedizierter Raidboxes Varnish/Nginx Purge (best-effort, multi-mechanism)
 *   POST /wp-json/calluna/v1/maintenance/plugins/{slug}/update   Triggert wp_update_plugin via Plugin_Upgrader
 *
 * Sicherheit: Alle Endpoints erfordern WordPress Application-Password Auth (Basic Auth).
 * Posts: `edit_posts`. Maintenance: `manage_options` bzw. `update_plugins`.
 *
 * Auto-Update: Plugin Update Checker (YahnisElsts) pollt GitHub-Releases von
 *              callunaLabs/calluna-companion-wp. Updates erscheinen wie bei
 *              wordpress.org-Plugins in WP-Admin → Plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CALLUNA_COMPANION_VERSION', '0.7.0');
define('CALLUNA_COMPANION_NAMESPACE', 'calluna/v1');

/* ============================================================================
 * AUTO-UPDATE via GitHub-Releases (Plugin Update Checker v5.7)
 * ========================================================================== */

require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

$calluna_companion_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/callunaLabs/calluna-companion-wp/',
    __FILE__,
    'calluna-companion'
);
// Releases (saubere ZIP-Assets) statt source-tarballs der Tags
$calluna_companion_update_checker->getVcsApi()->enableReleaseAssets();
$calluna_companion_update_checker->setBranch('main');

/* ============================================================================
 * APPLICATION PASSWORDS: Force-enable trotz Front-Door-Basic-Auth
 * ----------------------------------------------------------------------------
 * WordPress detected `$_SERVER['PHP_AUTH_USER']` und schaltet den
 * App-Password-Flow (wp-admin/authorize-application.php) komplett ab —
 * sichtbar als "Deine Website scheint die Basis-Authentifizierung zu
 * verwenden, die derzeit nicht mit den Anwendungspasswörtern kompatibel ist."
 *
 * Bei Staging-Sites hinter Front-Door-Basic-Auth (z.B. myrdbx.io rb/...)
 * ist das ein Hard-Block fuer das Calluna-Onboarding. Wir ueberschreiben
 * deshalb mit hoher Prioritaet die zwei Filter, die WP-Core fuer die
 * Verfuegbarkeits-Checks nutzt. Sicherheits-Effekt: keiner — App-Passwords
 * brauchen weiterhin WP-Login + bestaetigten Authorize-Klick.
 * ========================================================================== */

add_filter('wp_is_application_passwords_available', '__return_true', 999);
add_filter('wp_is_application_passwords_available_for_user', '__return_true', 999, 2);

/*
 * Zusaetzlich: WP-Core prueft auf wp-admin/authorize-application.php DIREKT
 * gegen $_SERVER['PHP_AUTH_USER']/PHP_AUTH_PW. Wenn dort etwas drin steht
 * (Front-Door-Basic-Auth), zeigt WP die Fehlermeldung "scheint Basis-
 * Authentifizierung zu verwenden" — unabhaengig von den Filtern oben.
 *
 * Loesung: auf genau dieser einen Seite die Header aus $_SERVER entfernen,
 * BEVOR WP-Core sie liest. REST-API + Login-Seiten behalten den Header,
 * sonst wuerde das Front-Door-Login brechen.
 */
$calluna_companion_script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
$calluna_companion_req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
if (
    $calluna_companion_script === 'authorize-application.php'
    || strpos($calluna_companion_req, '/wp-admin/authorize-application.php') !== false
) {
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    // Apache/CGI legen die Basic-Auth manchmal in HTTP_AUTHORIZATION ab —
    // auch killen, falls WP daraus PHP_AUTH_* rekonstruieren wuerde.
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}
unset($calluna_companion_script, $calluna_companion_req);

/* ============================================================================
 * TOKEN-AUTH — Front-Door-Basic-Auth-Bypass fuer Calluna Content Pipe
 * ----------------------------------------------------------------------------
 * Problem: Staging-Sites hinter Front-Door-Basic-Auth (z.B. myrdbx.io rb/pw)
 * blockieren den WP-App-Password-Sync. Beide Layer wollen den Authorization-
 * Header — nur einer kann gleichzeitig korrekt sein.
 *
 * Loesung: Content-Pipe sendet zusaetzlich `X-Calluna-Token: <secret>`. Wenn
 * der Token matched:
 *   1. wir entfernen $_SERVER['PHP_AUTH_*'] (verhindert dass WP versucht,
 *      die Front-Door-Basic-Auth-Creds als WP-User zu validieren)
 *   2. wir setzen `determine_current_user`-Filter auf einen Admin-User —
 *      damit alle bestehenden permission_callbacks (current_user_can(...))
 *      ohne Aenderung weiterfunktionieren
 *
 * Der Token wird beim Plugin-Aktivieren generiert (64-Zeichen-Random) und
 * unter "Einstellungen → Calluna Companion" angezeigt. Admin kopiert in
 * Content-Pipe → Tenant-Settings → "Companion-Token".
 *
 * Sicherheit: hash_equals fuer Timing-Safety, 64 Zeichen Entropie reicht
 * gegen Brute-Force. Token rotierbar in der Admin-UI.
 * ========================================================================== */

define('CALLUNA_COMPANION_TOKEN_OPTION', 'calluna_companion_secret');

function calluna_companion_get_or_create_secret(): string {
    $stored = get_option(CALLUNA_COMPANION_TOKEN_OPTION, '');
    if (!is_string($stored) || strlen($stored) < 32) {
        $stored = wp_generate_password(64, false, false);
        update_option(CALLUNA_COMPANION_TOKEN_OPTION, $stored, false);
    }
    return $stored;
}

function calluna_companion_request_has_valid_token(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    $hdr = isset($_SERVER['HTTP_X_CALLUNA_TOKEN']) ? (string) $_SERVER['HTTP_X_CALLUNA_TOKEN'] : '';
    if ($hdr === '') return $cached = false;
    $stored = get_option(CALLUNA_COMPANION_TOKEN_OPTION, '');
    if (!is_string($stored) || $stored === '') return $cached = false;
    return $cached = hash_equals($stored, $hdr);
}

// Sehr frueh laufen, BEVOR WP irgendeine Basic-Auth-Logik triggert.
if (calluna_companion_request_has_valid_token()) {
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

    add_filter('determine_current_user', function ($user_id) {
        if ($user_id) return $user_id;

        // Optional: als ein BESTIMMTER WP-User anmelden (Header X-Calluna-WP-User
        // = Login-Name oder numerische ID). So werden Posts unter dem echten
        // Account des Kunden angelegt statt unter dem aeltesten Admin. Der User
        // MUSS Schreibrechte haben (edit_posts), sonst faellt der Endpoint-
        // permission_callback eh durch. Fallback: aeltester Administrator.
        $wanted = isset($_SERVER['HTTP_X_CALLUNA_WP_USER'])
            ? trim((string) $_SERVER['HTTP_X_CALLUNA_WP_USER'])
            : '';
        if ($wanted !== '') {
            $u = null;
            if (ctype_digit($wanted)) {
                $u = get_user_by('id', (int) $wanted);
            }
            if (!$u) {
                $u = get_user_by('login', $wanted) ?: get_user_by('slug', $wanted) ?: get_user_by('email', $wanted);
            }
            if ($u && user_can($u, 'edit_posts')) {
                return (int) $u->ID;
            }
            // Gewuenschter User nicht gefunden / ohne Rechte → Fallback unten.
        }

        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'fields'  => 'ID',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
        return !empty($admins) ? (int) $admins[0] : false;
    }, 100);

    // Verhindere dass WP-Core eine REST-API-Anfrage als "nicht eingeloggt"
    // ablehnt — wir sind via Token authentifiziert.
    add_filter('rest_authentication_errors', function ($result) {
        // Wenn ein anderes Plugin (z.B. App-Password-Handler) bereits einen
        // Error gesetzt hat, ueberschreiben wir den, da unser Token vorgeht.
        if (is_wp_error($result)) return null;
        return $result;
    }, 999);
}

register_activation_hook(__FILE__, function () {
    calluna_companion_get_or_create_secret();
});

/* WP-Admin Settings → Calluna Companion */
add_action('admin_menu', function () {
    add_options_page(
        'Calluna Companion',
        'Calluna Companion',
        'manage_options',
        'calluna-companion',
        'calluna_companion_settings_page'
    );
});

function calluna_companion_settings_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    if (
        isset($_POST['calluna_companion_action']) &&
        $_POST['calluna_companion_action'] === 'regenerate' &&
        check_admin_referer('calluna_companion_regen')
    ) {
        update_option(CALLUNA_COMPANION_TOKEN_OPTION, wp_generate_password(64, false, false), false);
        echo '<div class="notice notice-success is-dismissible"><p>Token regeneriert. Bestehende Calluna-Verbindungen muessen den neuen Token uebernehmen.</p></div>';
    }
    $secret = calluna_companion_get_or_create_secret();
    ?>
    <div class="wrap">
        <h1>Calluna Companion</h1>
        <p>Dieser Token erlaubt Calluna Content Pipe (https://content-pipe.calluna.ai), diese Site auch dann zu erreichen, wenn sie hinter einem Front-Door-Basic-Auth liegt (z.B. Staging-Schutz).</p>
        <p><strong>Wie zu benutzen:</strong> Token kopieren → in Content Pipe unter <code>Admin → Tenant → Domain-Editor → Companion-Token</code> einfuegen → Speichern.</p>
        <h2>Token</h2>
        <input
            type="text"
            readonly
            value="<?php echo esc_attr($secret); ?>"
            style="width:100%;max-width:640px;font-family:monospace;font-size:13px;padding:8px;background:#f8f9fa;"
            onclick="this.select();document.execCommand('copy');this.nextElementSibling.style.display='inline';setTimeout(()=>this.nextElementSibling.style.display='none',1500);"
        />
        <span style="display:none;color:#46b450;margin-left:8px;">Kopiert!</span>
        <p class="description">Klicken kopiert den Token ins Clipboard. <strong>Behandle ihn wie ein Passwort</strong> — wer den Token hat, kann alle REST-Endpoints administrieren.</p>
        <h2>Token regenerieren</h2>
        <p>Falls der Token kompromittiert wurde: regeneriere ihn hier. Du musst den neuen Token danach in Content Pipe nachtragen, sonst bricht der Sync.</p>
        <form method="post">
            <?php wp_nonce_field('calluna_companion_regen'); ?>
            <input type="hidden" name="calluna_companion_action" value="regenerate" />
            <button type="submit" class="button button-secondary" onclick="return confirm('Token wirklich neu generieren? Bestehende Calluna-Verbindungen brechen bis Du den neuen Token in Content Pipe nachtraegst.');">Token neu generieren</button>
        </form>
    </div>
    <?php
}

/**
 * Helper: Erkennt aktive SEO-Plugins ohne sich auf is_plugin_active() zu verlassen,
 * weil das nur in admin-Kontext zuverlässig funktioniert.
 */
function calluna_companion_detect_seo_plugin(): string {
    if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
        return 'yoast';
    }
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        return 'rank-math';
    }
    if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin')) {
        return 'aioseo';
    }
    return 'none';
}

/**
 * Helper: Liefert die Postmeta-Schlüssel für SEO-Title / -Description / Focus-Keyword
 * abhängig vom erkannten Plugin.
 */
function calluna_companion_seo_meta_keys(string $plugin): array {
    switch ($plugin) {
        case 'yoast':
            return [
                'title'         => '_yoast_wpseo_title',
                'description'   => '_yoast_wpseo_metadesc',
                'focus_keyword' => '_yoast_wpseo_focuskw',
            ];
        case 'rank-math':
            return [
                'title'         => 'rank_math_title',
                'description'   => 'rank_math_description',
                'focus_keyword' => 'rank_math_focus_keyword',
            ];
        case 'aioseo':
            return [
                'title'         => '_aioseo_title',
                'description'   => '_aioseo_description',
                'focus_keyword' => '_aioseo_keyphrases',
            ];
        default:
            return [
                'title'         => '_calluna_seo_title',
                'description'   => '_calluna_seo_description',
                'focus_keyword' => '_calluna_seo_focus_keyword',
            ];
    }
}

/**
 * Liest die SEO-Meta für einen Post. Wenn das aktive Plugin spezifische Helper hat,
 * werden diese bevorzugt (Yoast hat z. B. ein Template, das Variablen ersetzt).
 */
function calluna_companion_read_seo(int $post_id): array {
    $plugin = calluna_companion_detect_seo_plugin();
    $keys   = calluna_companion_seo_meta_keys($plugin);

    return [
        'plugin'        => $plugin,
        'title'         => (string) get_post_meta($post_id, $keys['title'], true),
        'description'   => (string) get_post_meta($post_id, $keys['description'], true),
        'focus_keyword' => (string) get_post_meta($post_id, $keys['focus_keyword'], true),
    ];
}

function calluna_companion_write_seo(int $post_id, array $seo): void {
    $plugin = calluna_companion_detect_seo_plugin();
    $keys   = calluna_companion_seo_meta_keys($plugin);

    if (isset($seo['title'])) {
        update_post_meta($post_id, $keys['title'], sanitize_text_field($seo['title']));
    }
    if (isset($seo['description'])) {
        update_post_meta($post_id, $keys['description'], sanitize_textarea_field($seo['description']));
    }
    if (isset($seo['focus_keyword'])) {
        update_post_meta($post_id, $keys['focus_keyword'], sanitize_text_field($seo['focus_keyword']));
    }
}

/**
 * REST: GET /calluna/v1/info
 */
function calluna_companion_rest_info(): WP_REST_Response {
    $plugin = calluna_companion_detect_seo_plugin();

    $namespaces = rest_get_server()->get_namespaces();
    $detected   = [
        'wpml'        => defined('ICL_SITEPRESS_VERSION'),
        'polylang'    => function_exists('pll_current_language'),
        'woocommerce' => class_exists('WooCommerce'),
        'elementor'   => defined('ELEMENTOR_VERSION'),
        'acf'         => class_exists('ACF'),
    ];

    return new WP_REST_Response([
        'companion_version' => CALLUNA_COMPANION_VERSION,
        'wp_version'        => get_bloginfo('version'),
        'site_url'          => get_site_url(),
        'language'          => get_bloginfo('language'),
        'seo_plugin'        => $plugin,
        'seo_meta_keys'     => calluna_companion_seo_meta_keys($plugin),
        'plugins'           => $detected,
        'rest_namespaces'   => $namespaces,
        'capabilities'      => [
            'can_edit_posts' => current_user_can('edit_posts'),
            'can_publish'    => current_user_can('publish_posts'),
            'can_upload'     => current_user_can('upload_files'),
        ],
    ], 200);
}

/**
 * REST: GET /calluna/v1/posts
 * Erweiterte Posts-Liste mit SEO-Meta + Featured Image + Categories/Tags namentlich.
 */
function calluna_companion_rest_posts(WP_REST_Request $req): WP_REST_Response {
    $page     = max(1, (int) $req->get_param('page') ?: 1);
    $per_page = max(1, min(100, (int) $req->get_param('per_page') ?: 20));
    $search   = sanitize_text_field((string) $req->get_param('search'));
    $status   = sanitize_text_field((string) $req->get_param('status') ?: 'any');

    $query = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => $status === 'any' ? ['publish', 'draft', 'pending', 'private', 'future'] : $status,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        's'              => $search,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    $items = [];
    foreach ($query->posts as $p) {
        $featured_id  = (int) get_post_thumbnail_id($p->ID);
        $featured_url = $featured_id ? wp_get_attachment_image_url($featured_id, 'full') : null;
        $cats         = wp_get_post_categories($p->ID, ['fields' => 'all']);
        $tags         = wp_get_post_tags($p->ID, ['fields' => 'all']);

        $items[] = [
            'id'              => $p->ID,
            'title'           => get_the_title($p),
            'slug'            => $p->post_name,
            'status'          => $p->post_status,
            'date'            => $p->post_date_gmt,
            'modified'        => $p->post_modified_gmt,
            'link'            => get_permalink($p),
            'excerpt'         => has_excerpt($p) ? $p->post_excerpt : wp_trim_words(strip_tags($p->post_content), 30),
            'word_count'      => str_word_count(strip_tags($p->post_content)),
            'featured_image'  => $featured_url,
            'categories'      => array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $cats),
            'tags'            => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $tags),
            'author_id'       => (int) $p->post_author,
            'author_name'     => get_the_author_meta('display_name', $p->post_author),
            'seo'             => calluna_companion_read_seo($p->ID),
        ];
    }

    return new WP_REST_Response([
        'items'       => $items,
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => (int) $query->found_posts,
        'total_pages' => (int) $query->max_num_pages,
    ], 200);
}

/**
 * REST: POST /calluna/v1/posts/{id}
 * Aktualisiert Post + SEO-Felder in einem Call.
 */
function calluna_companion_rest_update_post(WP_REST_Request $req): WP_REST_Response {
    $id = (int) $req['id'];
    if (!$id || !get_post($id)) {
        return new WP_REST_Response(['error' => 'post_not_found'], 404);
    }
    if (!current_user_can('edit_post', $id)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }

    $body = $req->get_json_params() ?: [];
    $update = ['ID' => $id];
    if (isset($body['title']))   $update['post_title']   = sanitize_text_field($body['title']);
    if (isset($body['content'])) $update['post_content'] = wp_kses_post($body['content']);
    if (isset($body['excerpt'])) $update['post_excerpt'] = sanitize_textarea_field($body['excerpt']);
    if (isset($body['slug']))    $update['post_name']    = sanitize_title($body['slug']);
    if (isset($body['status']))  $update['post_status']  = sanitize_key($body['status']);

    if (count($update) > 1) {
        $res = wp_update_post($update, true);
        if (is_wp_error($res)) {
            return new WP_REST_Response(['error' => 'update_failed', 'message' => $res->get_error_message()], 500);
        }
    }

    if (isset($body['categories']) && is_array($body['categories'])) {
        wp_set_post_categories($id, array_map('intval', $body['categories']));
    }
    if (isset($body['tags']) && is_array($body['tags'])) {
        wp_set_post_tags($id, array_map('intval', $body['tags']), false);
    }

    if (!empty($body['featured_image_url'])) {
        $attachment_id = calluna_companion_sideload_image((string) $body['featured_image_url'], $id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($id, $attachment_id);
        }
    }

    if (isset($body['seo']) && is_array($body['seo'])) {
        calluna_companion_write_seo($id, $body['seo']);
    }

    if (isset($body['primary_category'])) {
        $primary = (int) $body['primary_category'];
        $plugin  = calluna_companion_detect_seo_plugin();
        if ($plugin === 'yoast') {
            update_post_meta($id, '_yoast_wpseo_primary_category', $primary);
        } elseif ($plugin === 'rank-math') {
            update_post_meta($id, 'rank_math_primary_category', $primary);
        }
    }

    return calluna_companion_rest_get_post($req);
}

function calluna_companion_rest_get_post(WP_REST_Request $req): WP_REST_Response {
    $id = (int) $req['id'];
    if (!$id || !($p = get_post($id))) {
        return new WP_REST_Response(['error' => 'post_not_found'], 404);
    }
    if (!current_user_can('edit_post', $id) && !current_user_can('read_post', $id)) {
        return new WP_REST_Response(['error' => 'forbidden'], 403);
    }
    $featured_id  = (int) get_post_thumbnail_id($id);
    $featured_url = $featured_id ? wp_get_attachment_image_url($featured_id, 'full') : null;
    $cats         = wp_get_post_categories($id, ['fields' => 'all']);
    $tags         = wp_get_post_tags($id, ['fields' => 'all']);
    return new WP_REST_Response([
        'id'             => $p->ID,
        'title'          => $p->post_title,
        'slug'           => $p->post_name,
        'status'         => $p->post_status,
        'date'           => $p->post_date_gmt,
        'modified'       => $p->post_modified_gmt,
        'link'           => get_permalink($p),
        'excerpt'        => $p->post_excerpt,
        'content'        => $p->post_content,
        'featured_image' => $featured_url,
        'categories'     => array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $cats),
        'tags'           => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug], $tags),
        'seo'            => calluna_companion_read_seo($p->ID),
    ], 200);
}

/**
 * Helper: Sideload eine externe Bild-URL als WP-Anhang und gibt die Attachment-ID zurück.
 */
function calluna_companion_sideload_image(string $url, int $parent_post = 0) {
    if (!function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $tmp = download_url($url);
    if (is_wp_error($tmp)) return $tmp;

    $file_array = [
        'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
        'tmp_name' => $tmp,
    ];
    $id = media_handle_sideload($file_array, $parent_post);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return $id;
    }
    return $id;
}

/**
 * REST-Routes registrieren.
 */
add_action('rest_api_init', function () {
    // Public health endpoint — Onboarding-v2 nutzt das für den Plugin-Detect.
    // Liefert nur Boolean + Version, keine sensitiven Felder.
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/health', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new \WP_REST_Response([
                'ok'      => true,
                'version' => defined('CALLUNA_COMPANION_VERSION') ? CALLUNA_COMPANION_VERSION : '0',
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/info', [
        'methods'             => 'GET',
        'callback'            => 'calluna_companion_rest_info',
        'permission_callback' => fn() => current_user_can('edit_posts'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/posts', [
        'methods'             => 'GET',
        'callback'            => 'calluna_companion_rest_posts',
        'permission_callback' => fn() => current_user_can('edit_posts'),
        'args'                => [
            'page'     => ['default' => 1, 'sanitize_callback' => 'absint'],
            'per_page' => ['default' => 20, 'sanitize_callback' => 'absint'],
            'search'   => ['default' => ''],
            'status'   => ['default' => 'any'],
        ],
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/posts/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'calluna_companion_rest_get_post',
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'calluna_companion_rest_update_post',
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ],
    ]);
});

/**
 * Erweitert auch die Standard-/wp/v2/posts-Antwort um SEO-Felder, sodass
 * Calluna ohne Companion-Plugin (oder rückwärtskompatibel) trotzdem eine
 * vereinheitlichte Sicht bekommt — wenn ein SEO-Plugin aktiv ist.
 */
add_action('rest_api_init', function () {
    register_rest_field('post', 'calluna_seo', [
        'get_callback' => function ($post_arr) {
            return calluna_companion_read_seo((int) $post_arr['id']);
        },
        'update_callback' => function ($value, $post) {
            if (!current_user_can('edit_post', $post->ID)) return false;
            if (is_array($value)) {
                calluna_companion_write_seo((int) $post->ID, $value);
            }
            return true;
        },
        'schema' => [
            'description' => 'Calluna-normalisierte SEO-Felder (mappen automatisch zu Yoast/RankMath/AIOSEO)',
            'type'        => 'object',
            'properties'  => [
                'title'         => ['type' => 'string'],
                'description'   => ['type' => 'string'],
                'focus_keyword' => ['type' => 'string'],
            ],
        ],
    ]);
});

/* ============================================================================
 * MAINTENANCE — für Calluna Dashboard
 * ========================================================================== */

/**
 * Helper: liefert die letzten N Bytes von debug.log + Anzahl "Fatal"-Treffer.
 * Liest niemals mehr als $max_bytes, damit ein riesiges Log die Site nicht killt.
 */
function calluna_companion_maintenance_debug_log_tail(int $max_bytes = 50 * 1024): array {
    $path = WP_CONTENT_DIR . '/debug.log';
    if (!file_exists($path) || !is_readable($path)) {
        return ['exists' => false, 'size_kb' => 0, 'tail' => '', 'fatal_hits' => 0];
    }
    $size = (int) filesize($path);
    $tail = '';
    $fp = @fopen($path, 'rb');
    if ($fp) {
        if ($size > $max_bytes) {
            fseek($fp, -$max_bytes, SEEK_END);
            fgets($fp); // verwerfe angeschnittene erste Zeile
        }
        $tail = stream_get_contents($fp) ?: '';
        fclose($fp);
    }
    $fatal_hits = preg_match_all('/PHP Fatal error/i', $tail) ?: 0;
    return [
        'exists'     => true,
        'size_kb'    => round($size / 1024, 2),
        'tail'       => substr($tail, -8192), // max 8KB im Response
        'fatal_hits' => $fatal_hits,
    ];
}

/**
 * Helper: gibt den Plugin-Dateipfad zu einem URL-Slug zurück (erstes Path-Segment).
 */
function calluna_companion_maintenance_find_plugin_file(string $slug): ?string {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach (array_keys(get_plugins()) as $file) {
        $first = strpos($file, '/') !== false ? substr($file, 0, strpos($file, '/')) : basename($file, '.php');
        if ($first === $slug) {
            return $file;
        }
    }
    return null;
}

/**
 * REST: GET /calluna/v1/maintenance/health
 */
function calluna_companion_maintenance_health(): WP_REST_Response {
    global $wp_version;

    if (!function_exists('wp_get_update_data')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    $update_data = function_exists('wp_get_update_data') ? wp_get_update_data() : ['counts' => []];

    return new WP_REST_Response([
        'companion_version'  => CALLUNA_COMPANION_VERSION,
        'wp_version'         => $wp_version,
        'php_version'        => PHP_VERSION,
        'is_multisite'       => is_multisite(),
        'site_url'           => get_site_url(),
        'home_url'           => get_home_url(),
        'language'           => get_bloginfo('language'),
        'timezone'           => wp_timezone_string(),
        'memory_limit'       => ini_get('memory_limit'),
        'max_execution_time' => (int) ini_get('max_execution_time'),
        'upload_max_filesize'=> ini_get('upload_max_filesize'),
        'debug_log'          => calluna_companion_maintenance_debug_log_tail(),
        'updates'            => [
            'counts' => $update_data['counts'] ?? [],
            'title'  => $update_data['title']  ?? '',
        ],
        'critical_plugins'   => [
            'elementor'     => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
            'elementor_pro' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
            'wp_rocket'     => defined('WP_ROCKET_VERSION') ? WP_ROCKET_VERSION : null,
            'woocommerce'   => defined('WC_VERSION') ? WC_VERSION : null,
            'yoast'         => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
            'rank_math'     => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null,
            'wp_super_cache'=> defined('WPCACHEHOME'),
            'w3tc'          => function_exists('w3tc_flush_all'),
            'autoptimize'   => class_exists('autoptimizeCache'),
        ],
        'caches_available' => [
            'wp_rocket'      => function_exists('rocket_clean_domain'),
            'elementor'      => class_exists('\\Elementor\\Plugin'),
            'w3tc'           => function_exists('w3tc_flush_all'),
            'wp_super_cache' => function_exists('wp_cache_clean_cache'),
            'autoptimize'    => class_exists('autoptimizeCache'),
            'opcache'        => function_exists('opcache_reset'),
            'raidboxes'      => calluna_companion_is_raidboxes(),
        ],
        'capabilities' => [
            'can_manage_options' => current_user_can('manage_options'),
            'can_update_plugins' => current_user_can('update_plugins'),
        ],
        'checked_at' => current_time('c'),
    ], 200);
}

/**
 * REST: GET /calluna/v1/maintenance/plugins
 * Plugin-Inventory inkl. Update-Status. Refresht den update_plugins-Transient.
 */
function calluna_companion_maintenance_plugins(): WP_REST_Response {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('wp_update_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    wp_update_plugins();

    $all     = get_plugins();
    $updates = get_site_transient('update_plugins');
    $active  = (array) get_option('active_plugins', []);
    if (is_multisite()) {
        $network_active = array_keys((array) get_site_option('active_sitewide_plugins', []));
        $active = array_unique(array_merge($active, $network_active));
    }
    $auto_updates = (array) get_site_option('auto_update_plugins', []);

    $items = [];
    foreach ($all as $file => $data) {
        $update_available = isset($updates->response[$file]);
        $new_version      = $update_available ? $updates->response[$file]->new_version : null;
        $slug             = strpos($file, '/') !== false ? substr($file, 0, strpos($file, '/')) : basename($file, '.php');

        $items[] = [
            'file'             => $file,
            'slug'             => $slug,
            'name'             => $data['Name'] ?? '',
            'version'          => $data['Version'] ?? '',
            'author'           => wp_strip_all_tags($data['Author'] ?? ''),
            'plugin_uri'       => $data['PluginURI'] ?? '',
            'description'      => wp_strip_all_tags($data['Description'] ?? ''),
            'active'           => in_array($file, $active, true),
            'update_available' => $update_available,
            'new_version'      => $new_version,
            'auto_update'      => in_array($file, $auto_updates, true),
        ];
    }

    return new WP_REST_Response([
        'items'             => $items,
        'total'             => count($items),
        'updates_available' => count(array_filter($items, fn($p) => $p['update_available'])),
        'checked_at'        => current_time('c'),
    ], 200);
}

/* ============================================================================
 * RAIDBOXES — Server-side Varnish/Nginx cache purge
 * Multi-mechanism, best-effort, fail-soft.
 * ========================================================================== */

/**
 * Detect whether this site is hosted on Raidboxes.
 * Returns true if ANY signal matches.
 */
function calluna_companion_is_raidboxes(): bool {
    // 1. Constants set by Raidboxes plugin/MU-plugin
    if (defined('RAIDBOXES_CACHE_VERSION') || defined('RB_INSTALL_PATH')) return true;
    // 2. Standard Raidboxes plugin active
    if (function_exists('is_plugin_active')) {
        if (is_plugin_active('raidboxes/raidboxes.php')) return true;
        if (is_plugin_active('rb-cache/rb-cache.php')) return true;
    }
    // 3. Common Raidboxes host signature in HTTP_HOST (staging domains)
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if (preg_match('/myrdbx\.io$/i', $host)) return true;
    // 4. Filesystem markers Raidboxes drops in their containers
    if (file_exists('/etc/raidboxes') || file_exists('/var/lib/raidboxes')) return true;
    return false;
}

/**
 * Best-effort Raidboxes cache purge.
 * Tries multiple known mechanisms and reports per-mechanism outcome.
 *
 * @return array{ok: bool, attempts: array}
 */
function calluna_companion_purge_raidboxes(): array {
    $attempts = [];

    // 1. Action hook (preferred if Raidboxes plugin is active)
    if (has_action('rb_clear_cache')) {
        try {
            do_action('rb_clear_cache');
            $attempts['rb_clear_cache_action'] = ['ok' => true];
        } catch (Throwable $e) {
            $attempts['rb_clear_cache_action'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    } else {
        $attempts['rb_clear_cache_action'] = ['ok' => false, 'error' => 'action not registered'];
    }

    // 2. Direct function calls (various plugin/MU-plugin versions)
    foreach (['rb_flush_cache', 'raidboxes_clear_cache', 'rb_cache_flush_all'] as $fn) {
        if (function_exists($fn)) {
            try {
                $fn();
                $attempts["fn_$fn"] = ['ok' => true];
            } catch (Throwable $e) {
                $attempts["fn_$fn"] = ['ok' => false, 'error' => $e->getMessage()];
            }
            break; // only try the first one that exists
        }
    }

    // 3. HTTP PURGE method on homepage (Varnish-compatible)
    $home = home_url('/');
    $purge = wp_remote_request($home, [
        'method'    => 'PURGE',
        'timeout'   => 5,
        'sslverify' => false,
    ]);
    if (is_wp_error($purge)) {
        $attempts['purge_request'] = ['ok' => false, 'error' => $purge->get_error_message()];
    } else {
        $code = wp_remote_retrieve_response_code($purge);
        $attempts['purge_request'] = ['ok' => $code >= 200 && $code < 400, 'status' => $code];
    }

    // 4. BAN request — some Varnish setups support BAN for regex-based invalidation
    $ban = wp_remote_request($home, [
        'method'    => 'BAN',
        'timeout'   => 5,
        'sslverify' => false,
        'headers'   => ['X-Ban-Url' => '.*'],
    ]);
    if (is_wp_error($ban)) {
        $attempts['ban_request'] = ['ok' => false, 'error' => $ban->get_error_message()];
    } else {
        $code = wp_remote_retrieve_response_code($ban);
        $attempts['ban_request'] = ['ok' => $code >= 200 && $code < 400, 'status' => $code];
    }

    $ok = array_reduce($attempts, fn($carry, $a) => $carry || ($a['ok'] ?? false), false);
    return ['ok' => $ok, 'attempts' => $attempts];
}

/**
 * REST: POST /calluna/v1/maintenance/cache/clear
 * Multi-Layer-Flush: Core Object → WP Rocket (Domain + Minify + Critical-CSS) →
 * Elementor CSS → W3TC → Super-Cache → Autoptimize → OPcache → Raidboxes.
 * Returnt 200 wenn alle erkannten Layer geleert, 207 wenn Teilfehler.
 */
function calluna_companion_maintenance_cache_clear(): WP_REST_Response {
    $cleared = [];
    $errors  = [];

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $cleared[] = 'wp_object_cache';
    }

    if (function_exists('rocket_clean_domain')) {
        try {
            rocket_clean_domain();
            $cleared[] = 'wp_rocket_domain';
            if (function_exists('rocket_clean_minify')) {
                rocket_clean_minify();
                $cleared[] = 'wp_rocket_minify';
            }
            if (function_exists('rocket_clean_critical_css')) {
                rocket_clean_critical_css();
                $cleared[] = 'wp_rocket_critical_css';
            }
            if (function_exists('rocket_generate_advanced_cache_file')) {
                rocket_generate_advanced_cache_file();
                $cleared[] = 'wp_rocket_advanced_cache_regenerated';
            }
        } catch (Throwable $e) {
            $errors['wp_rocket'] = $e->getMessage();
        }
    }

    if (class_exists('\\Elementor\\Plugin')) {
        try {
            $elementor = \Elementor\Plugin::$instance ?? null;
            if ($elementor && isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                $elementor->files_manager->clear_cache();
                $cleared[] = 'elementor_css';
            }
        } catch (Throwable $e) {
            $errors['elementor'] = $e->getMessage();
        }
    }

    if (function_exists('w3tc_flush_all')) {
        try {
            w3tc_flush_all();
            $cleared[] = 'w3_total_cache';
        } catch (Throwable $e) {
            $errors['w3tc'] = $e->getMessage();
        }
    }

    if (function_exists('wp_cache_clean_cache')) {
        try {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix ?? '');
            $cleared[] = 'wp_super_cache';
        } catch (Throwable $e) {
            $errors['wp_super_cache'] = $e->getMessage();
        }
    }

    if (class_exists('autoptimizeCache')) {
        try {
            \autoptimizeCache::clearall();
            $cleared[] = 'autoptimize';
        } catch (Throwable $e) {
            $errors['autoptimize'] = $e->getMessage();
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
        $cleared[] = 'opcache';
    }

    // Raidboxes server cache (Varnish + Nginx) — only when detected
    $raidboxes = null;
    if (calluna_companion_is_raidboxes()) {
        $raidboxes = calluna_companion_purge_raidboxes();
        if (!($raidboxes['ok'] ?? false)) {
            $errors['raidboxes'] = 'all_mechanisms_failed';
        } else {
            $cleared[] = 'raidboxes_server_cache';
        }
    }

    return new WP_REST_Response([
        'ok'         => empty($errors),
        'cleared'    => $cleared,
        'errors'     => $errors,
        'raidboxes'  => $raidboxes,
        'cleared_at' => current_time('c'),
    ], empty($errors) ? 200 : 207);
}

/**
 * REST: POST /calluna/v1/maintenance/plugins/{slug}/update
 * Initialisiert WP_Filesystem (direct mode) und ruft Plugin_Upgrader->upgrade().
 * Liefert from_version/to_version + Upgrader-Messages zurück.
 */
function calluna_companion_maintenance_plugin_update(WP_REST_Request $req): WP_REST_Response {
    $slug = sanitize_key((string) $req['slug']);
    if (!$slug) {
        return new WP_REST_Response(['error' => 'invalid_slug'], 400);
    }

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!function_exists('wp_update_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    $plugin_file = calluna_companion_maintenance_find_plugin_file($slug);
    if (!$plugin_file) {
        return new WP_REST_Response(['error' => 'plugin_not_found', 'slug' => $slug], 404);
    }

    $all          = get_plugins();
    $from_version = $all[$plugin_file]['Version'] ?? '';

    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    if (!isset($updates->response[$plugin_file])) {
        return new WP_REST_Response([
            'ok'               => true,
            'no_update_needed' => true,
            'slug'             => $slug,
            'plugin_file'      => $plugin_file,
            'current_version'  => $from_version,
        ], 200);
    }
    $target_version = $updates->response[$plugin_file]->new_version;

    $creds = request_filesystem_credentials('', 'direct', false, false, null, true);
    if ($creds === false || !WP_Filesystem($creds)) {
        return new WP_REST_Response([
            'ok'      => false,
            'error'   => 'filesystem_unavailable',
            'message' => 'WordPress kann nicht ohne FTP-Credentials auf das Filesystem zugreifen (FS_METHOD != direct). Update via Dashboard nicht möglich.',
        ], 500);
    }

    ob_start();
    $skin     = new \Automatic_Upgrader_Skin();
    $upgrader = new \Plugin_Upgrader($skin);
    $result   = $upgrader->upgrade($plugin_file);
    $log      = ob_get_clean();

    if (is_wp_error($result)) {
        return new WP_REST_Response([
            'ok'             => false,
            'error'          => 'upgrade_failed',
            'message'        => $result->get_error_message(),
            'slug'           => $slug,
            'plugin_file'    => $plugin_file,
            'from_version'   => $from_version,
            'target_version' => $target_version,
            'messages'       => $skin->get_upgrade_messages(),
            'log'            => $log,
        ], 500);
    }
    if ($result === false) {
        return new WP_REST_Response([
            'ok'             => false,
            'error'          => 'upgrade_returned_false',
            'slug'           => $slug,
            'plugin_file'    => $plugin_file,
            'from_version'   => $from_version,
            'target_version' => $target_version,
            'messages'       => $skin->get_upgrade_messages(),
            'log'            => $log,
        ], 500);
    }

    $all_after  = get_plugins();
    $to_version = $all_after[$plugin_file]['Version'] ?? $target_version;

    return new WP_REST_Response([
        'ok'           => true,
        'slug'         => $slug,
        'plugin_file'  => $plugin_file,
        'from_version' => $from_version,
        'to_version'   => $to_version,
        'messages'     => $skin->get_upgrade_messages(),
        'updated_at'   => current_time('c'),
    ], 200);
}

/**
 * REST: GET/POST /calluna/v1/maintenance/option
 * Schmaler, whitelisted Setter für Calluna-Update-Optionen — KEIN generischer
 * Options-Writer, KEIN Code-Upload. Erlaubt sind nur:
 *   - *_github_token  (z.B. cp_github_token, cdb_github_token — Bootstrap-Token)
 *   - calluna_proxy_secret  (X-Calluna-Proxy für den Update-Proxy)
 *
 * GET  → meldet maskiert, welche dieser Optionen gesetzt sind.
 * POST → {"name":"<option>","value":"<wert>"}  schreibt den Wert (autoload=false).
 *        Alternativ {"name":"…","copy_from":"<andere whitelisted option>"} kopiert.
 */
function calluna_companion_maintenance_option_is_allowed(string $opt): bool {
    return $opt === 'calluna_proxy_secret' || substr($opt, -13) === '_github_token';
}

function calluna_companion_maintenance_option(WP_REST_Request $req): WP_REST_Response {
    $known = ['calluna_github_token', 'cp_github_token', 'cdb_github_token', 'calluna_proxy_secret'];

    if ($req->get_method() === 'GET') {
        $report = [];
        foreach ($known as $opt) {
            $v = (string) get_option($opt, '');
            $report[$opt] = $v === '' ? null : (substr($v, 0, 4) . '…' . substr($v, -4) . ' (' . strlen($v) . ')');
        }
        return new WP_REST_Response(['ok' => true, 'options' => $report], 200);
    }

    $name = sanitize_key((string) ($req->get_param('name') ?? ''));
    if ($name === '' || !calluna_companion_maintenance_option_is_allowed($name)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'option_not_allowed', 'message' => 'Nur *_github_token oder calluna_proxy_secret.'], 400);
    }

    $value = (string) ($req->get_param('value') ?? '');
    $copy  = sanitize_key((string) ($req->get_param('copy_from') ?? ''));
    if ($value === '' && $copy !== '' && calluna_companion_maintenance_option_is_allowed($copy)) {
        $value = (string) get_option($copy, '');
    }
    if ($value === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'no_value', 'message' => 'value oder copy_from (mit Wert) nötig.'], 400);
    }

    update_option($name, $value, false);
    return new WP_REST_Response(['ok' => true, 'option' => $name, 'value_len' => strlen($value)], 200);
}

add_action('rest_api_init', function () {
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/health', [
        'methods'             => 'GET',
        'callback'            => 'calluna_companion_maintenance_health',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/option', [
        'methods'             => ['GET', 'POST'],
        'callback'            => 'calluna_companion_maintenance_option',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/plugins', [
        'methods'             => 'GET',
        'callback'            => 'calluna_companion_maintenance_plugins',
        'permission_callback' => fn() => current_user_can('update_plugins'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/cache/clear', [
        'methods'             => 'POST',
        'callback'            => 'calluna_companion_maintenance_cache_clear',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/plugins/(?P<slug>[a-zA-Z0-9_\-]+)/update', [
        'methods'             => 'POST',
        'callback'            => 'calluna_companion_maintenance_plugin_update',
        'permission_callback' => fn() => current_user_can('update_plugins'),
    ]);

    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/maintenance/cache/raidboxes', [
        'methods'  => 'POST',
        'callback' => function () {
            if (!calluna_companion_is_raidboxes()) {
                return new WP_REST_Response(['ok' => false, 'error' => 'not_raidboxes'], 400);
            }
            return new WP_REST_Response(calluna_companion_purge_raidboxes(), 200);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});

/* ============================================================================
 * MONITOR HEARTBEAT — periodic self-registration to monitor.calluna.ai
 * ========================================================================== */

define('CALLUNA_MONITOR_HEARTBEAT_URL', 'https://monitor.calluna.ai/api/companion/discover');
define('CALLUNA_MONITOR_HEARTBEAT_CRON', 'calluna_companion_monitor_heartbeat');

function calluna_companion_monitor_heartbeat_payload(): array {
    global $wp_version;
    return [
        'site_url'       => home_url(),
        'site_name'      => get_bloginfo('name'),
        'wp_version'     => $wp_version ?? null,
        'plugin_version' => CALLUNA_COMPANION_VERSION,
        'admin_email'    => get_bloginfo('admin_email'),
        'multisite'      => is_multisite(),
    ];
}

function calluna_companion_monitor_heartbeat_send(): void {
    $body = wp_json_encode(calluna_companion_monitor_heartbeat_payload());
    $headers = ['Content-Type' => 'application/json'];
    // Optional shared secret (defined in wp-config.php as CALLUNA_MONITOR_REGISTER_TOKEN)
    if (defined('CALLUNA_MONITOR_REGISTER_TOKEN') && CALLUNA_MONITOR_REGISTER_TOKEN) {
        $headers['Authorization'] = 'Bearer ' . CALLUNA_MONITOR_REGISTER_TOKEN;
    }
    // Filter to override URL or skip entirely (return falsy URL = skip)
    $url = apply_filters('calluna_monitor_heartbeat_url', CALLUNA_MONITOR_HEARTBEAT_URL);
    if (!$url) return;

    wp_remote_post($url, [
        'timeout'     => 5,
        'blocking'    => false,
        'headers'     => $headers,
        'body'        => $body,
        'data_format' => 'body',
        'sslverify'   => true,
    ]);
}

// On plugin activation: schedule cron + send immediately
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(CALLUNA_MONITOR_HEARTBEAT_CRON)) {
        wp_schedule_event(time() + 60, 'daily', CALLUNA_MONITOR_HEARTBEAT_CRON);
    }
    // Defer the first beat to avoid blocking activation
    wp_schedule_single_event(time() + 30, CALLUNA_MONITOR_HEARTBEAT_CRON);
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(CALLUNA_MONITOR_HEARTBEAT_CRON);
});

add_action(CALLUNA_MONITOR_HEARTBEAT_CRON, 'calluna_companion_monitor_heartbeat_send');

// Self-heal scheduling on every init: register_activation_hook does NOT fire
// on plugin updates, so sites that auto-updated from an earlier version
// without heartbeat-scheduling would never ping. Check + register on init
// (cheap, runs once per request, no-op when already scheduled).
add_action('init', function () {
    if (!wp_next_scheduled(CALLUNA_MONITOR_HEARTBEAT_CRON)) {
        wp_schedule_event(time() + 60, 'daily', CALLUNA_MONITOR_HEARTBEAT_CRON);
        // Also fire one immediate single-event so the user sees a heartbeat
        // shortly after the update lands, not 24h later.
        wp_schedule_single_event(time() + 30, CALLUNA_MONITOR_HEARTBEAT_CRON);
    }
});

// Admin-triggerable REST endpoint to send a heartbeat on demand (debugging).
add_action('rest_api_init', function () {
    register_rest_route(CALLUNA_COMPANION_NAMESPACE, '/monitor/heartbeat', [
        'methods'  => 'POST',
        'callback' => function () {
            calluna_companion_monitor_heartbeat_send();
            return new WP_REST_Response([
                'ok' => true,
                'scheduled_next' => wp_next_scheduled(CALLUNA_MONITOR_HEARTBEAT_CRON),
                'payload' => calluna_companion_monitor_heartbeat_payload(),
            ], 200);
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);
});
