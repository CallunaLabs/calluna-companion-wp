<?php
/**
 * Modul „consent" — Consent-Konfiguration für das Frontend.
 *
 * Das CMS ist headless: Hier wird NICHTS im WordPress-Frontend ausgegeben. Der
 * Banner (@calluna/consent, CookieConsent v3 + Google Consent Mode v2) entsteht
 * im Astro-Build. Das Modul sorgt nur dafür, dass `profil.consent` in
 * GET /calluna/v1/index/build-konfig steht (siehe index/rest.php), und warnt,
 * wenn es an ist, aber kein Profil mit Consent-Teil vorliegt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Calluna_Index_Modul_Consent extends Calluna_Index_Modul {

    public function kennung(): string {
        return 'consent';
    }

    public function titel(): string {
        return 'Consent';
    }

    public function beschreibung(): string {
        return 'Gibt die Consent-Konfiguration (Kategorien, Dienste, Texte, Nachweis) aus dem Seitenprofil an den Build weiter. Keine Ausgabe im CMS.';
    }

    public function laden(): void {
        // Absichtlich leer: keine Frontend-Ausgabe im CMS.
    }

    public function hinweise(): array {
        $profil = calluna_index_profil();
        if ($profil === null) {
            return [[
                'typ'      => 'warning',
                'text'     => 'Das Modul ist an, aber es liegt noch kein Seitenprofil vor. Der Build bekommt keine Consent-Konfiguration (consent: null) und baut keinen Banner.',
                'ueberall' => true,
            ]];
        }
        if (empty($profil['consent']) || !is_array($profil['consent'])) {
            return [[
                'typ'      => 'warning',
                'text'     => 'Das Modul ist an, aber das Seitenprofil (Revision ' . (isset($profil['revision']) ? (string) $profil['revision'] : '?') . ') enthält keinen Consent-Teil.',
                'ueberall' => true,
            ]];
        }
        return [];
    }

    public function status_html(): string {
        $profil = calluna_index_profil();
        if ($profil === null || empty($profil['consent']) || !is_array($profil['consent'])) {
            return 'Keine Consent-Konfiguration im Profil.';
        }
        $c = $profil['consent'];
        $kategorien = isset($c['kategorien']) && is_array($c['kategorien']) ? count($c['kategorien']) : 0;
        $dienste = [];
        if (isset($c['dienste']) && is_array($c['dienste'])) {
            foreach ($c['dienste'] as $d) {
                if (is_array($d) && isset($d['id']) && is_scalar($d['id'])) {
                    $dienste[] = (string) $d['id'];
                }
            }
        }
        $stufe = isset($c['nachweis']['stufe']) && is_scalar($c['nachweis']['stufe']) ? (string) $c['nachweis']['stufe'] : '—';
        $version = isset($c['version']) && is_scalar($c['version']) ? (string) $c['version'] : '—';
        return 'Version <code>' . esc_html($version) . '</code> · '
            . esc_html($kategorien . ' Kategorien') . ' · '
            . esc_html(count($dienste) . ' Dienste') . ($dienste ? ' (<code>' . esc_html(implode(', ', $dienste)) . '</code>)' : '') . ' · '
            . 'Nachweis: ' . esc_html($stufe)
            . '<br><span class="description">Gepflegt in der Index-Konsole; hier nur Anzeige.</span>';
    }
}
