<?php
/**
 * Modul „weiterleitungen" — VORBEREITET, ohne Funktion (kommt nach dem Livegang).
 *
 * Ziel: Weiterleitungen (301/302/308/410) zentral pflegen — aus dem Seitenprofil
 * bzw. lokal —, per build-konfig ans Frontend geben (Headless: der Build
 * erzeugt _redirects/Middleware) und auf klassischen Seiten selbst ausführen.
 * Optional 404-Protokoll als Grundlage für neue Regeln.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Calluna_Index_Modul_Weiterleitungen extends Calluna_Index_Modul {

    public function kennung(): string {
        return 'weiterleitungen';
    }

    public function titel(): string {
        return 'Weiterleitungen';
    }

    public function beschreibung(): string {
        return 'Weiterleitungsregeln zentral pflegen und an den Build bzw. die Seite geben (Ersatz für Redirection / Rank-Math-Weiterleitungen).';
    }

    public function vorbereitet(): bool {
        return true;
    }

    public function felder(): array {
        return [
            'statuscode' => [
                'typ'      => 'auswahl',
                'titel'    => 'Standard-Statuscode',
                'profil'   => 'statuscode',
                'standard' => '301',
                'optionen' => ['301' => '301 dauerhaft', '302' => '302 vorübergehend', '308' => '308 dauerhaft (Methode bleibt)'],
            ],
            'protokoll404' => [
                'typ'          => 'checkbox',
                'titel'        => '404-Protokoll',
                'beschriftung' => 'Nicht gefundene Adressen protokollieren (ohne IP)',
                'standard'     => '0',
            ],
        ];
    }

    public function laden(): void {
        // Kommt nach dem Livegang.
    }
}
