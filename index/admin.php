<?php
/**
 * Calluna Index — Einstellungsseite (Einstellungen → Calluna Index).
 *
 * Slug `calluna-companion` (wie bisher). Zwei Reiter:
 *   - „Index & Module": Site-ID, Site-Token, Index-URL, Profil-Stand, Module.
 *   - „Companion-Token": der bisherige Inhalt der Companion-Seite (X-Calluna-Token).
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
            $link = $eigene ? '' : ' <a href="' . esc_url(calluna_index_seiten_url()) . '">Calluna Index öffnen</a>';
            printf(
                '<div class="notice notice-%s"><p><strong>Calluna Index · %s:</strong> %s%s</p></div>',
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
    $tab = (isset($_GET['tab']) && $_GET['tab'] === 'token') ? 'token' : 'index';
    ?>
    <div class="wrap calluna-index">
        <?php calluna_companion_logo_html('Index'); ?>
        <h1 class="screen-reader-text">Calluna Index</h1>
        <?php calluna_index_meldungen_ausgeben(); ?>
        <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
            <a href="<?php echo esc_url(calluna_index_seiten_url()); ?>" class="nav-tab<?php echo $tab === 'index' ? ' nav-tab-active' : ''; ?>">Index &amp; Module</a>
            <a href="<?php echo esc_url(calluna_index_seiten_url(['tab' => 'token'])); ?>" class="nav-tab<?php echo $tab === 'token' ? ' nav-tab-active' : ''; ?>">Companion-Token</a>
        </nav>
        <?php
        if ($tab === 'token') {
            calluna_companion_token_bereich();
        } else {
            calluna_index_seite_index();
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

function calluna_index_seite_index(): void {
    $e = calluna_index_einstellungen();
    $stand = calluna_index_stand();
    $profil = calluna_index_profil();
    $token = calluna_index_site_token();
    ?>
    <p>Verbindet diese Seite mit ihrem <strong>Seitenprofil</strong> im Calluna Index (<code><?php echo esc_html(calluna_index_url()); ?></code>).
        Das Profil gibt vor, welche Module laufen und wie sie eingestellt sind; jede lokale Einstellung hier hat Vorrang.
        Ohne Einrichtung bleiben alle Module aus.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="calluna_index_speichern" />
        <?php wp_nonce_field('calluna_index_speichern'); ?>
        <?php /* Enter in einem Feld = Speichern (erster Submit-Knopf im Formular), nicht „Profil abrufen". */ ?>
        <button type="submit" name="aktion" value="speichern" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">Speichern</button>

        <h2>Verbindung</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="calluna-index-site-id">Site-ID</label></th>
                <td>
                    <?php if (defined('CALLUNA_INDEX_SITE_ID')) : ?>
                        <code><?php echo esc_html(calluna_index_site_id()); ?></code> <span class="description">(fest in wp-config.php: <code>CALLUNA_INDEX_SITE_ID</code>)</span>
                    <?php else : ?>
                        <input type="text" id="calluna-index-site-id" name="site_id" value="<?php echo esc_attr((string) $e['site_id']); ?>" class="regular-text" pattern="[a-z0-9\-]{2,40}" placeholder="z. B. paqato" autocomplete="off" />
                        <p class="description">Kennung der Seite im Index, <code>[a-z0-9-]</code>, 2–40 Zeichen.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="calluna-index-token">Site-Token</label></th>
                <td>
                    <?php if (defined('CALLUNA_INDEX_SITE_TOKEN')) : ?>
                        <span><?php echo esc_html(calluna_index_geheim_anzeige($token)); ?></span> <span class="description">(fest in wp-config.php: <code>CALLUNA_INDEX_SITE_TOKEN</code>)</span>
                    <?php else : ?>
                        <input type="password" id="calluna-index-token" name="site_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $token !== '' ? esc_attr('unverändert lassen') : esc_attr('aus der Index-Konsole einfügen'); ?>" />
                        <p class="description"><?php echo esc_html(calluna_index_geheim_anzeige($token)); ?>. Leer lassen = unverändert.
                            <?php if ($token !== '') : ?>
                                <label style="margin-left:8px;"><input type="checkbox" name="token_loeschen" value="1" /> Token entfernen</label>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="calluna-index-url">Index-URL</label></th>
                <td>
                    <?php if (defined('CALLUNA_INDEX_URL')) : ?>
                        <code><?php echo esc_html(calluna_index_url()); ?></code> <span class="description">(fest in wp-config.php: <code>CALLUNA_INDEX_URL</code>)</span>
                    <?php else : ?>
                        <input type="url" id="calluna-index-url" name="index_url" value="<?php echo esc_attr((string) $e['index_url']); ?>" class="regular-text" placeholder="<?php echo esc_attr(CALLUNA_INDEX_URL_STANDARD); ?>" />
                        <p class="description">Leer = <code><?php echo esc_html(CALLUNA_INDEX_URL_STANDARD); ?></code>. Nur https (http nur für localhost/*.test).</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h2>Seitenprofil</h2>
        <table class="form-table" role="presentation">
            <tr><th scope="row">Revision</th><td><?php echo $profil !== null && isset($profil['revision']) ? '<strong>' . esc_html((string) $profil['revision']) . '</strong>' : '—'; ?>
                <?php if ($profil !== null && !empty($profil['aktualisiert']) && is_string($profil['aktualisiert'])) : ?>
                    <span class="description">· im Index gespeichert <?php echo esc_html($profil['aktualisiert']); ?></span>
                <?php endif; ?></td></tr>
            <tr><th scope="row">Zuletzt abgerufen</th><td><?php echo esc_html(calluna_index_zeit($stand['abgerufen'])); ?>
                <span class="description">· zuletzt geändert <?php echo esc_html(calluna_index_zeit($stand['geaendert'])); ?>
                · zuletzt versucht <?php echo esc_html(calluna_index_zeit($stand['geprueft'])); ?><?php echo $stand['http'] ? esc_html(' (HTTP ' . (int) $stand['http'] . ')') : ''; ?></span></td></tr>
            <tr><th scope="row">Automatisch</th><td>
                <?php
                $naechster = wp_next_scheduled(CALLUNA_INDEX_PROFIL_CRON);
                echo $naechster ? esc_html('stündlich, nächster Abruf ' . calluna_index_zeit($naechster)) : 'aus (erst nach Einrichtung)';
                ?></td></tr>
            <?php if ($stand['fehler'] !== '') : ?>
                <tr><th scope="row">Fehler</th><td><span style="color:#b32d2e;"><?php echo esc_html($stand['fehler']); ?></span>
                    <?php if ($profil !== null) : ?><br><span class="description">Das zuletzt übernommene Profil bleibt gültig.</span><?php endif; ?></td></tr>
            <?php endif; ?>
        </table>
        <p>
            <button type="submit" name="aktion" value="abrufen" class="button"<?php disabled(!calluna_index_eingerichtet()); ?>>Profil jetzt abrufen</button>
            <?php if (!calluna_index_eingerichtet()) : ?><span class="description">erst Site-ID und Token speichern</span><?php endif; ?>
        </p>
        <?php if ($profil !== null) : ?>
            <details style="margin:8px 0 16px;"><summary>Profil ansehen (JSON)</summary>
                <pre style="max-height:420px;overflow:auto;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;"><?php echo esc_html((string) wp_json_encode($profil, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            </details>
        <?php endif; ?>

        <h2>Module</h2>
        <p class="description">„Vorgabe aus Index" folgt <code>profil.module.&lt;name&gt;.aktiv</code>; ohne Profil ist das Modul aus. Leere Felder übernehmen den Wert aus dem Profil, sonst den Standard.</p>
        <?php calluna_index_module_tabelle($e); ?>

        <h2>Für den Build</h2>
        <p><code>GET <?php echo esc_html(rest_url('calluna/v1/index/build-konfig')); ?></code><br>
            <span class="description">Auth: Anwendungspasswort eines Administrators oder <code>X-Calluna-Token</code> (Reiter „Companion-Token"). <code>?aktualisieren=1</code> fragt vorher das Profil beim Index nach.</span></p>

        <p class="submit"><button type="submit" name="aktion" value="speichern" class="button button-primary">Speichern</button></p>
    </form>
    <?php
}

function calluna_index_module_tabelle(array $e): void {
    $quellen = ['lokal' => 'lokal', 'index' => 'Vorgabe aus Index', 'standard' => 'Standard'];
    ?>
    <table class="widefat striped" style="max-width:1100px;">
        <thead><tr><th style="width:38%;">Modul</th><th style="width:22%;">Schalter</th><th>Wirksam</th></tr></thead>
        <tbody>
        <?php foreach (calluna_index_module() as $kennung => $modul) :
            $z = calluna_index_modul_zustand($kennung);
            $vorgabe_text = $z['vorgabe'] === null ? 'kein Profilwert' : ($z['vorgabe'] ? 'an' : 'aus');
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
                        <option value="vorgabe" <?php selected($z['lokal'], 'vorgabe'); ?>>Vorgabe aus Index (<?php echo esc_html($vorgabe_text); ?>)</option>
                        <option value="an" <?php selected($z['lokal'], 'an'); ?>>an</option>
                        <option value="aus" <?php selected($z['lokal'], 'aus'); ?>>aus</option>
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
    $platzhalter = $vorgabe !== null ? 'Index: ' . $vorgabe : ($standard !== '' ? 'Standard: ' . $standard : '');
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
                    echo '<option value="">' . esc_html('— ' . ($vorgabe !== null ? 'Index' : 'Standard') . ' (' . ($optionen[$vorgabe !== null ? $vorgabe : $standard] ?? ($vorgabe !== null ? $vorgabe : $standard)) . ')') . '</option>';
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

    $aktion = isset($_POST['aktion']) ? sanitize_key(wp_unslash($_POST['aktion'])) : 'speichern';

    if ($aktion === 'abrufen') {
        $stand = calluna_index_profil_abrufen(true);
        calluna_index_abruf_meldung($stand);
        wp_safe_redirect(calluna_index_seiten_url());
        exit;
    }

    $e = calluna_index_einstellungen();
    $site_vorher = calluna_index_site_id();

    if (!defined('CALLUNA_INDEX_SITE_ID') && isset($_POST['site_id'])) {
        $site = strtolower(trim(sanitize_text_field(wp_unslash($_POST['site_id']))));
        if ($site === '' || calluna_index_site_id_gueltig($site)) {
            $e['site_id'] = $site;
        } else {
            calluna_index_meldung('error', 'Site-ID ungültig (erlaubt: a–z, 0–9, Bindestrich, 2–40 Zeichen) — nicht übernommen.');
        }
    }

    if (!defined('CALLUNA_INDEX_URL') && isset($_POST['index_url'])) {
        $roh = trim(wp_unslash($_POST['index_url']));
        if ($roh === '') {
            $e['index_url'] = '';
        } else {
            $url = calluna_index_url_pruefen($roh);
            if ($url === '') {
                calluna_index_meldung('error', 'Index-URL ungültig (nur https, http nur für localhost/*.test) — nicht übernommen.');
            } else {
                $e['index_url'] = ($url === CALLUNA_INDEX_URL_STANDARD) ? '' : $url;
            }
        }
    }

    if (!defined('CALLUNA_INDEX_SITE_TOKEN')) {
        if (!empty($_POST['token_loeschen'])) {
            delete_option(CALLUNA_INDEX_TOKEN_OPTION);
        } else {
            $token = isset($_POST['site_token']) ? trim(wp_unslash($_POST['site_token'])) : '';
            if ($token !== '') {
                if (preg_match('/^[\x21-\x7e]{16,512}$/', $token)) {
                    update_option(CALLUNA_INDEX_TOKEN_OPTION, $token, false);
                } else {
                    calluna_index_meldung('error', 'Site-Token ungültig (16–512 sichtbare ASCII-Zeichen) — nicht übernommen.');
                }
            }
        }
    }

    // Module
    $eingabe = isset($_POST['module']) && is_array($_POST['module']) ? wp_unslash($_POST['module']) : [];
    $geheim = get_option(CALLUNA_INDEX_GEHEIM_OPTION, []);
    if (!is_array($geheim)) {
        $geheim = [];
    }
    foreach (calluna_index_module() as $kennung => $modul) {
        $m = isset($eingabe[$kennung]) && is_array($eingabe[$kennung]) ? $eingabe[$kennung] : [];
        $bisher = isset($e['module'][$kennung]) && is_array($e['module'][$kennung]) ? $e['module'][$kennung] : [];
        $zustand = isset($m['zustand']) && in_array($m['zustand'], ['vorgabe', 'an', 'aus'], true) ? $m['zustand'] : ($bisher['zustand'] ?? 'vorgabe');
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

    if (calluna_index_site_id() !== $site_vorher) {
        calluna_index_profil_verwerfen();
    }

    calluna_index_uebergaenge_pruefen();
    calluna_index_init(); // Cron je nach Einrichtung planen/abräumen
    calluna_index_meldung('success', 'Einstellungen gespeichert.');

    if (calluna_index_eingerichtet()) {
        calluna_index_abruf_meldung(calluna_index_profil_abrufen(false));
    }

    wp_safe_redirect(calluna_index_seiten_url());
    exit;
}

function calluna_index_abruf_meldung(array $stand): void {
    if ($stand['fehler'] !== '') {
        calluna_index_meldung('error', 'Profil-Abruf: ' . $stand['fehler']);
    } elseif ((int) $stand['http'] === 304) {
        calluna_index_meldung('success', 'Profil ist aktuell (Revision ' . (string) $stand['revision'] . ').');
    } else {
        calluna_index_meldung('success', 'Profil abgerufen (Revision ' . (string) $stand['revision'] . ').');
    }
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
