<?php
/**
 * Modul „seo" — VORBEREITET, ohne Funktion (kommt nach dem Livegang).
 *
 * Ziel: Ersatz für Rank Math auf Headless-Seiten. Konzept: docs/seo-modul.md
 * (Editor-Seitenleiste, Term-SEO, Speicherung in _calluna_seo_*, Migration
 * rank_math_* / _yoast_* per WP-CLI, REST-Ausgabe fürs Frontend, Folgen für die
 * Plugin-Erkennung der Content Pipe).
 *
 * Bis dahin: das bestehende SEO-Mapping des Companion-Kerns (Yoast / Rank Math /
 * AIOSEO / _calluna_seo_*) bleibt unverändert zuständig.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Calluna_Index_Modul_Seo extends Calluna_Index_Modul {

    public function kennung(): string {
        return 'seo';
    }

    public function titel(): string {
        return 'SEO';
    }

    public function beschreibung(): string {
        return 'Eigene SEO-Maske statt Rank Math: Titel, Beschreibung, Fokus-Keyword, Robots, Canonical, Open Graph/Twitter — gespeichert in den _calluna_seo_*-Feldern.';
    }

    public function vorbereitet(): bool {
        return true;
    }

    public function felder(): array {
        return [
            'titelMuster' => [
                'typ'      => 'text',
                'titel'    => 'Titel-Muster',
                'profil'   => 'titelMuster',
                'standard' => '%titel% %trenner% %seite%',
                'hilfe'    => 'Platzhalter: <code>%titel%</code>, <code>%seite%</code>, <code>%trenner%</code>.',
            ],
            'trenner' => [
                'typ'      => 'text',
                'titel'    => 'Trennzeichen',
                'profil'   => 'trenner',
                'standard' => '–',
            ],
            'ogBild' => [
                'typ'    => 'url',
                'titel'  => 'Standard-Bild (Open Graph)',
                'profil' => 'ogBild',
            ],
            'archiveNoindex' => [
                'typ'          => 'checkbox',
                'titel'        => 'Archive',
                'beschriftung' => 'Schlagwort- und Autorenarchive standardmäßig noindex',
                'standard'     => '1',
            ],
        ];
    }

    public function laden(): void {
        // Kommt nach dem Livegang.
    }
}
