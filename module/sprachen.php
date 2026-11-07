<?php
/**
 * Modul „sprachen" — Mehrsprachigkeit ohne WPML/Polylang (für Headless-Seiten).
 *
 * Datenmodell: je Dokument und je Begriff zwei Meta-Felder
 *   _calluna_lang    Sprachcode (z. B. de, en, es, fr)
 *   _calluna_gruppe  Übersetzungsgruppe (alle Sprachfassungen teilen sie)
 * Ohne Sprache gilt ein Dokument als Standardsprache.
 *
 * Was das Modul tut:
 *  - REST-Feld `calluna_sprache` an Dokumenten und Begriffen: { lang, gruppe, uebersetzungen }
 *    (schreibbar: lang, gruppe, verknuepfen_mit).
 *  - REST-Filter `?calluna_lang=xx` für Dokument- und Begriffslisten.
 *  - Gleiche Slugs in verschiedenen Sprachen erlaubt (/en/contact/ und /fr/contact/).
 *  - Links mit Sprachpräfix (/en/…); Standardsprache ohne Präfix (einstellbar).
 *  - Editor: Seitenleiste „Sprache" mit Übersetzungen und „Übersetzung anlegen".
 *  - Listen im Admin: Spalte und Filter „Sprache"; Begriffe: Sprachauswahl.
 *  - POST /calluna/v1/sprachen/uebersetzung  — Übersetzung als Entwurf anlegen.
 *  - POST /calluna/v1/sprachen/uebernehmen   — Zuordnung aus WPML übernehmen (Tabelle icl_translations).
 *  - Content Pipe: /i18n/link und /i18n/overview nutzen die Strategie „calluna" (siehe Kern).
 *
 * Das Frontend (Astro) baut Sprachrouting, Sprachumschalter und hreflang aus diesen Daten.
 * WordPress selbst liefert keine Seiten unter /en/… aus (headless).
 */

if (!defined('ABSPATH')) {
    exit;
}

const CALLUNA_SPRACHEN_META_LANG   = '_calluna_lang';
const CALLUNA_SPRACHEN_META_GRUPPE = '_calluna_gruppe';

/** Das Modul, wenn es läuft — sonst null. Für den Kern (i18n-Endpunkte, /info). */
function calluna_sprachen(): ?Calluna_Index_Modul_Sprachen {
    if (!function_exists('calluna_index_module')) {
        return null;
    }
    $module = calluna_index_module();
    $m = isset($module['sprachen']) ? $module['sprachen'] : null;
    return ($m instanceof Calluna_Index_Modul_Sprachen && $m->ist_aktiv() && $m->blockiert() === null) ? $m : null;
}

/** Neue, zufällige Gruppenkennung. */
function calluna_sprachen_neue_gruppe(): string {
    return 'g' . substr(md5(uniqid((string) wp_rand(), true)), 0, 12);
}

class Calluna_Index_Modul_Sprachen extends Calluna_Index_Modul {

    /** @var string Sprach-Hinweis für die Slug-Prüfung, solange ein Dokument noch keine Meta hat. */
    private $sprach_hinweis = '';

    public function kennung(): string {
        return 'sprachen';
    }

    public function titel(): string {
        return 'Sprachen';
    }

    public function beschreibung(): string {
        return 'Mehrsprachigkeit ohne WPML/Polylang: Sprache und Übersetzungsgruppe je Dokument und Begriff, Übersetzungen im Editor, Daten für Sprachrouting und hreflang im Frontend.';
    }

    public function blockiert(): ?string {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return 'WPML ist noch aktiv. Erst die Zuordnung übernehmen (POST /calluna/v1/sprachen/uebernehmen), dann WPML abschalten.';
        }
        if (function_exists('pll_current_language') || defined('POLYLANG_VERSION')) {
            return 'Polylang ist aktiv. Beide gleichzeitig würden sich widersprechen.';
        }
        return null;
    }

    public function felder(): array {
        return [
            'sprachen' => [
                'typ'         => 'text',
                'titel'       => 'Sprachen',
                'hilfe'       => 'Sprachcodes, mit Komma getrennt, z. B. <code>de,en,es,fr</code>. Die erste ist die Standardsprache, wenn unten nichts steht.',
                'profil'      => 'sprachen',
                'standard'    => '',
                'oeffentlich' => true,
            ],
            'standard' => [
                'typ'         => 'text',
                'titel'       => 'Standardsprache',
                'hilfe'       => 'Leer = erste der Liste. Dokumente ohne Sprache gelten als Standardsprache.',
                'profil'      => 'standard',
                'standard'    => '',
                'oeffentlich' => true,
            ],
            'praefix_standard' => [
                'typ'          => 'checkbox',
                'titel'        => 'Präfix für die Standardsprache',
                'beschriftung' => 'Auch die Standardsprache bekommt ein Präfix (/de/…)',
                'profil'       => 'praefixStandard',
                'standard'     => '0',
                'oeffentlich'  => true,
            ],
            'inhaltstypen' => [
                'typ'      => 'text',
                'titel'    => 'Inhaltstypen',
                'hilfe'    => 'Mehrsprachige Inhaltstypen, mit Komma getrennt. Standard: <code>post,page</code>.',
                'standard' => 'post,page',
            ],
        ];
    }

    public function build_konfig(): array {
        return [
            'sprachen'        => $this->liste(),
            'standard'        => $this->standard(),
            'praefixStandard' => $this->wert('praefix_standard') === '1',
        ];
    }

    /* ------------------------------------------------------------ Grundlagen */

    /** @return string[] Sprachcodes in Reihenfolge. */
    public function liste(): array {
        $roh = strtolower($this->wert('sprachen'));
        $aus = [];
        foreach (preg_split('/[\s,;]+/', $roh) ?: [] as $code) {
            if ($this->code_gueltig($code) && !in_array($code, $aus, true)) {
                $aus[] = $code;
            }
        }
        return $aus;
    }

    public function standard(): string {
        $s = strtolower(trim($this->wert('standard')));
        $liste = $this->liste();
        if ($this->code_gueltig($s)) {
            return $s;
        }
        return $liste ? $liste[0] : 'de';
    }

    public function code_gueltig($code): bool {
        return is_string($code) && (bool) preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code);
    }

    /** @return string[] */
    public function inhaltstypen(): array {
        $typen = [];
        foreach (preg_split('/[\s,;]+/', (string) $this->wert('inhaltstypen')) ?: [] as $t) {
            $t = sanitize_key($t);
            if ($t !== '' && post_type_exists($t)) {
                $typen[] = $t;
            }
        }
        return $typen ?: ['post', 'page'];
    }

    /** @return string[] Taxonomien der mehrsprachigen Inhaltstypen (öffentlich, mit REST). */
    public function taxonomien(): array {
        $aus = [];
        foreach ($this->inhaltstypen() as $typ) {
            foreach (get_object_taxonomies($typ, 'objects') as $tax) {
                if ($tax->public && $tax->show_in_rest && $tax->name !== 'post_format') {
                    $aus[$tax->name] = $tax->name;
                }
            }
        }
        return array_values($aus);
    }

    public function sprache_von(int $post_id): string {
        $l = get_post_meta($post_id, CALLUNA_SPRACHEN_META_LANG, true);
        return $this->code_gueltig($l) ? $l : $this->standard();
    }

    public function gruppe_von(int $post_id): string {
        $g = get_post_meta($post_id, CALLUNA_SPRACHEN_META_GRUPPE, true);
        return is_string($g) ? $g : '';
    }

    public function begriff_sprache(int $term_id): string {
        $l = get_term_meta($term_id, CALLUNA_SPRACHEN_META_LANG, true);
        return $this->code_gueltig($l) ? $l : $this->standard();
    }

    public function begriff_gruppe(int $term_id): string {
        $g = get_term_meta($term_id, CALLUNA_SPRACHEN_META_GRUPPE, true);
        return is_string($g) ? $g : '';
    }

    /** Sprache (und Gruppe) eines Dokuments setzen. Ohne Gruppe bekommt es eine eigene. */
    public function setzen(int $post_id, string $lang, string $gruppe = ''): void {
        if (!$this->code_gueltig($lang)) {
            return;
        }
        update_post_meta($post_id, CALLUNA_SPRACHEN_META_LANG, $lang);
        if ($gruppe === '') {
            $gruppe = $this->gruppe_von($post_id) ?: calluna_sprachen_neue_gruppe();
        }
        update_post_meta($post_id, CALLUNA_SPRACHEN_META_GRUPPE, sanitize_key($gruppe));
    }

    public function begriff_setzen(int $term_id, string $lang, string $gruppe = ''): void {
        if (!$this->code_gueltig($lang)) {
            return;
        }
        update_term_meta($term_id, CALLUNA_SPRACHEN_META_LANG, $lang);
        if ($gruppe === '') {
            $gruppe = $this->begriff_gruppe($term_id) ?: calluna_sprachen_neue_gruppe();
        }
        update_term_meta($term_id, CALLUNA_SPRACHEN_META_GRUPPE, sanitize_key($gruppe));
    }

    /**
     * Dokumente zu einer Gruppe verknüpfen: [lang => post_id]. Die Gruppe des
     * Quelldokuments bleibt, sonst die erste vorhandene, sonst eine neue.
     */
    public function verknuepfen(array $map, string $quelle_lang = ''): string {
        $gruppe = '';
        if ($quelle_lang !== '' && isset($map[$quelle_lang])) {
            $gruppe = $this->gruppe_von((int) $map[$quelle_lang]);
        }
        if ($gruppe === '') {
            foreach ($map as $pid) {
                $gruppe = $this->gruppe_von((int) $pid);
                if ($gruppe !== '') {
                    break;
                }
            }
        }
        if ($gruppe === '') {
            $gruppe = calluna_sprachen_neue_gruppe();
        }
        foreach ($map as $lang => $pid) {
            $this->setzen((int) $pid, (string) $lang, $gruppe);
        }
        return $gruppe;
    }

    /** @return array<string,int> lang => post_id aller Fassungen (inkl. des Dokuments selbst). */
    public function uebersetzungen(int $post_id): array {
        $gruppe = $this->gruppe_von($post_id);
        $aus = [$this->sprache_von($post_id) => $post_id];
        if ($gruppe === '') {
            return $aus;
        }
        $ids = get_posts([
            'post_type'        => get_post_type($post_id) ?: 'any',
            'post_status'      => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page'   => 50,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_key'         => CALLUNA_SPRACHEN_META_GRUPPE,
            'meta_value'       => $gruppe,
        ]);
        foreach ($ids as $id) {
            $l = $this->sprache_von((int) $id);
            if (!isset($aus[$l]) || (int) $id === $post_id) {
                $aus[$l] = (int) $id;
            }
        }
        return $aus;
    }

    /** @return array<string,int> lang => term_id */
    public function begriff_uebersetzungen(int $term_id): array {
        $term = get_term($term_id);
        $aus = [$this->begriff_sprache($term_id) => $term_id];
        $gruppe = $this->begriff_gruppe($term_id);
        if ($gruppe === '' || !$term instanceof WP_Term) {
            return $aus;
        }
        $ids = get_terms([
            'taxonomy'   => $term->taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_key'   => CALLUNA_SPRACHEN_META_GRUPPE,
            'meta_value' => $gruppe,
        ]);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $l = $this->begriff_sprache((int) $id);
                if (!isset($aus[$l])) {
                    $aus[$l] = (int) $id;
                }
            }
        }
        return $aus;
    }

    /* ------------------------------------------------------------ Hooks */

    public function laden(): void {
        add_action('init', [$this, 'meta_registrieren'], 20);
        add_action('rest_api_init', [$this, 'rest_registrieren']);

        add_filter('wp_unique_post_slug', [$this, 'slug_je_sprache'], 10, 6);
        foreach (['post_link', 'page_link', 'post_type_link'] as $filter) {
            add_filter($filter, [$this, 'link_mit_praefix'], 20, 2);
        }
        add_filter('term_link', [$this, 'begriff_link_mit_praefix'], 20, 3);

        if (is_admin()) {
            add_action('admin_init', [$this, 'admin_registrieren']);
            add_action('enqueue_block_editor_assets', [$this, 'editor_skript']);
        }
    }

    public function meta_registrieren(): void {
        $darf = function () {
            return current_user_can('edit_posts');
        };
        foreach ($this->inhaltstypen() as $typ) {
            foreach ([CALLUNA_SPRACHEN_META_LANG, CALLUNA_SPRACHEN_META_GRUPPE] as $key) {
                register_post_meta($typ, $key, [
                    'type'          => 'string',
                    'single'        => true,
                    'show_in_rest'  => false,
                    'auth_callback' => $darf,
                ]);
            }
        }
    }

    /* ------------------------------------------------------------ REST */

    public function rest_registrieren(): void {
        $schema = [
            'description' => 'Sprache und Übersetzungen (Calluna Index, Modul sprachen)',
            'type'        => 'object',
            'context'     => ['view', 'edit', 'embed'],
            'properties'  => [
                'lang'            => ['type' => 'string'],
                'gruppe'          => ['type' => 'string'],
                'verknuepfen_mit' => ['type' => 'integer', 'description' => 'Nur schreiben: in die Gruppe dieses Dokuments/Begriffs aufnehmen'],
                'uebersetzungen'  => ['type' => 'object', 'readonly' => true],
            ],
        ];
        foreach ($this->inhaltstypen() as $typ) {
            register_rest_field($typ, 'calluna_sprache', [
                'get_callback'    => [$this, 'rest_feld_dokument'],
                'update_callback' => [$this, 'rest_feld_dokument_schreiben'],
                'schema'          => $schema,
            ]);
            add_filter('rest_' . $typ . '_collection_params', [$this, 'rest_param']);
            add_filter('rest_' . $typ . '_query', [$this, 'rest_abfrage_dokumente'], 10, 2);
            add_filter('rest_pre_insert_' . $typ, [$this, 'rest_hinweis_merken'], 10, 2);
        }
        foreach ($this->taxonomien() as $tax) {
            register_rest_field($tax, 'calluna_sprache', [
                'get_callback'    => [$this, 'rest_feld_begriff'],
                'update_callback' => [$this, 'rest_feld_begriff_schreiben'],
                'schema'          => $schema,
            ]);
            add_filter('rest_' . $tax . '_collection_params', [$this, 'rest_param']);
            add_filter('rest_' . $tax . '_query', [$this, 'rest_abfrage_begriffe'], 10, 2);
        }

        register_rest_route('calluna/v1', '/sprachen/uebersetzung', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_uebersetzung_anlegen'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args'                => [
                'quelle' => ['type' => 'integer', 'required' => true],
                'lang'   => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function rest_param($params) {
        $params['calluna_lang'] = [
            'description' => 'Nur Einträge dieser Sprache (Standardsprache schließt Einträge ohne Sprache ein).',
            'type'        => 'string',
        ];
        return $params;
    }

    private function meta_bedingung(string $lang): array {
        if ($lang === $this->standard()) {
            return [
                'relation' => 'OR',
                ['key' => CALLUNA_SPRACHEN_META_LANG, 'value' => $lang],
                ['key' => CALLUNA_SPRACHEN_META_LANG, 'compare' => 'NOT EXISTS'],
            ];
        }
        return [['key' => CALLUNA_SPRACHEN_META_LANG, 'value' => $lang]];
    }

    public function rest_abfrage_dokumente($args, $anfrage) {
        $lang = strtolower((string) $anfrage->get_param('calluna_lang'));
        if ($this->code_gueltig($lang)) {
            $bisher = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
            $args['meta_query'] = array_merge($bisher, [$this->meta_bedingung($lang)]);
        }
        return $args;
    }

    public function rest_abfrage_begriffe($args, $anfrage) {
        $lang = strtolower((string) $anfrage->get_param('calluna_lang'));
        if ($this->code_gueltig($lang)) {
            $bisher = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
            $args['meta_query'] = array_merge($bisher, [$this->meta_bedingung($lang)]);
        }
        return $args;
    }

    /** Sprache aus der Anfrage merken, damit die Slug-Prüfung sie schon beim Anlegen kennt. */
    public function rest_hinweis_merken($beitrag, $anfrage) {
        $feld = $anfrage->get_param('calluna_sprache');
        if (is_array($feld) && isset($feld['lang']) && $this->code_gueltig($feld['lang'])) {
            $this->sprach_hinweis = $feld['lang'];
        }
        return $beitrag;
    }

    private function dokument_kurz(int $id): array {
        return [
            'id'     => $id,
            'slug'   => (string) get_post_field('post_name', $id),
            'titel'  => get_the_title($id),
            'status' => (string) get_post_status($id),
            'link'   => (string) get_permalink($id),
        ];
    }

    public function rest_feld_dokument($objekt) {
        $id = (int) $objekt['id'];
        $uebersetzungen = [];
        foreach ($this->uebersetzungen($id) as $lang => $pid) {
            $uebersetzungen[$lang] = $this->dokument_kurz($pid);
        }
        return [
            'lang'           => $this->sprache_von($id),
            'gruppe'         => $this->gruppe_von($id),
            'uebersetzungen' => (object) $uebersetzungen,
        ];
    }

    public function rest_feld_dokument_schreiben($wert, $beitrag) {
        if (!is_array($wert) || !$beitrag instanceof WP_Post) {
            return true;
        }
        if (!current_user_can('edit_post', $beitrag->ID)) {
            return new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
        }
        $lang = isset($wert['lang']) ? strtolower((string) $wert['lang']) : $this->sprache_von($beitrag->ID);
        if (!$this->code_gueltig($lang)) {
            return new WP_Error('rest_invalid_param', 'Ungültiger Sprachcode.', ['status' => 400]);
        }
        $gruppe = isset($wert['gruppe']) ? sanitize_key((string) $wert['gruppe']) : '';
        if (!empty($wert['verknuepfen_mit'])) {
            $andere = (int) $wert['verknuepfen_mit'];
            if (get_post_status($andere) === false) {
                return new WP_Error('rest_invalid_param', 'verknuepfen_mit: Dokument nicht gefunden.', ['status' => 400]);
            }
            $gruppe = $this->gruppe_von($andere);
            if ($gruppe === '') {
                $gruppe = calluna_sprachen_neue_gruppe();
                $this->setzen($andere, $this->sprache_von($andere), $gruppe);
            }
        }
        $this->setzen($beitrag->ID, $lang, $gruppe);
        $this->slug_nachziehen($beitrag->ID);
        return true;
    }

    public function rest_feld_begriff($objekt) {
        $id = (int) $objekt['id'];
        $uebersetzungen = [];
        foreach ($this->begriff_uebersetzungen($id) as $lang => $tid) {
            $t = get_term($tid);
            $uebersetzungen[$lang] = [
                'id'   => $tid,
                'slug' => $t instanceof WP_Term ? $t->slug : '',
                'name' => $t instanceof WP_Term ? $t->name : '',
                'link' => $t instanceof WP_Term ? (string) get_term_link($t) : '',
            ];
        }
        return [
            'lang'           => $this->begriff_sprache($id),
            'gruppe'         => $this->begriff_gruppe($id),
            'uebersetzungen' => (object) $uebersetzungen,
        ];
    }

    public function rest_feld_begriff_schreiben($wert, $term) {
        if (!is_array($wert) || !$term instanceof WP_Term) {
            return true;
        }
        if (!current_user_can('edit_term', $term->term_id)) {
            return new WP_Error('rest_forbidden', 'Keine Berechtigung.', ['status' => 403]);
        }
        $lang = isset($wert['lang']) ? strtolower((string) $wert['lang']) : $this->begriff_sprache($term->term_id);
        if (!$this->code_gueltig($lang)) {
            return new WP_Error('rest_invalid_param', 'Ungültiger Sprachcode.', ['status' => 400]);
        }
        $gruppe = isset($wert['gruppe']) ? sanitize_key((string) $wert['gruppe']) : '';
        if (!empty($wert['verknuepfen_mit'])) {
            $andere = (int) $wert['verknuepfen_mit'];
            $gruppe = $this->begriff_gruppe($andere);
            if ($gruppe === '') {
                $gruppe = calluna_sprachen_neue_gruppe();
                $this->begriff_setzen($andere, $this->begriff_sprache($andere), $gruppe);
            }
        }
        $this->begriff_setzen($term->term_id, $lang, $gruppe);
        return true;
    }

    /**
     * Übersetzung als Entwurf anlegen: Titel, Inhalt, Auszug, Beitragsbild,
     * Seiten-Eltern und Begriffe (in die Zielsprache übersetzt, wenn vorhanden).
     */
    public function rest_uebersetzung_anlegen(WP_REST_Request $anfrage) {
        $quelle = (int) $anfrage->get_param('quelle');
        $lang = strtolower((string) $anfrage->get_param('lang'));
        $post = get_post($quelle);
        if (!$post instanceof WP_Post || !in_array($post->post_type, $this->inhaltstypen(), true)) {
            return new WP_Error('rest_invalid_param', 'Quelle nicht gefunden.', ['status' => 404]);
        }
        if (!current_user_can('edit_post', $quelle) || !in_array($lang, $this->liste(), true)) {
            return new WP_Error('rest_invalid_param', 'Sprache nicht erlaubt oder keine Berechtigung.', ['status' => 400]);
        }
        $vorhanden = $this->uebersetzungen($quelle);
        if (isset($vorhanden[$lang])) {
            return new WP_REST_Response(['id' => $vorhanden[$lang], 'neu' => false, 'bearbeiten' => get_edit_post_link($vorhanden[$lang], 'raw')], 200);
        }

        $gruppe = $this->gruppe_von($quelle);
        if ($gruppe === '') {
            $gruppe = calluna_sprachen_neue_gruppe();
            $this->setzen($quelle, $this->sprache_von($quelle), $gruppe);
        }
        $eltern = 0;
        if ($post->post_parent) {
            $e = $this->uebersetzungen((int) $post->post_parent);
            $eltern = isset($e[$lang]) ? $e[$lang] : 0;
        }

        $this->sprach_hinweis = $lang;
        $neu = wp_insert_post(wp_slash([
            'post_type'    => $post->post_type,
            'post_status'  => 'draft',
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_name'    => $post->post_name,
            'post_parent'  => $eltern,
            'menu_order'   => $post->menu_order,
            'post_author'  => get_current_user_id() ?: $post->post_author,
        ]), true);
        $this->sprach_hinweis = '';
        if (is_wp_error($neu)) {
            return $neu;
        }
        $this->setzen($neu, $lang, $gruppe);

        $bild = get_post_thumbnail_id($quelle);
        if ($bild) {
            set_post_thumbnail($neu, $bild);
        }
        foreach (get_object_taxonomies($post->post_type) as $tax) {
            $ids = wp_get_object_terms($quelle, $tax, ['fields' => 'ids']);
            if (!is_array($ids) || !$ids) {
                continue;
            }
            $ziel = [];
            foreach ($ids as $tid) {
                $u = $this->begriff_uebersetzungen((int) $tid);
                if (isset($u[$lang])) {
                    $ziel[] = $u[$lang];
                }
            }
            if ($ziel) {
                wp_set_object_terms($neu, $ziel, $tax);
            }
        }
        return new WP_REST_Response(['id' => $neu, 'neu' => true, 'bearbeiten' => get_edit_post_link($neu, 'raw')], 201);
    }

    /* ------------------------------------------------------------ Slugs und Links */

    /**
     * WordPress hängt bei gleichem Slug „-2" an. Kollidiert der gewünschte Slug
     * nur mit Dokumenten anderer Sprachen, bleibt er unverändert.
     */
    public function slug_je_sprache($slug, $post_id, $status, $typ, $eltern, $original) {
        if ($slug === $original || $original === '' || !in_array($typ, $this->inhaltstypen(), true)) {
            return $slug;
        }
        global $wpdb, $wp_rewrite;
        if (is_numeric($original) || in_array($original, (array) $wp_rewrite->feeds, true) || $original === 'embed') {
            return $slug;
        }
        $lang = $this->sprach_hinweis;
        if ($lang === '' && $post_id) {
            $lang = $this->sprache_von((int) $post_id);
        }
        if ($lang === '') {
            $lang = $this->standard();
        }
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d AND post_status NOT IN ('trash','auto-draft','inherit')";
        $werte = [$original, $typ, (int) $post_id];
        if (is_post_type_hierarchical($typ)) {
            $sql .= ' AND post_parent = %d';
            $werte[] = (int) $eltern;
        }
        $andere = $wpdb->get_col($wpdb->prepare($sql, $werte)); // phpcs:ignore
        foreach ($andere as $id) {
            if ($this->sprache_von((int) $id) === $lang) {
                return $slug; // echte Kollision in derselben Sprache
            }
        }
        return $original;
    }

    /** Nach Sprachwechsel: „-2" wieder entfernen, wenn der Slug in der Sprache frei ist. */
    private function slug_nachziehen(int $post_id): void {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !preg_match('/^(.+)-\d+$/', $post->post_name, $m)) {
            return;
        }
        $this->sprach_hinweis = $this->sprache_von($post_id);
        $frei = $this->slug_je_sprache($post->post_name, $post_id, $post->post_status, $post->post_type, $post->post_parent, $m[1]);
        $this->sprach_hinweis = '';
        if ($frei === $m[1]) {
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_name' => $frei], ['ID' => $post_id]);
            clean_post_cache($post_id);
        }
    }

    private function praefix(string $lang): string {
        if ($lang === $this->standard() && $this->wert('praefix_standard') !== '1') {
            return '';
        }
        return $lang;
    }

    private function praefix_einsetzen(string $link, string $lang): string {
        $p = $this->praefix($lang);
        if ($p === '') {
            return $link;
        }
        $home = trailingslashit(home_url());
        if (strpos($link, $home) !== 0 || strpos($link, $home . $p . '/') === 0) {
            return $link;
        }
        return $home . $p . '/' . substr($link, strlen($home));
    }

    public function link_mit_praefix($link, $post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post || !in_array($post->post_type, $this->inhaltstypen(), true)) {
            return $link;
        }
        // Entwürfe ohne schönen Link (?p=) bleiben, wie sie sind.
        if (strpos((string) $link, '?') !== false) {
            return $link;
        }
        return $this->praefix_einsetzen((string) $link, $this->sprache_von($post->ID));
    }

    public function begriff_link_mit_praefix($link, $term, $taxonomie) {
        if (!$term instanceof WP_Term || !in_array($taxonomie, $this->taxonomien(), true)) {
            return $link;
        }
        return $this->praefix_einsetzen((string) $link, $this->begriff_sprache($term->term_id));
    }

    /* ------------------------------------------------------------ Admin */

    public function admin_registrieren(): void {
        foreach ($this->inhaltstypen() as $typ) {
            add_filter('manage_' . $typ . '_posts_columns', [$this, 'spalte']);
            add_action('manage_' . $typ . '_posts_custom_column', [$this, 'spalte_inhalt'], 10, 2);
        }
        add_action('restrict_manage_posts', [$this, 'listenfilter'], 10, 1);
        add_action('pre_get_posts', [$this, 'listenfilter_anwenden']);
        foreach ($this->taxonomien() as $tax) {
            add_action($tax . '_add_form_fields', [$this, 'begriff_feld_neu']);
            add_action($tax . '_edit_form_fields', [$this, 'begriff_feld_bearbeiten']);
            add_action('created_' . $tax, [$this, 'begriff_feld_speichern']);
            add_action('edited_' . $tax, [$this, 'begriff_feld_speichern']);
            add_filter('manage_edit-' . $tax . '_columns', [$this, 'spalte']);
            add_filter('manage_' . $tax . '_custom_column', [$this, 'begriff_spalte_inhalt'], 10, 3);
        }
    }

    public function spalte($spalten) {
        $aus = [];
        foreach ($spalten as $k => $v) {
            $aus[$k] = $v;
            if ($k === 'title' || $k === 'name') {
                $aus['calluna_sprache'] = 'Sprache';
            }
        }
        if (!isset($aus['calluna_sprache'])) {
            $aus['calluna_sprache'] = 'Sprache';
        }
        return $aus;
    }

    public function spalte_inhalt($spalte, $post_id): void {
        if ($spalte !== 'calluna_sprache') {
            return;
        }
        $eigene = $this->sprache_von((int) $post_id);
        $teile = ['<strong>' . esc_html(strtoupper($eigene)) . '</strong>'];
        $vorhanden = $this->uebersetzungen((int) $post_id);
        foreach ($this->liste() as $lang) {
            if ($lang === $eigene) {
                continue;
            }
            if (isset($vorhanden[$lang])) {
                $teile[] = '<a href="' . esc_url((string) get_edit_post_link($vorhanden[$lang])) . '" title="Übersetzung bearbeiten">' . esc_html($lang) . '</a>';
            } else {
                $teile[] = '<span style="opacity:.4" title="fehlt">' . esc_html($lang) . '</span>';
            }
        }
        echo implode(' ', $teile); // phpcs:ignore — oben escaped
    }

    public function listenfilter($typ): void {
        if (!in_array($typ, $this->inhaltstypen(), true)) {
            return;
        }
        $aktuell = isset($_GET['calluna_lang']) ? sanitize_key(wp_unslash($_GET['calluna_lang'])) : ''; // phpcs:ignore
        echo '<select name="calluna_lang"><option value="">Alle Sprachen</option>';
        foreach ($this->liste() as $lang) {
            echo '<option value="' . esc_attr($lang) . '"' . selected($aktuell, $lang, false) . '>' . esc_html(strtoupper($lang)) . '</option>';
        }
        echo '</select>';
    }

    public function listenfilter_anwenden($abfrage): void {
        if (!is_admin() || !$abfrage->is_main_query() || empty($_GET['calluna_lang'])) { // phpcs:ignore
            return;
        }
        $lang = sanitize_key(wp_unslash($_GET['calluna_lang'])); // phpcs:ignore
        $typ = $abfrage->get('post_type');
        if (!$this->code_gueltig($lang) || !in_array(is_string($typ) ? $typ : 'post', $this->inhaltstypen(), true)) {
            return;
        }
        $bisher = $abfrage->get('meta_query');
        $abfrage->set('meta_query', array_merge(is_array($bisher) ? $bisher : [], [$this->meta_bedingung($lang)]));
    }

    private function sprach_auswahl(string $aktuell): string {
        $html = '<select name="calluna_lang">';
        foreach ($this->liste() as $lang) {
            $html .= '<option value="' . esc_attr($lang) . '"' . selected($aktuell, $lang, false) . '>' . esc_html(strtoupper($lang)) . '</option>';
        }
        return $html . '</select>';
    }

    public function begriff_feld_neu(): void {
        wp_nonce_field('calluna_sprache_begriff', 'calluna_sprache_nonce');
        echo '<div class="form-field"><label>Sprache</label>' . $this->sprach_auswahl($this->standard()) // phpcs:ignore
            . '<p>Übersetzung von (ID, optional): <input type="number" name="calluna_verknuepfen" style="width:8em"></p></div>';
    }

    public function begriff_feld_bearbeiten($term): void {
        wp_nonce_field('calluna_sprache_begriff', 'calluna_sprache_nonce');
        $u = [];
        foreach ($this->begriff_uebersetzungen($term->term_id) as $lang => $tid) {
            if ($tid !== $term->term_id) {
                $u[] = '<a href="' . esc_url((string) get_edit_term_link($tid, $term->taxonomy)) . '">' . esc_html($lang) . '</a>';
            }
        }
        echo '<tr class="form-field"><th>Sprache</th><td>' . $this->sprach_auswahl($this->begriff_sprache($term->term_id)) // phpcs:ignore
            . ' Übersetzungen: ' . ($u ? implode(' ', $u) : '–') // phpcs:ignore
            . '<p>Verknüpfen mit (ID eines Begriffs, optional): <input type="number" name="calluna_verknuepfen" style="width:8em"></p></td></tr>';
    }

    public function begriff_feld_speichern($term_id): void {
        if (empty($_POST['calluna_sprache_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['calluna_sprache_nonce'])), 'calluna_sprache_begriff')) { // phpcs:ignore
            return;
        }
        if (!current_user_can('edit_term', $term_id)) {
            return;
        }
        $lang = isset($_POST['calluna_lang']) ? sanitize_key(wp_unslash($_POST['calluna_lang'])) : ''; // phpcs:ignore
        $gruppe = '';
        $andere = isset($_POST['calluna_verknuepfen']) ? (int) $_POST['calluna_verknuepfen'] : 0; // phpcs:ignore
        if ($andere > 0 && get_term($andere) instanceof WP_Term) {
            $gruppe = $this->begriff_gruppe($andere);
            if ($gruppe === '') {
                $gruppe = calluna_sprachen_neue_gruppe();
                $this->begriff_setzen($andere, $this->begriff_sprache($andere), $gruppe);
            }
        }
        $this->begriff_setzen((int) $term_id, $lang, $gruppe);
    }

    public function begriff_spalte_inhalt($inhalt, $spalte, $term_id) {
        if ($spalte !== 'calluna_sprache') {
            return $inhalt;
        }
        return esc_html(strtoupper($this->begriff_sprache((int) $term_id)));
    }

    public function editor_skript(): void {
        $bildschirm = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$bildschirm || !in_array($bildschirm->post_type, $this->inhaltstypen(), true)) {
            return;
        }
        $datei = __DIR__ . '/sprachen/editor.js';
        wp_enqueue_script(
            'calluna-sprachen-editor',
            plugins_url('module/sprachen/editor.js', CALLUNA_INDEX_HAUPTDATEI),
            ['wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-core-data'],
            file_exists($datei) ? (string) filemtime($datei) : '1',
            true
        );
        $basen = [];
        foreach ($this->inhaltstypen() as $typ) {
            $o = get_post_type_object($typ);
            $basen[$typ] = $o && $o->rest_base ? $o->rest_base : $typ;
        }
        wp_add_inline_script('calluna-sprachen-editor', 'window.callunaSprachen = ' . wp_json_encode([
            'sprachen' => $this->liste(),
            'standard' => $this->standard(),
            'basen'    => $basen,
        ]) . ';', 'before');
    }

    /* ------------------------------------------------------------ Status */

    public function hinweise(): array {
        if (!$this->liste()) {
            return [[
                'typ'      => 'warning',
                'text'     => 'Modul Sprachen: Es sind noch keine Sprachen eingetragen (Feld „Sprachen" oder Seitenprofil).',
                'ueberall' => false,
            ]];
        }
        return [];
    }

    public function status_html(): string {
        global $wpdb;
        $ohne = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
            "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status IN ('publish','draft','future','pending','private') AND p.post_type IN ('" . implode("','", array_map('esc_sql', $this->inhaltstypen())) . "') AND m.meta_id IS NULL",
            CALLUNA_SPRACHEN_META_LANG
        ));
        $je = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "SELECT meta_value AS l, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY n DESC",
            CALLUNA_SPRACHEN_META_LANG
        ));
        $teile = [];
        foreach ((array) $je as $z) {
            $teile[] = esc_html(strtoupper((string) $z->l) . ' ' . (int) $z->n);
        }
        return 'Sprachen: <code>' . esc_html(implode(', ', $this->liste())) . '</code>, Standard <code>' . esc_html($this->standard()) . '</code>'
            . '<br>Dokumente je Sprache: ' . ($teile ? implode(' · ', $teile) : '–')
            . ' · ohne Sprache (zählen als Standard): ' . esc_html((string) $ohne);
    }
}

/* ------------------------------------------------------------------ Übernahme aus WPML */

/**
 * POST /calluna/v1/sprachen/uebernehmen?trocken=1&ueberschreiben=0
 * Liest {prefix}icl_translations (bleibt nach dem Abschalten von WPML in der
 * Datenbank) und schreibt _calluna_lang und _calluna_gruppe ('wpml-<trid>')
 * an Dokumente und Begriffe. Läuft auch, solange das Modul noch blockiert ist.
 */
add_action('rest_api_init', function () {
    register_rest_route('calluna/v1', '/sprachen/uebernehmen', [
        'methods'             => 'POST',
        'callback'            => 'calluna_sprachen_rest_uebernehmen',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'args'                => [
            'trocken'        => ['type' => 'boolean', 'default' => true],
            'ueberschreiben' => ['type' => 'boolean', 'default' => false],
        ],
    ]);
});

function calluna_sprachen_rest_uebernehmen(WP_REST_Request $anfrage) {
    global $wpdb;
    $tabelle = $wpdb->prefix . 'icl_translations';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) !== $tabelle) { // phpcs:ignore
        return new WP_Error('keine_wpml_daten', 'Tabelle ' . $tabelle . ' nicht gefunden.', ['status' => 404]);
    }
    $trocken = (bool) $anfrage->get_param('trocken');
    $ueber = (bool) $anfrage->get_param('ueberschreiben');
    $zeilen = $wpdb->get_results( // phpcs:ignore
        "SELECT element_id, element_type, trid, language_code FROM {$tabelle} WHERE element_id IS NOT NULL AND (element_type LIKE 'post\\_%' OR element_type LIKE 'tax\\_%')"
    );
    $z = ['dokumente' => 0, 'begriffe' => 0, 'uebersprungen' => 0, 'je_sprache' => []];
    foreach ((array) $zeilen as $r) {
        $lang = strtolower((string) $r->language_code);
        $gruppe = 'wpml-' . (int) $r->trid;
        if (strpos($r->element_type, 'post_') === 0) {
            $id = (int) $r->element_id;
            if (!get_post($id) || (!$ueber && get_post_meta($id, CALLUNA_SPRACHEN_META_LANG, true) !== '')) {
                $z['uebersprungen']++;
                continue;
            }
            if (!$trocken) {
                update_post_meta($id, CALLUNA_SPRACHEN_META_LANG, $lang);
                update_post_meta($id, CALLUNA_SPRACHEN_META_GRUPPE, $gruppe);
            }
            $z['dokumente']++;
        } else {
            // element_id ist bei Begriffen die term_taxonomy_id.
            $term_id = (int) $wpdb->get_var($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", (int) $r->element_id)); // phpcs:ignore
            if (!$term_id || (!$ueber && get_term_meta($term_id, CALLUNA_SPRACHEN_META_LANG, true) !== '')) {
                $z['uebersprungen']++;
                continue;
            }
            if (!$trocken) {
                update_term_meta($term_id, CALLUNA_SPRACHEN_META_LANG, $lang);
                update_term_meta($term_id, CALLUNA_SPRACHEN_META_GRUPPE, $gruppe);
            }
            $z['begriffe']++;
        }
        $z['je_sprache'][$lang] = (isset($z['je_sprache'][$lang]) ? $z['je_sprache'][$lang] : 0) + 1;
    }
    $z['trocken'] = $trocken;
    return new WP_REST_Response($z, 200);
}
