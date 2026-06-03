<?php
/*
Plugin Name: Impression 3D - Pont Slicer
Description: Relie WordPress au service de découpe (slicer) installé sur le VPS, pour calculer le prix d'impression côté serveur. Fonctionne aux côtés de 3DPrint Lite.
Version: 0.1.0
Author: gege1010
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

// Sécurité : empêche d'ouvrir ce fichier directement dans le navigateur.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Petit raccourci pour lire un réglage enregistré.
 * Tous nos réglages sont stockés ensemble dans une seule option "i3db_settings".
 */
function i3db_get($key, $default = '') {
    $o = get_option('i3db_settings', array());
    return isset($o[$key]) ? $o[$key] : $default;
}

/**
 * Ajoute une page "Pont Impression 3D" dans le menu Réglages de l'admin WordPress.
 */
add_action('admin_menu', function () {
    add_options_page(
        'Pont Impression 3D',     // titre de la page
        'Pont Impression 3D',     // libellé dans le menu
        'manage_options',         // seul un administrateur y a accès
        'i3db',                   // identifiant de la page
        'i3db_render_page'        // fonction qui affiche la page
    );
});

/**
 * Affiche la page de réglages + le test de connexion.
 */
function i3db_render_page() {
    if (!current_user_can('manage_options')) {
        return; // double sécurité
    }

    $notice = '';
    $test_result = '';

    // --- 1) Enregistrement des réglages -----------------------------------
    if (isset($_POST['i3db_save']) && check_admin_referer('i3db_save_action')) {
        $settings = array(
            'slicer_url' => esc_url_raw(trim($_POST['slicer_url'])),
            'api_key'    => sanitize_text_field($_POST['api_key']),
        );
        update_option('i3db_settings', $settings);
        $notice = 'Réglages enregistrés.';
    }

    // --- 2) Test de connexion au slicer -----------------------------------
    if (isset($_POST['i3db_test']) && check_admin_referer('i3db_test_action')) {
        // On interroge la page /health du service (la plus simple, sans fichier).
        $base = rtrim(i3db_get('slicer_url', 'http://127.0.0.1:8099'), '/');
        $resp = wp_remote_get($base . '/health', array('timeout' => 10));

        if (is_wp_error($resp)) {
            // WordPress n'a pas réussi à joindre le service.
            $test_result = "ÉCHEC : " . esc_html($resp->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            $test_result = "Réponse HTTP " . intval($code) . " : " . esc_html($body);
        }
    }

    // Valeurs actuelles (avec des valeurs par défaut pratiques).
    $slicer_url = i3db_get('slicer_url', 'http://127.0.0.1:8099');
    $api_key    = i3db_get('api_key', '');
    ?>
    <div class="wrap">
        <h1>Pont Impression 3D</h1>

        <?php if ($notice): ?>
            <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <h2>Réglages</h2>
        <form method="post">
            <?php wp_nonce_field('i3db_save_action'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="slicer_url">Adresse du slicer</label></th>
                    <td>
                        <input type="text" id="slicer_url" name="slicer_url"
                               value="<?php echo esc_attr($slicer_url); ?>" size="40" class="regular-text">
                        <p class="description">Sur la même machine, laisse <code>http://127.0.0.1:8099</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="api_key">Clé secrète</label></th>
                    <td>
                        <input type="text" id="api_key" name="api_key"
                               value="<?php echo esc_attr($api_key); ?>" size="40" class="regular-text">
                        <p class="description">La même clé que celle donnée au lancement du conteneur (SLICER_API_KEY).</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="i3db_save" class="button button-primary">Enregistrer</button></p>
        </form>

        <hr>

        <h2>Test de connexion</h2>
        <p>Vérifie que WordPress arrive à joindre le service de découpe.</p>
        <form method="post">
            <?php wp_nonce_field('i3db_test_action'); ?>
            <p><button type="submit" name="i3db_test" class="button">Tester la connexion</button></p>
        </form>

        <?php if ($test_result): ?>
            <div class="notice notice-info"><p><strong>Résultat :</strong> <?php echo $test_result; ?></p></div>
        <?php endif; ?>
    </div>
    <?php
}
