<?php
/**
 * Modul „Autorenbild“: eigenes Profilbild für WordPress-Benutzer, setzbar
 * über die Core-REST-API — damit die Content Pipe Autorenbilder pflegen kann,
 * ohne dass jemand ins WordPress-Backend muss.
 *
 * WordPress selbst kennt nur Gravatar (an der E-Mail-Adresse). Dieses Modul
 *  - registriert am Benutzer-Endpunkt das Feld `calluna_avatar` (ID eines
 *    Bilds aus der Mediathek; 0 = kein eigenes Bild):
 *        POST /wp-json/wp/v2/users/{id}   { "calluna_avatar": 123 }
 *    Lesbar in jedem Kontext (das Bild ist ohnehin öffentlich).
 *  - zeigt das Bild überall, wo WordPress ein Profilbild ausgibt
 *    (`pre_get_avatar_data`): Autorenbox, Kommentare, Admin-Liste, REST
 *    `avatar_urls`.
 *
 * Rechte: Schreiben nur, wer den Benutzer bearbeiten darf (`edit_user`),
 * dieselbe Prüfung, die der Core-Endpunkt ohnehin macht. Das Bild muss ein
 * Bild aus der Mediathek sein.
 *
 * Ist „Simple Local Avatars“ aktiv, hat dessen Bild Vorrang (das Modul greift
 * nur, wenn ein `calluna_avatar` gesetzt ist, und setzt sich nicht über ein
 * dort gewähltes Bild hinweg).
 */

if (!defined('ABSPATH')) {
    exit;
}

const CALLUNA_AVATAR_META = 'calluna_avatar';

add_action('rest_api_init', function () {
    register_rest_field('user', 'calluna_avatar', [
        'get_callback' => function ($user) {
            return (int) get_user_meta((int) $user['id'], CALLUNA_AVATAR_META, true);
        },
        'update_callback' => 'calluna_companion_set_avatar',
        'schema' => [
            'description' => 'ID eines Bilds aus der Mediathek als Profilbild (0 = Gravatar).',
            'type'        => 'integer',
            'minimum'     => 0,
            'context'     => ['view', 'edit'],
        ],
    ]);
});

/**
 * @param mixed   $value
 * @param WP_User $user
 * @return true|WP_Error
 */
function calluna_companion_set_avatar($value, $user) {
    if (!($user instanceof WP_User) || !current_user_can('edit_user', $user->ID)) {
        return new WP_Error('rest_cannot_edit', 'Keine Berechtigung, diesen Benutzer zu bearbeiten.', ['status' => 403]);
    }
    $media_id = (int) $value;
    if ($media_id === 0) {
        delete_user_meta($user->ID, CALLUNA_AVATAR_META);
        return true;
    }
    $attachment = get_post($media_id);
    if (!$attachment || $attachment->post_type !== 'attachment' || !wp_attachment_is_image($attachment)) {
        return new WP_Error('invalid_media_id', 'Das Profilbild muss ein Bild aus der Mediathek sein.', ['status' => 400]);
    }
    update_user_meta($user->ID, CALLUNA_AVATAR_META, $media_id);
    return true;
}

/** Benutzer-ID aus allem, was get_avatar() bekommen kann. */
function calluna_companion_avatar_user_id($id_or_email): int {
    if (is_numeric($id_or_email)) {
        return (int) $id_or_email;
    }
    if ($id_or_email instanceof WP_User) {
        return (int) $id_or_email->ID;
    }
    if ($id_or_email instanceof WP_Post) {
        return (int) $id_or_email->post_author;
    }
    if ($id_or_email instanceof WP_Comment) {
        return (int) $id_or_email->user_id;
    }
    if (is_string($id_or_email) && is_email($id_or_email)) {
        $user = get_user_by('email', $id_or_email);
        return $user ? (int) $user->ID : 0;
    }
    return 0;
}

add_filter('pre_get_avatar_data', function ($args, $id_or_email) {
    if (!empty($args['url'])) {
        return $args; // ein anderes Plugin (z. B. Simple Local Avatars) hat schon entschieden
    }
    $user_id = calluna_companion_avatar_user_id($id_or_email);
    if ($user_id <= 0) {
        return $args;
    }
    $media_id = (int) get_user_meta($user_id, CALLUNA_AVATAR_META, true);
    if ($media_id <= 0) {
        return $args;
    }
    $size = isset($args['size']) ? max(1, (int) $args['size']) : 96;
    $url = wp_get_attachment_image_url($media_id, [$size, $size]);
    if (!$url) {
        return $args;
    }
    $args['url'] = $url;
    $args['found_avatar'] = true;
    return $args;
}, 20, 2);
