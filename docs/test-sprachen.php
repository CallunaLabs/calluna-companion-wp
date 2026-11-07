<?php
/**
 * Abnahmetest Modul „sprachen" gegen ein echtes WordPress (nicht auf Kundenseiten!).
 *   wp --user=<admin> eval-file test-sprachen.php
 * Legt Testdaten an (Titel beginnen mit „ZZTEST") und löscht sie am Ende wieder.
 */

$fehler = 0;
$angelegt = ['post' => [], 'term' => []];
function pruefe($name, $bed, $info = '') {
    global $fehler;
    echo ($bed ? 'ok   ' : 'FEHL ') . $name . ($bed ? '' : '  → ' . (is_string($info) ? $info : wp_json_encode($info))) . "\n";
    if (!$bed) $fehler++;
}
function rest($methode, $pfad, $daten = []) {
    $a = new WP_REST_Request($methode, $pfad);
    if ($methode === 'GET') { foreach ($daten as $k => $v) $a->set_param($k, $v); }
    else { $a->set_header('content-type', 'application/json'); $a->set_body(wp_json_encode($daten)); }
    $r = rest_do_request($a);
    return [$r->get_status(), $r->get_data()];
}

$m = calluna_sprachen();
pruefe('Modul aktiv', $m !== null);
if (!$m) exit(1);

// Kategorien: Magazin (de) ↔ Magazine (en)
[$s, $kde] = rest('POST', '/wp/v2/categories', ['name' => 'ZZTEST Magazin', 'slug' => 'zztest-magazin', 'calluna_sprache' => ['lang' => 'de']]);
$angelegt['term'][] = $kde['id'];
[$s, $ken] = rest('POST', '/wp/v2/categories', ['name' => 'ZZTEST Magazine', 'slug' => 'zztest-magazine', 'calluna_sprache' => ['lang' => 'en', 'verknuepfen_mit' => $kde['id']]]);
$angelegt['term'][] = $ken['id'];
pruefe('Kategorie en angelegt', $s === 201, $ken);
[$s, $k] = rest('GET', '/wp/v2/categories/' . $kde['id']);
pruefe('Kategorie-Gruppe de↔en', isset($k['calluna_sprache']['uebersetzungen']->en) || isset($k['calluna_sprache']['uebersetzungen']['en']), $k['calluna_sprache'] ?? $k);
pruefe('Kategorie-Link en mit Präfix', strpos(get_term_link($ken['id']), '/en/') !== false, get_term_link($ken['id']));

// Seiten: gleicher Slug in en und fr
[$s, $pde] = rest('POST', '/wp/v2/pages', ['title' => 'ZZTEST Kontakt', 'slug' => 'zztest-kontakt', 'status' => 'publish', 'calluna_sprache' => ['lang' => 'de']]);
[$s, $pen] = rest('POST', '/wp/v2/pages', ['title' => 'ZZTEST Contact', 'slug' => 'zztest-contact', 'status' => 'publish', 'calluna_sprache' => ['lang' => 'en', 'verknuepfen_mit' => $pde['id']]]);
[$s, $pfr] = rest('POST', '/wp/v2/pages', ['title' => 'ZZTEST Contact FR', 'slug' => 'zztest-contact', 'status' => 'publish', 'calluna_sprache' => ['lang' => 'fr', 'verknuepfen_mit' => $pde['id']]]);
[$s, $pfr2] = rest('POST', '/wp/v2/pages', ['title' => 'ZZTEST Contact FR 2', 'slug' => 'zztest-contact', 'status' => 'publish', 'calluna_sprache' => ['lang' => 'fr']]);
array_push($angelegt['post'], $pde['id'], $pen['id'], $pfr['id'], $pfr2['id']);
pruefe('Slug fr = en erlaubt', get_post_field('post_name', $pfr['id']) === 'zztest-contact', get_post_field('post_name', $pfr['id']));
pruefe('Slug fr doppelt in fr → -2', get_post_field('post_name', $pfr2['id']) === 'zztest-contact-2', get_post_field('post_name', $pfr2['id']));
pruefe('Link en mit /en/', strpos(get_permalink($pen['id']), '/en/zztest-contact/') !== false, get_permalink($pen['id']));
pruefe('Link de ohne Präfix', strpos(get_permalink($pde['id']), '/de/') === false && strpos(get_permalink($pde['id']), '/zztest-kontakt/') !== false, get_permalink($pde['id']));

[$s, $d] = rest('GET', '/wp/v2/pages/' . $pde['id']);
$u = (array) $d['calluna_sprache']['uebersetzungen'];
pruefe('Gruppe de/en/fr vollständig', isset($u['de'], $u['en'], $u['fr']) && !isset($u['es']), array_keys($u));
pruefe('fr2 nicht in der Gruppe', $u['fr']['id'] === $pfr['id'], $u['fr'] ?? null);

[$s, $liste] = rest('GET', '/wp/v2/pages', ['calluna_lang' => 'fr', 'search' => 'ZZTEST', 'per_page' => 50]);
pruefe('Filter calluna_lang=fr', count($liste) === 2, count($liste));
[$s, $liste] = rest('GET', '/wp/v2/pages', ['calluna_lang' => 'de', 'search' => 'ZZTEST', 'per_page' => 50]);
pruefe('Filter calluna_lang=de', count($liste) === 1, count($liste));

// Beitrag mit Kategorie → Übersetzung anlegen (es fehlt als Kategorie, en vorhanden)
[$s, $bde] = rest('POST', '/wp/v2/posts', ['title' => 'ZZTEST Beitrag', 'slug' => 'zztest-beitrag', 'status' => 'publish', 'categories' => [$kde['id']], 'calluna_sprache' => ['lang' => 'de']]);
$angelegt['post'][] = $bde['id'];
pruefe('Beitrags-Link de', strpos(get_permalink($bde['id']), '/zztest-magazin/zztest-beitrag/') !== false, get_permalink($bde['id']));
[$s, $neu] = rest('POST', '/calluna/v1/sprachen/uebersetzung', ['quelle' => $bde['id'], 'lang' => 'en']);
$angelegt['post'][] = $neu['id'] ?? 0;
pruefe('Übersetzung en angelegt (201)', $s === 201, $neu);
pruefe('Übersetzung ist Entwurf in en', get_post_status($neu['id']) === 'draft' && $m->sprache_von($neu['id']) === 'en');
pruefe('Kategorie übersetzt (Magazine)', wp_get_post_categories($neu['id']) === [$ken['id']], wp_get_post_categories($neu['id']));
pruefe('Slug gleich wie Quelle', get_post_field('post_name', $neu['id']) === 'zztest-beitrag', get_post_field('post_name', $neu['id']));
[$s, $nochmal] = rest('POST', '/calluna/v1/sprachen/uebersetzung', ['quelle' => $bde['id'], 'lang' => 'en']);
pruefe('Zweites Anlegen liefert vorhandene (200)', $s === 200 && $nochmal['id'] === $neu['id'], [$s, $nochmal]);

// Content Pipe: /i18n/link, /i18n/overview, /info
[$s, $bes] = rest('POST', '/wp/v2/posts', ['title' => 'ZZTEST Beitrag ES', 'status' => 'publish']);
$angelegt['post'][] = $bes['id'];
[$s, $l] = rest('POST', '/calluna/v1/i18n/link', ['source_lang' => 'de', 'posts' => [['lang' => 'de', 'post_id' => $bde['id']], ['lang' => 'es', 'post_id' => $bes['id']]]]);
pruefe('/i18n/link Strategie calluna', $s === 200 && ($l['strategy'] ?? '') === 'calluna', $l);
pruefe('es jetzt in der Gruppe', isset($m->uebersetzungen($bde['id'])['es']), $m->uebersetzungen($bde['id']));
[$s, $o] = rest('GET', '/calluna/v1/i18n/overview', ['post_type' => 'post', 'per_page' => 100]);
$zeile = null;
foreach ($o['rows'] as $r) { if ($r['source_id'] === $bde['id']) $zeile = $r; }
pruefe('/i18n/overview plugin calluna', ($o['plugin'] ?? '') === 'calluna', $o['plugin'] ?? null);
pruefe('/i18n/overview Zeile mit de/en/es', $zeile && isset($zeile['langs']['de'], $zeile['langs']['en'], $zeile['langs']['es']), $zeile);
pruefe('/i18n/overview ohne es-Beitrag als Zeile', !array_filter($o['rows'], function ($r) use ($bes) { return $r['source_id'] === $bes['id']; }));
[$s, $i] = rest('GET', '/calluna/v1/info');
pruefe('/info i18n.active = calluna', ($i['i18n']['active'] ?? '') === 'calluna' && $i['i18n']['languages'] === ['de', 'en', 'es', 'fr'], $i['i18n'] ?? null);
[$s, $b] = rest('GET', '/calluna/v1/index/build-konfig');
pruefe('build-konfig sprachen', ($b['module']->sprachen['sprachen'] ?? null) === ['de', 'en', 'es', 'fr'], $b['module']->sprachen ?? null);
[$s, $w] = rest('POST', '/calluna/v1/sprachen/uebernehmen', ['trocken' => true]);
pruefe('Übernahme ohne WPML-Tabelle → 404', $s === 404, [$s, $w]);

// Sprachwechsel entfernt „-2"
rest('POST', '/wp/v2/pages/' . $pfr2['id'], ['calluna_sprache' => ['lang' => 'es']]);
pruefe('Sprachwechsel fr→es: Slug wieder ohne -2', get_post_field('post_name', $pfr2['id']) === 'zztest-contact', get_post_field('post_name', $pfr2['id']));

// Übernahme aus WPML (nachgebaute Tabelle icl_translations)
global $wpdb;
$t = $wpdb->prefix . 'icl_translations';
$wpdb->query("CREATE TABLE {$t} (translation_id BIGINT AUTO_INCREMENT PRIMARY KEY, element_type VARCHAR(60), element_id BIGINT NULL, trid BIGINT, language_code VARCHAR(7), source_language_code VARCHAR(7) NULL)");
$w1 = wp_insert_post(['post_title' => 'ZZTEST WPML de', 'post_status' => 'publish']);
$w2 = wp_insert_post(['post_title' => 'ZZTEST WPML fr', 'post_status' => 'publish']);
$wk = wp_insert_term('ZZTEST WPML Kat', 'category');
array_push($angelegt['post'], $w1, $w2); $angelegt['term'][] = $wk['term_id'];
$wpdb->insert($t, ['element_type' => 'post_post', 'element_id' => $w1, 'trid' => 9001, 'language_code' => 'de']);
$wpdb->insert($t, ['element_type' => 'post_post', 'element_id' => $w2, 'trid' => 9001, 'language_code' => 'fr', 'source_language_code' => 'de']);
$wpdb->insert($t, ['element_type' => 'tax_category', 'element_id' => $wk['term_taxonomy_id'], 'trid' => 9002, 'language_code' => 'fr']);
[$s, $tr] = rest('POST', '/calluna/v1/sprachen/uebernehmen', ['trocken' => true]);
pruefe('WPML trocken zählt, schreibt nicht', $s === 200 && $tr['dokumente'] >= 2 && get_post_meta($w2, '_calluna_lang', true) === '', $tr);
[$s, $ue] = rest('POST', '/calluna/v1/sprachen/uebernehmen', ['trocken' => false]);
pruefe('WPML übernommen: fr + Gruppe', $m->sprache_von($w2) === 'fr' && $m->gruppe_von($w2) === 'wpml-9001', [$m->sprache_von($w2), $m->gruppe_von($w2)]);
pruefe('WPML Gruppe de↔fr', array_keys($m->uebersetzungen($w1)) == ['de', 'fr'] || array_keys($m->uebersetzungen($w1)) == ['fr', 'de'], $m->uebersetzungen($w1));
pruefe('WPML Begriff fr', $m->begriff_sprache($wk['term_id']) === 'fr');
$wpdb->query("DROP TABLE {$t}");

// Aufräumen
foreach ($angelegt['post'] as $id) { if ($id) wp_delete_post($id, true); }
foreach ($angelegt['term'] as $id) { wp_delete_term($id, 'category'); }
echo $fehler ? "\n$fehler Fehler\n" : "\nAlles bestanden\n";
