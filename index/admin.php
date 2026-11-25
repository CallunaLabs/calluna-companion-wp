<?php
/**
 * Calluna Companion — Einstellungsseite (Einstellungen → Calluna Companion).
 *
 * Slug `calluna-companion` (wie bisher). Zwei Reiter:
 *   - „Verbindung zur Content Pipe": Companion-Token (X-Calluna-Token), Version, erkannte Plugins.
 *   - „Module": Schalter und Einstellungen der Module (seit 0.10.0 nur headless).
 * Gespeichert wird über admin-post.php (Nonce + manage_options).
 */

if (!defined('ABSPATH')) {
    exit;
}

const CALLUNA_INDEX_SEITE = 'calluna-companion';

function calluna_index_admin_starten(): void {
    add_action('admin_post_calluna_index_speichern', 'calluna_index_admin_speichern');
    add_action('admin_notices', 'calluna_index_admin_hinweise');
    add_filter('plugin_action_links_' . plugin_basename(CALLUNA_INDEX_HAUPTDATEI), 'calluna_index_plugin_links');
}

function calluna_index_seiten_url(array $args = []): string {
    return add_query_arg(array_merge(['page' => CALLUNA_INDEX_SEITE], $args), admin_url('options-general.php'));
}

function calluna_index_plugin_links($links) {
    if (!is_array($links)) {
        return $links;
    }
    array_unshift($links, '<a href="' . esc_url(calluna_index_seiten_url()) . '">Einstellungen</a>');
    return $links;
}

/* ---------------------------------------------------------------- Meldungen */

function calluna_index_meldung(string $typ, string $text): void {
    $schluessel = 'calluna_index_meldung_' . get_current_user_id();
    $liste = get_transient($schluessel);
    if (!is_array($liste)) {
        $liste = [];
    }
    $liste[] = ['typ' => $typ, 'text' => $text];
    set_transient($schluessel, $liste, 120);
}

function calluna_index_meldungen_ausgeben(): void {
    $schluessel = 'calluna_index_meldung_' . get_current_user_id();
    $liste = get_transient($schluessel);
    if (!is_array($liste)) {
        return;
    }
    delete_transient($schluessel);
    foreach ($liste as $m) {
        $typ = in_array($m['typ'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $m['typ'] : 'info';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($typ), esc_html((string) ($m['text'] ?? '')));
    }
}

/** Hinweise der aktiven Module (z. B. Consent ohne Profil) auf Dashboard, Plugins und der eigenen Seite. */
function calluna_index_admin_hinweise(): void {
    if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    $id = $screen ? (string) $screen->id : '';
    $eigene = ($id === 'settings_page_' . CALLUNA_INDEX_SEITE);
    if (!$eigene && !in_array($id, ['dashboard', 'plugins'], true)) {
        return;
    }
    foreach (calluna_index_module() as $kennung => $modul) {
        $z = calluna_index_modul_zustand($kennung);
        if (!$z['aktiv']) {
            continue;
        }
        try {
            $hinweise = $modul->hinweise();
        } catch (\Throwable $fehler) {
            $hinweise = [];
        }
        foreach ($hinweise as $h) {
            // Nur Hinweise mit 'ueberall' oben als Notice; die übrigen stehen
            // im Status-Kasten des Moduls auf der Einstellungsseite.
            if (empty($h['ueberall'])) {
                continue;
            }
            $typ = in_array($h['typ'] ?? '', ['error', 'warning', 'info', 'success'], true) ? $h['typ'] : 'info';
            $link = $eigene ? '' : ' <a href="' . esc_url(calluna_index_seiten_url(['tab' => 'module'])) . '">Calluna Companion öffnen</a>';
            printf(
                '<div class="notice notice-%s"><p><strong>Calluna Companion · %s:</strong> %s%s</p></div>',
                esc_attr($typ),
                esc_html($modul->titel()),
                esc_html((string) ($h['text'] ?? '')),
                $link
            );
        }
    }
}

/* ---------------------------------------------------------------- Seite */

function calluna_index_einstellungsseite(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.', 403);
    }
    $tab = (isset($_GET['tab']) && $_GET['tab'] === 'module') ? 'module' : 'verbindung';
    ?>
    <div class="wrap calluna-index">
        <?php calluna_companion_logo_html('Companion'); ?>
        <h1 class="screen-reader-text">Calluna Companion</h1>
        <?php calluna_index_meldungen_ausgeben(); ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
            <a href="<?php echo esc_url(calluna_index_seiten_url()); ?>" class="nav-tab<?php echo $tab === 'verbindung' ? ' nav-tab-active' : ''; ?>">Verbindung zur Content Pipe</a>
            <a href="<?php echo esc_url(calluna_index_seiten_url(['tab' => 'module'])); ?>" class="nav-tab<?php echo $tab === 'module' ? ' nav-tab-active' : ''; ?>">Module</a>
        </nav>
        <?php
        if ($tab === 'module') {
            calluna_index_seite_module();
        } else {
            calluna_companion_token_bereich();
            calluna_index_seite_info();
        }
        ?>
    </div>
    <?php
}

function calluna_index_zeit($ts): string {
    $ts = (int) $ts;
    return $ts > 0 ? wp_date('d.m.Y H:i:s', $ts) : '—';
}

/** „gesetzt · …abcd · SHA-256 1a2b3c4d…" — nie den Wert selbst. */
function calluna_index_geheim_anzeige(string $wert): string {
    if ($wert === '') {
        return 'nicht gesetzt';
    }
    return 'gesetzt · endet auf …' . substr($wert, -4) . ' · SHA-256 ' . substr(hash('sha256', $wert), 0, 12) . '…';
}

/** Nur zum Lesen: was die Content Pipe über /calluna/v1/info von dieser Seite erfährt. */
function calluna_index_seite_info(): void {
    $seo = function_exists('calluna_companion_detect_seo_plugin') ? calluna_companion_detect_seo_plugin() : '';
    $namen = ['yoast' => 'Yoast SEO', 'rank-math' => 'Rank Math', 'aioseo' => 'All in One SEO', 'none' => 'keins'];
    $sprachen = defined('ICL_SITEPRESS_VERSION') ? 'WPML' : (function_exists('pll_languages_list') ? 'Polylang' : 'keins');
    ?>
    <h2>Diese Seite</h2>
    <table class="form-table" role="presentation">
        <tr><th scope="row">Companion-Version</th><td><code><?php echo esc_html(CALLUNA_COMPANION_VERSION); ?></code></td></tr>
        <tr><th scope="row">SEO-Plugin</th><td><?php echo esc_html($namen[$seo] ?? ($seo !== '' ? $seo : 'keins')); ?></td></tr>
        <tr><th scope="row">Sprachen-Plugin</th><td><?php echo esc_html($sprachen); ?></td></tr>
    </table>
    <?php
}

function calluna_index_seite_module(): void {
    $e = calluna_index_einstellungen();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="calluna_index_speichern" />
        <?php wp_nonce_field('calluna_index_speichern'); ?>
        <p class="description">Module sind standardmäßig aus. Die Headless-Vorschau braucht man nur, wenn WordPress die Redaktion ist und ein anderes Frontend die Seite ausliefert.</p>
        <?php calluna_index_module_tabelle($e); ?>
        <p class="submit"><button type="submit" name="aktion" value="speichern" class="button button-primary">Speichern</button></p>
    </form>
    <?php
}

function calluna_index_module_tabelle(array $e): void {
    $quellen = ['lokal' => 'eingestellt', 'index' => 'Vorgabe aus früherem Seitenprofil', 'standard' => 'Standard'];
    ?>
    <table class="widefat striped" style="max-width:1100px;">
        <thead><tr><th style="width:38%;">Modul</th><th style="width:22%;">Schalter</th><th>Wirksam</th></tr></thead>
        <tbody>
        <?php foreach (calluna_index_module() as $kennung => $modul) :
            $z = calluna_index_modul_zustand($kennung);
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($modul->titel()); ?></strong> <code><?php echo esc_html($kennung); ?></code>
                    <?php if ($modul->vorbereitet()) : ?>
                        <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:10px;background:#f0e9ff;color:#5b2bc4;font-size:11px;">kommt nach dem Livegang</span>
                    <?php endif; ?>
                    <br><span class="description"><?php echo esc_html($modul->beschreibung()); ?></span>
                </td>
                <td>
                    <select name="module[<?php echo esc_attr($kennung); ?>][zustand]">
                        <option value="an" <?php selected($z['aktiv'] || $z['lokal'] === 'an'); ?>>an</option>
                        <option value="aus" <?php selected(!$z['aktiv'] && $z['lokal'] !== 'an'); ?>>aus</option>
                    </select>
                </td>
                <td>
                    <?php if ($z['aktiv']) : ?>
                        <span style="color:#00712e;font-weight:600;">● an</span>
                    <?php else : ?>
                        <span style="color:#646970;font-weight:600;">○ aus</span>
                    <?php endif; ?>
                    <span class="description">(<?php echo esc_html($quellen[$z['quelle']] ?? $z['quelle']); ?>)</span>
                    <?php if ($z['blockiert'] !== null) : ?>
                        <br><span class="description">gesperrt: <?php echo esc_html($z['blockiert']); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
            $felder = $modul->felder();
            $status = '';
            try {
                $status = $modul->status_html();
            } catch (\Throwable $fehler) {
                $status = '';
            }
            if ($felder || $status !== '') :
                ?>
                <tr>
                    <td colspan="3" style="padding-top:0;">
                        <details<?php echo ($z['aktiv'] && !$modul->vorbereitet()) ? ' open' : ''; ?>>
                            <summary>Einstellungen <?php echo esc_html($modul->titel()); ?></summary>
                            <?php if ($status !== '') : ?>
                                <div style="margin:8px 0;padding:8px 12px;background:#f6f7f7;border-left:3px solid #6933d9;"><?php echo $status; // bereits escaped (status_html) ?></div>
                            <?php endif; ?>
                            <?php if ($felder) : ?>
                                <table class="form-table" role="presentation" style="margin-top:0;">
                                    <?php foreach ($felder as $schluessel => $def) {
                                        calluna_index_feld_html($modul, (string) $schluessel, $def, $e);
                                    } ?>
                                </table>
                            <?php endif; ?>
                        </details>
                    </td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function calluna_index_feld_html(Calluna_Index_Modul $modul, string $schluessel, array $def, array $e): void {
    $kennung = $modul->kennung();
    $name = 'module[' . $kennung . '][werte][' . $schluessel . ']';
    $id = 'calluna-index-' . $kennung . '-' . $schluessel;
    $typ = isset($def['typ']) ? (string) $def['typ'] : 'text';
    $lokal = isset($e['module'][$kennung]['werte'][$schluessel]) ? (string) $e['module'][$kennung]['werte'][$schluessel] : '';
    $vorgabe = calluna_index_modul_vorgabe($kennung, $schluessel);
    $standard = isset($def['standard']) ? (string) $def['standard'] : '';
    $platzhalter = $vorgabe !== null ? 'Vorgabe: ' . $vorgabe : ($standard !== '' ? 'Standard: ' . $standard : '');
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html((string) ($def['titel'] ?? $schluessel)); ?></label></th>
        <td>
            <?php
            switch ($typ) {
                case 'auswahl':
                    $optionen = isset($def['optionen']) && is_array($def['optionen']) ? $def['optionen'] : [];
                    $wirksam = $modul->wert($schluessel);
                    echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
                    echo '<option value="">' . esc_html('— ' . ($vorgabe !== null ? 'Vorgabe' : 'Standard') . ' (' . ($optionen[$vorgabe !== null ? $vorgabe : $standard] ?? ($vorgabe !== null ? $vorgabe : $standard)) . ')') . '</option>';
                    foreach ($optionen as $wert => $beschriftung) {
                        echo '<option value="' . esc_attr((string) $wert) . '"' . selected($lokal, (string) $wert, false) . '>' . esc_html((string) $beschriftung) . '</option>';
                    }
                    echo '</select>';
                    unset($wirksam);
                    break;
                case 'checkbox':
                    $wirksam = $modul->wert($schluessel);
                    echo '<input type="hidden" name="' . esc_attr($name) . '" value="0" />';
                    echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"' . checked($wirksam, '1', false) . ' /> ' . esc_html((string) ($def['beschriftung'] ?? 'an')) . '</label>';
                    break;
                case 'textarea':
                    echo '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" rows="3" class="large-text code" placeholder="' . esc_attr($platzhalter) . '">' . esc_textarea($lokal) . '</textarea>';
                    break;
                case 'zahl':
                    echo '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($lokal) . '" class="small-text"'
                        . (isset($def['min']) ? ' min="' . (int) $def['min'] . '"' : '')
                        . (isset($def['max']) ? ' max="' . (int) $def['max'] . '"' : '')
                        . ' placeholder="' . esc_attr($vorgabe !== null ? $vorgabe : $standard) . '" />';
                    break;
                case 'passwort':
                    $gesetzt = !empty($def['geheim']) ? $modul->wert($schluessel) : $lokal;
                    echo '<input type="password" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr($gesetzt !== '' ? 'unverändert lassen' : '') . '" />';
                    echo ' <span class="description">' . esc_html(calluna_index_geheim_anzeige($gesetzt)) . '</span>';
                    if ($gesetzt !== '') {
                        echo ' <label><input type="checkbox" name="' . esc_attr('module[' . $kennung . '][loeschen][' . $schluessel . ']') . '" value="1" /> entfernen</label>';
                    }
                    break;
                default:
                    echo '<input type="' . ($typ === 'url' ? 'url' : 'text') . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($lokal) . '" class="regular-text" placeholder="' . esc_attr($platzhalter) . '" />';
            }
            if (!empty($def['hilfe'])) {
                echo '<p class="description">' . wp_kses_post((string) $def['hilfe']) . '</p>';
            }
            ?>
        </td>
    </tr>
    <?php
}

/* ---------------------------------------------------------------- Speichern */

function calluna_index_admin_speichern(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.', 403);
    }
    check_admin_referer('calluna_index_speichern');

    $e = calluna_index_einstellungen();

    // Module
    $eingabe = isset($_POST['module']) && is_array($_POST['module']) ? wp_unslash($_POST['module']) : [];
    $geheim = get_option(CALLUNA_INDEX_GEHEIM_OPTION, []);
    if (!is_array($geheim)) {
        $geheim = [];
    }
    foreach (calluna_index_module() as $kennung => $modul) {
        $m = isset($eingabe[$kennung]) && is_array($eingabe[$kennung]) ? $eingabe[$kennung] : [];
        $bisher = isset($e['module'][$kennung]) && is_array($e['module'][$kennung]) ? $e['module'][$kennung] : [];
        $zustand = isset($m['zustand']) && in_array($m['zustand'], ['an', 'aus'], true) ? $m['zustand'] : ($bisher['zustand'] ?? 'vorgabe');
        $werte = isset($bisher['werte']) && is_array($bisher['werte']) ? $bisher['werte'] : [];
        $roh_werte = isset($m['werte']) && is_array($m['werte']) ? $m['werte'] : [];
        $loeschen = isset($m['loeschen']) && is_array($m['loeschen']) ? $m['loeschen'] : [];

        foreach ($modul->felder() as $schluessel => $def) {
            if (!empty($def['geheim'])) {
                if (!empty($loeschen[$schluessel])) {
                    unset($geheim[$kennung][$schluessel]);
                } elseif (isset($roh_werte[$schluessel]) && trim((string) $roh_werte[$schluessel]) !== '') {
                    $geheim[$kennung][$schluessel] = calluna_index_feld_bereinigen($def, $roh_werte[$schluessel]);
                }
                continue;
            }
            if (!array_key_exists($schluessel, $roh_werte)) {
                continue; // Feld nicht im Formular → unverändert
            }
            $wert = calluna_index_feld_bereinigen($def, $roh_werte[$schluessel]);
            if ($wert === '' && (string) $roh_werte[$schluessel] !== '' && ($def['typ'] ?? '') !== 'checkbox') {
                calluna_index_meldung('error', $modul->titel() . ' · ' . ($def['titel'] ?? $schluessel) . ': Wert ungültig — Feld geleert.');
            }
            $werte[$schluessel] = $wert;
        }
        $e['module'][$kennung] = ['zustand' => $zustand, 'werte' => $werte];
    }
    update_option(CALLUNA_INDEX_GEHEIM_OPTION, $geheim, false);
    calluna_index_einstellungen_speichern($e);

    calluna_index_uebergaenge_pruefen();
    calluna_index_meldung('success', 'Einstellungen gespeichert.');

    wp_safe_redirect(calluna_index_seiten_url(['tab' => 'module']));
    exit;
}

/** Bereinigt einen Formularwert je Feldtyp. '' = „nicht gesetzt" (Vorgabe/Standard gilt). */
function calluna_index_feld_bereinigen(array $def, $roh): string {
    $roh = is_scalar($roh) ? (string) $roh : '';
    $typ = isset($def['typ']) ? (string) $def['typ'] : 'text';
    switch ($typ) {
        case 'url':
            $v = trim($roh);
            return $v === '' ? '' : calluna_index_url_pruefen($v);
        case 'ziel':
            // URL (https, http nur lokal) oder absoluter Serverpfad (Auftragsdatei).
            $v = trim($roh);
            if ($v === '') {
                return '';
            }
            if ($v[0] === '/') {
                return (strpos($v, '..') === false && preg_match('#^/[A-Za-z0-9._/-]+$#', $v)) ? untrailingslashit($v) : '';
            }
            return calluna_index_url_pruefen($v);
        case 'zahl':
            if (trim($roh) === '' || !is_numeric(trim($roh))) {
                return '';
            }
            $n = (int) $roh;
            if (isset($def['min'])) {
                $n = max((int) $def['min'], $n);
            }
            if (isset($def['max'])) {
                $n = min((int) $def['max'], $n);
            }
            return (string) $n;
        case 'auswahl':
            $optionen = isset($def['optionen']) && is_array($def['optionen']) ? $def['optionen'] : [];
            return array_key_exists($roh, $optionen) ? $roh : '';
        case 'checkbox':
            return $roh === '1' ? '1' : '0';
        case 'textarea':
            return sanitize_textarea_field($roh);
        case 'passwort':
            $v = trim($roh);
            return preg_match('/^[\x21-\x7e]{1,1024}$/', $v) ? $v : '';
        default:
            return sanitize_text_field($roh);
    }
}
