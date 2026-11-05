<?php
/**
 * Modul „headless" — WordPress ist nur Redaktion, ausgeliefert wird ein
 * gebautes Frontend (Astro o. ä.).
 *
 * Generischer Ersatz für die projektspezifischen mu-plugins
 * (paqato-headless.php + paqato-ausspielen.php, calluna-frontend.php +
 * calluna-ausspielen.php), konfigurierbar statt hartkodiert:
 *
 *  Umleitung (Teil 1)
 *   - Öffentliche CMS-Seiten → 302 auf {frontendUrl}{pfad}. Ausgenommen:
 *     Vorschau (?preview, ?p, ?page_id), wp-admin, Login, REST, AJAX, Cron,
 *     XML-RPC, GraphQL, robots.txt/Favicon, frei einstellbare Pfad-Anfänge.
 *   - Optional Pfad je Dokument aus einem Meta-Feld (z. B. paqato_pfad).
 *   - „Ansehen"-Links im Admin (und in der Editor-REST-Abfrage) zeigen aufs
 *     Frontend, in neuem Tab; die Vorschau bleibt im CMS.
 *   - Optional noindex/X-Robots-Tag fürs CMS — nur solange umgeleitet wird.
 *
 *  Build (Teil 2)
 *   - Veröffentlichen/Aktualisieren/Löschen/geplantes Veröffentlichen von
 *     Beiträgen und Seiten, Begriffe, Menüs, Schreibzugriffe der Content Pipe
 *     → Build-Hook (Webhook-POST, GitHub repository_dispatch oder
 *     Auftragsdatei). Entprellt: erster Auslöser sofort, weitere innerhalb der
 *     Sperrzeit ergeben genau einen Nachlauf (WP-Cron).
 *   - Admin-Leiste: „↻ Seite neu bauen". Status des letzten Builds, inkl.
 *     Antwort des Hooks bzw. stand.txt bei Auftragsdateien.
 *
 * Verträgt sich mit den Projekt-mu-plugins: Erledigt dort schon etwas die
 * Umleitung bzw. das Ausspielen, bleibt der jeweilige Teil hier aus und die
 * Einstellungsseite sagt es (siehe fremde_umleitung()/fremder_bau()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Calluna_Index_Modul_Headless extends Calluna_Index_Modul {

    const STAND    = 'calluna_index_bau_stand';     // Option: letzter Auslöser + Antwort
    const SPERRE   = 'calluna_index_bau_sperre';    // Transient: Entprell-Sperre
    const OFFEN    = 'calluna_index_bau_offen';     // Option: Grund für den Nachlauf
    const NACHLAUF = 'calluna_index_bau_nachlauf';  // Cron-Hook für den Nachlauf

    /** @var bool In dieser Anfrage schon vorgemerkt (ein Build pro Anfrage). */
    private $vorgemerkt = false;

    /** @var string */
    private $grund = '';

    /** @var string|null Zwischenspeicher frontend_url() */
    private $frontend = null;

    public function kennung(): string {
        return 'headless';
    }

    public function titel(): string {
        return 'Headless';
    }

    public function beschreibung(): string {
        return 'WordPress nur als Redaktion: leitet öffentliche Seiten aufs Frontend um, zeigt „Ansehen" aufs Frontend und stößt nach Änderungen den Build an.';
    }

    public function felder(): array {
        return [
            'frontendUrl' => [
                'typ'         => 'url',
                'titel'       => 'Frontend-Adresse',
                'profil'      => 'frontendUrl',
                'oeffentlich' => true,
                'hilfe'       => 'Öffentliche Adresse der gebauten Seite, z. B. <code>https://www.paqato.com</code>. Ohne gültige Adresse (oder bei gleichem Host wie das CMS) wird nicht umgeleitet.',
            ],
            'umleiten' => [
                'typ'          => 'checkbox',
                'titel'        => 'Umleitung',
                'beschriftung' => 'Öffentliche CMS-Seiten per 302 aufs Frontend umleiten, „Ansehen"-Links aufs Frontend',
                'standard'     => '1',
            ],
            'pfadFeld' => [
                'typ'    => 'text',
                'titel'  => 'Pfad-Feld',
                'profil' => 'pfadFeld',
                'hilfe'  => 'Optional: Meta-Schlüssel mit dem öffentlichen Pfad eines Dokuments (z. B. <code>paqato_pfad</code>). Sonst gilt der Pfad der CMS-Adresse.',
            ],
            'ausnahmen' => [
                'typ'   => 'textarea',
                'titel' => 'Nicht umleiten',
                'hilfe' => 'Pfad-Anfänge, einer pro Zeile (z. B. <code>/feed/</code>). Vorschau, wp-admin, Login, REST, AJAX, Cron, XML-RPC, GraphQL und robots.txt sind immer ausgenommen.',
            ],
            'noindex' => [
                'typ'          => 'checkbox',
                'titel'        => 'Suchmaschinen',
                'beschriftung' => 'CMS nicht indexieren (noindex + X-Robots-Tag) — nur solange umgeleitet wird',
                'standard'     => '1',
            ],
            'buildHook' => [
                'typ'    => 'ziel',
                'titel'  => 'Build-Hook',
                'profil' => 'buildHook',
                'hilfe'  => 'Webhook-URL (POST), GitHub-Dispatch-URL (<code>https://api.github.com/repos/OWNER/REPO/dispatches</code>) oder bei „Auftragsdatei" ein Serververzeichnis (absoluter Pfad). Leer = kein Build-Auslöser.',
            ],
            'hookArt' => [
                'typ'      => 'auswahl',
                'titel'    => 'Hook-Art',
                'profil'   => 'hookArt',
                'standard' => 'webhook',
                'optionen' => [
                    'webhook' => 'Webhook (POST JSON)',
                    'github'  => 'GitHub repository_dispatch',
                    'datei'   => 'Auftragsdatei (Verzeichnis)',
                ],
                'hilfe'    => 'Webhook: <code>{quelle, siteId, cms, grund, zeitpunkt}</code>. GitHub: <code>{event_type, client_payload}</code>. Auftragsdatei: schreibt <code>&lt;Verzeichnis&gt;/auftrag</code>, liest <code>stand.txt</code> als Status.',
            ],
            'hookToken' => [
                'typ'    => 'passwort',
                'geheim' => true,
                'titel'  => 'Hook-Token',
                'hilfe'  => 'Optional, wird als <code>Authorization: Bearer …</code> mitgeschickt. Bei GitHub Pflicht (fine-grained Token, „Contents: Read and write"). Nur lokal, nie aus dem Profil.',
            ],
            'githubEreignis' => [
                'typ'      => 'text',
                'titel'    => 'GitHub-Ereignis',
                'profil'   => 'githubEreignis',
                'standard' => 'cms-publish',
            ],
            'entprellung' => [
                'typ'      => 'zahl',
                'titel'    => 'Entprellung (Sekunden)',
                'standard' => '30',
                'min'      => 5,
                'max'      => 900,
                'hilfe'    => 'Der erste Auslöser baut sofort; alle weiteren in dieser Zeit ergeben genau einen Nachlauf danach.',
            ],
            'inhaltstypen' => [
                'typ'   => 'text',
                'titel' => 'Inhaltstypen',
                'hilfe' => 'Kommagetrennt (z. B. <code>post, page, wp_block</code>). Leer = alle öffentlichen Inhaltstypen außer Medien, plus wiederverwendbare Blöcke und Navigation.',
            ],
            'neuBauenBeiProfil' => [
                'typ'          => 'checkbox',
                'titel'        => 'Profil-Änderung',
                'beschriftung' => 'Neu bauen, wenn eine neue Profil-Revision ankommt (z. B. geänderte Consent-Texte)',
                'standard'     => '1',
            ],
        ];
    }

    /* ------------------------------------------------------------ Konflikte */

    /** Erledigt schon ein Projekt-mu-plugin die Frontend-Umleitung? Liefert dessen Beschreibung. */
    public function fremde_umleitung(): ?string {
        $grund = null;
        if (defined('PAQATO_FRONTEND_URL') || function_exists('paqato_frontend_link')) {
            $grund = 'mu-plugin „paqato — Headless-Redaktion" (paqato-headless.php)';
        } elseif (defined('CALLUNA_FRONTEND_URL')) {
            $grund = 'mu-plugin „Calluna — Frontend weiterleiten" (calluna-frontend.php)';
        }
        /** Projekte können hier melden, dass sie selbst umleiten (String = Beschreibung). */
        $grund = apply_filters('calluna_index_headless_fremde_umleitung', $grund);
        return is_string($grund) && $grund !== '' ? $grund : null;
    }

    /** Erledigt schon ein Projekt-mu-plugin das Ausspielen (Build-Auslöser)? */
    public function fremder_bau(): ?string {
        $grund = null;
        if (function_exists('paqato_ausspielen_auftrag')) {
            $grund = 'mu-plugin „paqato — Ausspielen nach Veröffentlichen" (paqato-ausspielen.php)';
        } elseif (function_exists('calluna_ausspielen')) {
            $grund = 'mu-plugin „Calluna — Ausspielen nach Veröffentlichen" (calluna-ausspielen.php)';
        }
        $grund = apply_filters('calluna_index_headless_fremder_bau', $grund);
        return is_string($grund) && $grund !== '' ? $grund : null;
    }

    /* ------------------------------------------------------------ Laden */

    public function laden(): void {
        if ($this->umleitung_an()) {
            add_action('template_redirect', [$this, 'umleiten'], 10);
            add_filter('post_link', [$this, 'link'], 99, 2);
            add_filter('page_link', [$this, 'link'], 99, 2);
            add_filter('post_type_link', [$this, 'link'], 99, 2);
            add_filter('preview_post_link', [$this, 'vorschau_link'], 99, 2);
            add_action('admin_bar_menu', [$this, 'leiste_neuer_tab'], 999);
            add_filter('page_row_actions', [$this, 'zeile_neuer_tab']);
            add_filter('post_row_actions', [$this, 'zeile_neuer_tab']);
            if ($this->wert('noindex') === '1') {
                add_filter('wp_robots', [$this, 'robots']);
                add_action('send_headers', [$this, 'robots_kopf']);
            }
        }

        if ($this->bau_an()) {
            add_action('transition_post_status', [$this, 'beitrag_status'], 10, 3);
            add_action('before_delete_post', [$this, 'beitrag_loeschen'], 10, 2);
            add_action('created_term', [$this, 'begriff'], 10, 3);
            add_action('edited_term', [$this, 'begriff'], 10, 3);
            add_action('delete_term', [$this, 'begriff'], 10, 3);
            add_action('wp_update_nav_menu', [$this, 'menue'], 10, 1);
            add_action('wp_delete_nav_menu', [$this, 'menue'], 10, 1);
            add_filter('rest_request_after_callbacks', [$this, 'rest_nachher'], 10, 3);
            add_action(self::NACHLAUF, [$this, 'nachlauf']);
            add_action('admin_bar_menu', [$this, 'leiste_knopf'], 100);
            add_action('admin_post_calluna_index_neu_bauen', [$this, 'knopf']);
            add_action('admin_notices', [$this, 'bau_hinweis']);
            if ($this->wert('neuBauenBeiProfil') === '1') {
                add_action('calluna_index_profil_geaendert', [$this, 'profil_geaendert'], 10, 2);
            }
        }
    }

    public function deaktivieren(): void {
        wp_clear_scheduled_hook(self::NACHLAUF);
        delete_option(self::OFFEN);
        delete_transient(self::SPERRE);
    }

    public function umleitung_an(): bool {
        return $this->wert('umleiten') === '1' && $this->frontend_url() !== '' && $this->fremde_umleitung() === null;
    }

    public function bau_an(): bool {
        return $this->bau_ziel() !== '' && $this->fremder_bau() === null;
    }

    /** Gültige Frontend-Adresse ohne Schlussstrich, '' = keine/ungültig/gleicher Host wie das CMS. */
    public function frontend_url(): string {
        if ($this->frontend !== null) {
            return $this->frontend;
        }
        $url = calluna_index_url_pruefen($this->wert('frontendUrl'));
        if ($url !== '') {
            $frontend_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            $cms_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
            if ($frontend_host === $cms_host) {
                $url = ''; // sonst Umleitungsschleife
            }
        }
        return $this->frontend = $url;
    }

    /* ------------------------------------------------------------ Umleitung */

    private function umleitung_erlaubt(): bool {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return false;
        }
        if (function_exists('is_graphql_request') && is_graphql_request()) {
            return false;
        }
        if (is_preview() || is_customize_preview() || is_robots() || (function_exists('is_favicon') && is_favicon())) {
            return false;
        }
        // Wie die Projekt-mu-plugins: Vorschau-Links (?preview, ?p=, ?page_id=) bleiben im CMS.
        if (isset($_GET['preview']) || isset($_GET['preview_id']) || isset($_GET['p']) || isset($_GET['page_id'])) {
            return false;
        }
        return (bool) apply_filters('calluna_index_headless_umleiten', true);
    }

    /** Pfad-Anfänge, die nicht umgeleitet werden. */
    private function ausnahmen(): array {
        $liste = [];
        foreach (preg_split('/[\r\n]+/', $this->wert('ausnahmen')) as $zeile) {
            $zeile = trim($zeile);
            if ($zeile !== '' && $zeile[0] === '/') {
                $liste[] = $zeile;
            }
        }
        return $liste;
    }

    private function pfad_gueltig($pfad): bool {
        return is_string($pfad)
            && $pfad !== ''
            && $pfad[0] === '/'
            && strpos($pfad, '//') !== 0
            && strpos($pfad, '..') === false
            && !preg_match('/[\s<>"]/', $pfad);
    }

    public function umleiten(): void {
        if (!$this->umleitung_erlaubt()) {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $pfad = (string) wp_parse_url($uri, PHP_URL_PATH);
        if ($pfad === '') {
            $pfad = '/';
        }
        // WordPress in einem Unterverzeichnis (home_url = …/cms): Präfix abschneiden.
        $basis = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        if ($basis !== '') {
            if ($pfad === $basis) {
                $pfad = '/';
            } elseif (strpos($pfad, $basis . '/') === 0) {
                $pfad = substr($pfad, strlen($basis));
            }
        }
        foreach ($this->ausnahmen() as $anfang) {
            if (strpos($pfad, $anfang) === 0) {
                return;
            }
        }
        $feld = $this->wert('pfadFeld');
        if ($feld !== '' && is_singular()) {
            $eigen = get_post_meta(get_queried_object_id(), $feld, true);
            if ($this->pfad_gueltig($eigen)) {
                $pfad = $eigen;
            }
        }
        $ziel = apply_filters('calluna_index_headless_ziel', $this->frontend_url() . $pfad, $pfad);
        if (!is_string($ziel) || $ziel === '') {
            return;
        }
        // 302: das CMS ist kein Ziel für Suchmaschinen, die Weiterleitung bleibt änderbar.
        wp_redirect($ziel, 302, 'Calluna Index');
        exit;
    }

    /**
     * Wo „Ansehen"-Links aufs Frontend zeigen: im Admin und in der GET-Abfrage
     * des Block-Editors (/wp/v2/…?context=edit). NICHT in /calluna/v1/* und
     * nicht in Schreibantworten — die Content Pipe bekommt die Links wie bisher.
     */
    private function link_kontext(): bool {
        if (is_admin()) {
            return true;
        }
        if (!(defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }
        $methode = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($methode !== 'GET' || !isset($_GET['context']) || $_GET['context'] !== 'edit') {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $route = isset($_GET['rest_route']) ? (string) $_GET['rest_route'] : '';
        return strpos($uri, '/wp/v2/') !== false || strpos($route, '/wp/v2/') === 0;
    }

    public function link($link, $post) {
        if (!is_string($link) || !$this->link_kontext()) {
            return $link;
        }
        $id = is_object($post) ? (int) $post->ID : (int) $post;
        if ($id <= 0 || get_post_status($id) !== 'publish') {
            return $link; // Entwürfe: CMS-Vorschau bleibt
        }
        $frontend = $this->frontend_url();
        $feld = $this->wert('pfadFeld');
        if ($feld !== '') {
            $eigen = get_post_meta($id, $feld, true);
            if ($this->pfad_gueltig($eigen)) {
                return $frontend . $eigen;
            }
        }
        $home = untrailingslashit(home_url());
        if (strpos($link, $home) === 0) {
            return $frontend . substr($link, strlen($home));
        }
        return $link;
    }

    /** Vorschau veröffentlichter Dokumente bleibt im CMS (?p=/page_id= + preview, wird nicht umgeleitet). */
    public function vorschau_link($link, $post) {
        $frontend = $this->frontend_url();
        if (!is_string($link) || $frontend === '' || strpos($link, $frontend) !== 0 || !($post instanceof WP_Post)) {
            return $link;
        }
        $abfrage = [];
        $q = wp_parse_url($link, PHP_URL_QUERY);
        if (is_string($q) && $q !== '') {
            wp_parse_str($q, $abfrage);
        }
        if ($post->post_type === 'page') {
            $abfrage['page_id'] = $post->ID;
        } else {
            $abfrage['p'] = $post->ID;
            if ($post->post_type !== 'post') {
                $abfrage['post_type'] = $post->post_type;
            }
        }
        return add_query_arg($abfrage, home_url('/'));
    }

    /** Links aus dem CMS aufs Frontend in neuem Tab — der Weg zurück bleibt offen. */
    public function leiste_neuer_tab($leiste): void {
        if (!is_object($leiste) || !method_exists($leiste, 'get_node')) {
            return;
        }
        foreach (['site-name', 'view-site', 'view', 'preview'] as $id) {
            $knoten = $leiste->get_node($id);
            if (!$knoten) {
                continue;
            }
            $knoten->meta = array_merge((array) $knoten->meta, ['target' => '_blank', 'rel' => 'noopener']);
            $leiste->add_node((array) $knoten);
        }
    }

    public function zeile_neuer_tab($aktionen) {
        if (is_array($aktionen) && isset($aktionen['view']) && is_string($aktionen['view']) && strpos($aktionen['view'], 'target=') === false) {
            $aktionen['view'] = str_replace('<a ', '<a target="_blank" rel="noopener" ', $aktionen['view']);
        }
        return $aktionen;
    }

    public function robots($robots) {
        if (!is_array($robots)) {
            $robots = [];
        }
        unset($robots['index'], $robots['follow']);
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        return $robots;
    }

    public function robots_kopf(): void {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }
    }

    /* ------------------------------------------------------------ Build: Auslöser */

    public function hook_art(): string {
        $art = $this->wert('hookArt');
        return in_array($art, ['webhook', 'github', 'datei'], true) ? $art : 'webhook';
    }

    /** Geprüftes Ziel des Build-Hooks ('' = keins/ungültig). Profilwerte werden hier geprüft, lokale schon beim Speichern. */
    public function bau_ziel(): string {
        $ziel = trim($this->wert('buildHook'));
        if ($ziel === '') {
            return '';
        }
        if ($this->hook_art() === 'datei') {
            return ($ziel[0] === '/' && strpos($ziel, '..') === false && preg_match('#^/[A-Za-z0-9._/-]+$#', $ziel)) ? untrailingslashit($ziel) : '';
        }
        return calluna_index_url_pruefen($ziel);
    }

    private function entprellung(): int {
        $s = (int) $this->wert('entprellung');
        return $s < 5 ? 30 : min(900, $s);
    }

    private function inhaltstypen(): array {
        $roh = trim($this->wert('inhaltstypen'));
        if ($roh !== '') {
            $typen = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $roh))));
        } else {
            $typen = array_diff(array_values(get_post_types(['public' => true])), ['attachment']);
            $typen[] = 'wp_block';
            $typen[] = 'wp_navigation';
        }
        $typen = apply_filters('calluna_index_headless_inhaltstypen', array_values(array_unique($typen)));
        return is_array($typen) ? $typen : [];
    }

    public function beitrag_status($neu, $alt, $post): void {
        if (!($post instanceof WP_Post) || !in_array($post->post_type, $this->inhaltstypen(), true)) {
            return;
        }
        // Nur was öffentlich war oder wird; Entwürfe ändern die Seite nicht.
        if ($neu !== 'publish' && $alt !== 'publish') {
            return;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return;
        }
        $this->vormerken(sprintf('%s %d %s→%s', $post->post_type, $post->ID, (string) $alt, (string) $neu));
    }

    /** Endgültiges Löschen ohne Papierkorb (z. B. REST force=true) eines veröffentlichten Dokuments. */
    public function beitrag_loeschen($post_id, $post = null): void {
        if (!($post instanceof WP_Post)) {
            $post = get_post((int) $post_id);
        }
        if ($post instanceof WP_Post && $post->post_status === 'publish' && in_array($post->post_type, $this->inhaltstypen(), true)) {
            $this->vormerken(sprintf('%s %d gelöscht', $post->post_type, $post->ID));
        }
    }

    public function begriff($term_id, $tt_id = 0, $taxonomy = ''): void {
        $taxonomy = is_string($taxonomy) ? $taxonomy : '';
        if ($taxonomy !== 'nav_menu') {
            $tax = $taxonomy !== '' ? get_taxonomy($taxonomy) : false;
            if (!$tax || empty($tax->public)) {
                return;
            }
        }
        $this->vormerken('Begriff ' . $taxonomy . ' ' . (int) $term_id);
    }

    public function menue($menu_id): void {
        $this->vormerken('Menü ' . (int) $menu_id);
    }

    /**
     * Schreibzugriffe der Content Pipe auf veröffentlichte Beiträge, die kein
     * transition_post_status auslösen (z. B. nur SEO-Felder). Ändert die
     * REST-Antwort nicht.
     */
    public function rest_nachher($antwort, $handler, $anfrage) {
        if (!($anfrage instanceof WP_REST_Request) || $anfrage->get_method() === 'GET' || is_wp_error($antwort)) {
            return $antwort;
        }
        if ($antwort instanceof WP_REST_Response && $antwort->get_status() >= 400) {
            return $antwort;
        }
        $route = (string) $anfrage->get_route();
        if (preg_match('#^/calluna/v1/(?:posts|hreflang)/(\d+)$#', $route, $treffer)) {
            if (get_post_status((int) $treffer[1]) === 'publish') {
                $this->vormerken('Content Pipe ' . $route);
            }
        } elseif (strpos($route, '/calluna/v1/terms/') === 0) {
            $this->vormerken('Content Pipe ' . $route);
        }
        return $antwort;
    }

    public function profil_geaendert($profil, $alte_revision): void {
        if ($alte_revision === null) {
            return; // erster Abruf: nichts hat sich „geändert"
        }
        $neu = is_array($profil) && isset($profil['revision']) && is_scalar($profil['revision']) ? (string) $profil['revision'] : '?';
        $this->vormerken('Profil-Revision ' . $neu);
    }

    /**
     * Entprellen: Ist keine Sperre aktiv, wird am Ende dieser Anfrage gebaut und
     * die Sperre gesetzt. Sonst wird ein Nachlauf vorgemerkt, der nach Ablauf
     * der Sperre genau einmal baut — so geht die letzte Änderung nie verloren.
     */
    public function vormerken(string $grund): void {
        if ($this->vorgemerkt || (defined('WP_IMPORTING') && WP_IMPORTING)) {
            return;
        }
        $this->vorgemerkt = true;
        $sekunden = $this->entprellung();
        if (get_transient(self::SPERRE)) {
            update_option(self::OFFEN, $grund, false);
            if (!wp_next_scheduled(self::NACHLAUF)) {
                wp_schedule_single_event(time() + $sekunden + 1, self::NACHLAUF);
            }
            return;
        }
        set_transient(self::SPERRE, time(), $sekunden);
        $this->grund = $grund;
        add_action('shutdown', [$this, 'am_ende']);
    }

    public function am_ende(): void {
        $this->ausloesen($this->grund);
    }

    public function nachlauf(): void {
        $grund = get_option(self::OFFEN, '');
        if (!is_string($grund) || $grund === '') {
            return;
        }
        $sekunden = $this->entprellung();
        if (get_transient(self::SPERRE)) {
            // Inzwischen wurde wieder gebaut (z. B. Knopf) → nach der Sperre erneut versuchen.
            if (!wp_next_scheduled(self::NACHLAUF)) {
                wp_schedule_single_event(time() + $sekunden + 1, self::NACHLAUF);
            }
            return;
        }
        delete_option(self::OFFEN);
        set_transient(self::SPERRE, time(), $sekunden);
        $this->ausloesen('Nachlauf: ' . $grund);
    }

    /* ------------------------------------------------------------ Build: Senden */

    /**
     * Löst den Build aus und speichert das Ergebnis.
     *
     * @return array{zeit:int, grund:string, art:string, ok:bool, code:string, antwort:string}
     */
    public function ausloesen(string $grund): array {
        $art = $this->hook_art();
        $ziel = $this->bau_ziel();
        $stand = [
            'zeit'    => time(),
            'grund'   => substr($grund, 0, 200),
            'art'     => $art,
            'ok'      => false,
            'code'    => '',
            'antwort' => '',
        ];
        if ($ziel === '') {
            $stand['antwort'] = 'Kein gültiger Build-Hook eingestellt.';
            update_option(self::STAND, $stand, false);
            return $stand;
        }
        try {
            if ($art === 'datei') {
                if (!is_dir($ziel) || !is_writable($ziel)) {
                    $stand['antwort'] = 'Auftragsverzeichnis fehlt oder ist nicht beschreibbar.';
                } elseif (file_put_contents($ziel . '/auftrag', gmdate('c') . ' ' . $stand['grund'] . "\n") === false) {
                    $stand['antwort'] = 'Auftrag konnte nicht geschrieben werden.';
                } else {
                    $stand['ok'] = true;
                    $stand['antwort'] = 'Auftrag abgelegt.';
                }
            } else {
                $stand = array_merge($stand, $this->senden($art, $ziel, $stand['grund']));
            }
        } catch (\Throwable $fehler) {
            $stand['antwort'] = 'Fehler: ' . $fehler->getMessage();
        }
        update_option(self::STAND, $stand, false);
        do_action('calluna_index_bau_ausgeloest', $stand);
        return $stand;
    }

    private function senden(string $art, string $ziel, string $grund): array {
        $kopf = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'CallunaCompanion/' . (defined('CALLUNA_COMPANION_VERSION') ? CALLUNA_COMPANION_VERSION : '0') . '; ' . home_url('/'),
        ];
        $token = $this->wert('hookToken');
        if ($token !== '') {
            $kopf['Authorization'] = 'Bearer ' . $token;
        }
        $nutzlast = [
            'quelle'    => 'calluna-index',
            'siteId'    => calluna_index_site_id() !== '' ? calluna_index_site_id() : null,
            'cms'       => home_url('/'),
            'grund'     => $grund,
            'zeitpunkt' => gmdate('c'),
        ];
        if ($art === 'github') {
            $kopf['Accept'] = 'application/vnd.github+json';
            $kopf['X-GitHub-Api-Version'] = '2022-11-28';
            $ereignis = $this->wert('githubEreignis');
            $koerper = [
                'event_type'     => substr($ereignis !== '' ? $ereignis : 'cms-publish', 0, 100),
                'client_payload' => $nutzlast,
            ];
        } else {
            $koerper = $nutzlast;
        }
        $antwort = wp_remote_post($ziel, [
            'timeout'     => 8,
            'redirection' => 0,
            'headers'     => $kopf,
            'body'        => wp_json_encode($koerper),
            'data_format' => 'body',
        ]);
        if (is_wp_error($antwort)) {
            return ['ok' => false, 'code' => '', 'antwort' => 'Nicht erreichbar: ' . $antwort->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($antwort);
        return [
            'ok'      => $code >= 200 && $code < 300,
            'code'    => (string) $code,
            'antwort' => $this->antwort_kurz((string) wp_remote_retrieve_body($antwort)),
        ];
    }

    /** Status aus der Hook-Antwort, falls sie einen liefert (JSON-Felder oder kurzer Text). */
    private function antwort_kurz(string $koerper): string {
        $koerper = trim($koerper);
        if ($koerper === '') {
            return '';
        }
        $json = json_decode($koerper, true);
        if (is_array($json)) {
            $teile = [];
            foreach (['stand', 'status', 'state', 'message', 'nachricht', 'id', 'url', 'deploy_url', 'log_url'] as $k) {
                if (isset($json[$k]) && is_scalar($json[$k]) && (string) $json[$k] !== '') {
                    $teile[] = $k . ': ' . (string) $json[$k];
                }
            }
            return substr(implode(' · ', $teile), 0, 300);
        }
        return substr(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($koerper))), 0, 300);
    }

    /* ------------------------------------------------------------ Build: Anzeige */

    public function letzter_stand(): ?array {
        $s = get_option(self::STAND, null);
        return is_array($s) && isset($s['zeit']) ? $s : null;
    }

    /** stand.txt des Baudienstes (nur Hook-Art „datei"). */
    private function datei_stand(): string {
        if ($this->hook_art() !== 'datei') {
            return '';
        }
        $ziel = $this->bau_ziel();
        $datei = $ziel !== '' ? $ziel . '/stand.txt' : '';
        if ($datei === '' || !is_readable($datei)) {
            return '';
        }
        $inhalt = file_get_contents($datei, false, null, 0, 1024);
        return is_string($inhalt) ? trim($inhalt) : '';
    }

    public function stand_text(): string {
        $s = $this->letzter_stand();
        if ($s === null) {
            $text = 'Noch kein Build ausgelöst.';
        } else {
            $text = ($s['ok'] ? 'Build angestoßen ' : 'Build fehlgeschlagen ')
                . wp_date('d.m. H:i', (int) $s['zeit'])
                . ' (' . (string) $s['grund'] . ')';
            if ((string) $s['code'] !== '') {
                $text .= ' · HTTP ' . (string) $s['code'];
            }
            if ((string) $s['antwort'] !== '') {
                $text .= ' · ' . (string) $s['antwort'];
            }
        }
        $datei = $this->datei_stand();
        if ($datei !== '') {
            $text .= ' · Letzter Bau: ' . $datei;
        }
        return $text;
    }

    public function leiste_knopf($leiste): void {
        if (!current_user_can('edit_pages') || !is_object($leiste) || !method_exists($leiste, 'add_node')) {
            return;
        }
        $leiste->add_node([
            'id'    => 'calluna-index-bauen',
            'title' => '↻ Seite neu bauen',
            'href'  => wp_nonce_url(admin_url('admin-post.php?action=calluna_index_neu_bauen'), 'calluna_index_neu_bauen'),
            'meta'  => ['title' => $this->stand_text()],
        ]);
    }

    public function knopf(): void {
        if (!current_user_can('edit_pages')) {
            wp_die('Keine Berechtigung.', 403);
        }
        check_admin_referer('calluna_index_neu_bauen');
        set_transient(self::SPERRE, time(), $this->entprellung());
        $nutzer = wp_get_current_user();
        $this->ausloesen('Knopf' . ($nutzer && $nutzer->user_login ? ' (' . $nutzer->user_login . ')' : ''));
        set_transient('calluna_index_bau_meldung_' . get_current_user_id(), 1, 120);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    /** Status nach dem Knopf (überall) bzw. letzter Build der letzten 24 h (Dashboard, Listen). */
    public function bau_hinweis(): void {
        if (!current_user_can('edit_pages')) {
            return;
        }
        $s = $this->letzter_stand();
        $schluessel = 'calluna_index_bau_meldung_' . get_current_user_id();
        $nach_knopf = (bool) get_transient($schluessel);
        if ($nach_knopf) {
            delete_transient($schluessel);
        } else {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $id = $screen ? (string) $screen->id : '';
            if (!in_array($id, ['dashboard', 'edit-post', 'edit-page'], true) || $s === null || (int) $s['zeit'] < time() - DAY_IN_SECONDS) {
                return;
            }
        }
        $typ = ($s !== null && !$s['ok']) ? 'error' : 'info';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($typ), esc_html($this->stand_text()));
    }

    /* ------------------------------------------------------------ Einstellungsseite */

    public function hinweise(): array {
        $h = [];
        if ($this->wert('umleiten') === '1' && $this->fremde_umleitung() === null && $this->frontend_url() === '') {
            $h[] = ['typ' => 'warning', 'text' => 'Keine gültige Frontend-Adresse (https, anderer Host als das CMS) — es wird nicht umgeleitet.'];
        }
        if (($fremd = $this->fremde_umleitung()) !== null) {
            $h[] = ['typ' => 'info', 'text' => 'Die Umleitung erledigt bereits ' . $fremd . ' — dieser Teil des Moduls bleibt aus.'];
        }
        if (($fremd = $this->fremder_bau()) !== null) {
            $h[] = ['typ' => 'info', 'text' => 'Das Ausspielen erledigt bereits ' . $fremd . ' — der Build-Auslöser dieses Moduls bleibt aus.'];
        } elseif ($this->bau_ziel() === '') {
            $h[] = ['typ' => 'info', 'text' => 'Kein (gültiger) Build-Hook — Änderungen lösen keinen Build aus.'];
        } elseif ($this->hook_art() === 'github' && $this->wert('hookToken') === '') {
            $h[] = ['typ' => 'warning', 'text' => 'Hook-Art GitHub ohne Hook-Token — GitHub lehnt den Dispatch ab.'];
        }
        return $h;
    }

    private function ziel_maskiert(string $ziel): string {
        if ($ziel === '') {
            return '—';
        }
        if ($ziel[0] === '/') {
            return $ziel;
        }
        $teile = wp_parse_url($ziel);
        $pfad = isset($teile['path']) ? (string) $teile['path'] : '';
        // Pfade von Deploy-Hooks enthalten oft das Geheimnis → nur Anfang zeigen.
        return ($teile['scheme'] ?? 'https') . '://' . ($teile['host'] ?? '') . (strlen($pfad) > 12 ? substr($pfad, 0, 12) . '…' : $pfad);
    }

    public function status_html(): string {
        $zeilen = [];
        $frontend = $this->frontend_url();
        $zeilen[] = 'Frontend: ' . ($frontend !== '' ? '<code>' . esc_html($frontend) . '</code>' : '—')
            . ' · Umleitung: ' . esc_html($this->umleitung_an() ? 'an' : 'aus');
        $zeilen[] = 'Build-Hook: <code>' . esc_html($this->ziel_maskiert($this->bau_ziel())) . '</code> (' . esc_html($this->hook_art()) . ')'
            . ' · Auslöser: ' . esc_html($this->bau_an() ? 'an' : 'aus');
        if ($this->ist_aktiv()) {
            $zeilen[] = 'Letzter Build: ' . esc_html($this->stand_text());
        }
        if (function_exists('get_mu_plugins')) {
            $namen = [];
            foreach (get_mu_plugins() as $datei => $daten) {
                $namen[] = (isset($daten['Name']) && $daten['Name'] !== '' ? $daten['Name'] : $datei);
            }
            if ($namen) {
                $zeilen[] = '<span class="description">mu-plugins auf dieser Seite: ' . esc_html(implode(', ', $namen)) . '</span>';
            }
        }
        foreach ($this->ist_aktiv() ? $this->hinweise() : [] as $h) {
            $zeilen[] = '<span style="color:' . ($h['typ'] === 'warning' ? '#996800' : '#2271b1') . ';">' . esc_html($h['text']) . '</span>';
        }
        return implode('<br>', $zeilen);
    }
}
