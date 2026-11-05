<?php
/**
 * Calluna Index — Basisklasse für Module.
 *
 * Ein Modul hat Kennung, Titel, Beschreibung, Einstellungsfelder und
 * aktivieren()/laden(). Ob es läuft, entscheidet der Kern
 * (calluna_index_modul_zustand): lokale Einstellung („an"/„aus") vor der
 * Vorgabe aus dem Seitenprofil (`profil.module[<kennung>].aktiv`), sonst aus.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Calluna_Index_Modul {

    /** Kennung wie im Seitenprofil (`profil.module.<kennung>`), [a-z0-9_-]. */
    abstract public function kennung(): string;

    abstract public function titel(): string;

    abstract public function beschreibung(): string;

    /**
     * Hooks registrieren. Läuft bei jeder Anfrage (plugins_loaded), aber nur,
     * wenn das Modul effektiv an ist.
     */
    abstract public function laden(): void;

    /** Einmalig, wenn das Modul von aus nach an wechselt (Speichern oder Profil-Abruf). */
    public function aktivieren(): void {
    }

    /** Einmalig, wenn das Modul von an nach aus wechselt. */
    public function deaktivieren(): void {
    }

    /** true = nur Gerüst („kommt nach dem Livegang"), läuft nie. */
    public function vorbereitet(): bool {
        return false;
    }

    /**
     * Grund, warum das Modul trotz Schalter „an" nicht laufen darf (null = darf).
     */
    public function blockiert(): ?string {
        return $this->vorbereitet() ? 'kommt nach dem Livegang' : null;
    }

    /**
     * Einstellungsfelder: [ schluessel => [
     *   'typ'        => 'text' | 'url' | 'ziel' | 'passwort' | 'auswahl' | 'checkbox' | 'zahl' | 'textarea',
     *   'titel'      => 'Beschriftung',
     *   'hilfe'      => 'Erklärung (HTML erlaubt, wird mit wp_kses_post ausgegeben)',
     *   'standard'   => 'Wert ohne lokale Einstellung und ohne Profilwert',
     *   'profil'     => 'Schlüssel in profil.module.<kennung>, der als Vorgabe dient',
     *   'optionen'   => [wert => beschriftung]   (nur auswahl),
     *   'min'/'max'  => Grenzen (nur zahl),
     *   'geheim'     => true: eigene, nicht autoloadete Option, nie in build-konfig,
     *                   nie aus dem Profil, im Formular nur „gesetzt",
     *   'oeffentlich'=> true: steht (effektiver Wert) in build-konfig,
     * ] ]
     */
    public function felder(): array {
        return [];
    }

    /** Hinweise für Admins, solange das Modul an ist: [['typ' => 'warning'|'error'|'info', 'text' => '…', 'ueberall' => bool]]. */
    public function hinweise(): array {
        return [];
    }

    /** Zusätzliches Status-HTML unter den Einstellungen des Moduls (bereits escaped). */
    public function status_html(): string {
        return '';
    }

    /** Öffentlicher Teil des Moduls für build-konfig (nur Felder mit 'oeffentlich'). */
    public function build_konfig(): array {
        $aus = [];
        foreach ($this->felder() as $schluessel => $feld) {
            if (!empty($feld['oeffentlich']) && empty($feld['geheim'])) {
                $aus[$schluessel] = $this->wert($schluessel);
            }
        }
        return $aus;
    }

    /** Effektiver Wert eines Feldes (lokal → Profil → Standard). */
    public function wert(string $feld): string {
        return calluna_index_modul_wert($this->kennung(), $feld);
    }

    public function ist_aktiv(): bool {
        $zustand = calluna_index_modul_zustand($this->kennung());
        return $zustand['aktiv'];
    }
}
