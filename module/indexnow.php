<?php
/**
 * Modul „indexnow" — VORBEREITET, ohne Funktion (kommt nach dem Livegang).
 *
 * Ziel: Nach Veröffentlichen/Aktualisieren/Löschen die öffentliche URL
 * (Headless: Frontend-Adresse) per IndexNow melden — gebündelt und entprellt
 * wie der Build-Auslöser des Headless-Moduls. Der Schlüssel muss unter
 * {frontend}/{schluessel}.txt erreichbar sein (liefert dann der Build aus).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Calluna_Index_Modul_Indexnow extends Calluna_Index_Modul {

    public function kennung(): string {
        return 'indexnow';
    }

    public function titel(): string {
        return 'IndexNow';
    }

    public function beschreibung(): string {
        return 'Meldet geänderte Seiten per IndexNow an Bing, Yandex & Co.';
    }

    public function vorbereitet(): bool {
        return true;
    }

    public function felder(): array {
        return [
            'schluessel' => [
                'typ'    => 'text',
                'titel'  => 'Schlüssel',
                'profil' => 'schluessel',
                'hilfe'  => '8–128 Zeichen <code>[a-zA-Z0-9-]</code>; muss als <code>/&lt;schlüssel&gt;.txt</code> auf der öffentlichen Seite liegen.',
            ],
            'endpunkt' => [
                'typ'      => 'url',
                'titel'    => 'Endpunkt',
                'profil'   => 'endpunkt',
                'standard' => 'https://api.indexnow.org/indexnow',
            ],
        ];
    }

    public function laden(): void {
        // Kommt nach dem Livegang.
    }
}
