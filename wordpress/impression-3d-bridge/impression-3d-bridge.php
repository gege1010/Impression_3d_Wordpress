<?php
/*
Plugin Name: Impression 3D - Pont Slicer
Description: Relie WordPress au service de découpe (slicer) du VPS et à WooCommerce. Calcule le prix côté serveur (poids + temps) et ajoute la commande au panier. Fonctionne aux côtés de 3DPrint Lite.
Version: 0.7.2
Author: gege1010
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

// Repère de build interne (la version reste 0.7.0 jusqu'à la 1.0 finale).
define('I3DB_BUILD', '0.7.2 — enveloppes machine réelles (V400 Ø300x410, Bambu 256) alignées sur le slicer');

/* =====================================================================
 *  RÉGLAGES
 * ===================================================================== */

function i3db_get($key, $default = '') {
    $o = get_option('i3db_settings', array());
    return isset($o[$key]) ? $o[$key] : $default;
}

function i3db_set($key, $value) {
    $o = get_option('i3db_settings', array());
    $o[$key] = $value;
    update_option('i3db_settings', $o);
}

/** Matériaux : lignes "cle|Nom|densité|prix_au_gramme". */
function i3db_materials() {
    $raw = i3db_get('materials', "pla|PLA|1.24|0.05\npetg|PETG|1.27|0.06");
    $out = array();
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 4) continue;
        $out[$p[0]] = array('name' => $p[1], 'density' => floatval($p[2]), 'price_g' => floatval($p[3]));
    }
    return $out;
}

/** Prix horaire (€/h) par machine. */
function i3db_machine_rate($printer) {
    $map = array(
        'a1'   => floatval(i3db_get('rate_a1', '1.5')),
        'p1s'  => floatval(i3db_get('rate_p1s', '1.5')),
        'v400' => floatval(i3db_get('rate_v400', '1.5')),
    );
    return isset($map[$printer]) ? $map[$printer] : 0.0;
}

/** Coefficient de temps par machine (calibration). 1.0 = pas de correction. */
function i3db_time_factor($printer) {
    $map = array(
        'a1'   => floatval(i3db_get('tf_a1', '1.0')),
        'p1s'  => floatval(i3db_get('tf_p1s', '1.0')),
        'v400' => floatval(i3db_get('tf_v400', '1.0')),
    );
    $f = isset($map[$printer]) ? $map[$printer] : 1.0;
    return $f > 0 ? $f : 1.0;
}

/* =====================================================================
 *  CORRESPONDANCES Lite -> nos clés
 * ===================================================================== */

/** Nom d'imprimante Lite -> clé slicer (a1 / p1s / v400). */
function i3db_printer_key($name) {
    $n = strtolower($name);
    if (strpos($n, 'v400') !== false) return 'v400';
    if (strpos($n, 'p1s')  !== false) return 'p1s';
    if (strpos($n, 'a1')   !== false) return 'a1';
    return '';
}

/** Nom de matériau Lite (ex. "PLA (1.75 mm) Green") -> notre clé matériau. */
function i3db_material_key_from_name($lite_name) {
    $ln = strtolower($lite_name);
    foreach (i3db_materials() as $key => $m) {
        if ($m['name'] !== '' && strpos($ln, strtolower($m['name'])) !== false) {
            return $key;
        }
    }
    return '';
}

/* =====================================================================
 *  APPEL AU SLICER + CALCUL DU PRIX
 * ===================================================================== */

function i3db_call_slicer($stl_path, $fields, $endpoint = '/slice') {
    $base = rtrim(i3db_get('slicer_url', 'http://127.0.0.1:8099'), '/');
    $key  = i3db_get('api_key', '');

    $boundary = wp_generate_password(24, false);
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--$boundary\r\n";
        $body .= "Content-Disposition: form-data; name=\"$name\"\r\n\r\n";
        $body .= $value . "\r\n";
    }
    $body .= "--$boundary\r\n";
    $body .= 'Content-Disposition: form-data; name="file"; filename="' . basename($stl_path) . "\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= file_get_contents($stl_path) . "\r\n";
    $body .= "--$boundary--\r\n";

    $resp = wp_remote_post($base . $endpoint, array(
        'timeout' => 200,
        'headers' => array('X-API-Key' => $key, 'Content-Type' => "multipart/form-data; boundary=$boundary"),
        'body'    => $body,
    ));

    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || empty($data['ok'])) {
        $msg = is_array($data) && isset($data['error']) ? $data['error'] : wp_remote_retrieve_body($resp);
        return new WP_Error('slicer', 'Slicer (HTTP ' . intval($code) . ') : ' . $msg);
    }
    return $data;
}

/* ---------------------------------------------------------------------
 *  Analyse "supports" appelée par le formulaire (juste après l'upload)
 * ------------------------------------------------------------------- */
add_action('wp_ajax_i3db_analyze', 'i3db_ajax_analyze');
add_action('wp_ajax_nopriv_i3db_analyze', 'i3db_ajax_analyze');
function i3db_ajax_analyze() {
    $model = isset($_POST['model']) ? basename(sanitize_file_name($_POST['model'])) : '';
    if ($model === '') wp_send_json(array('ok' => false));
    $upload = wp_upload_dir();
    $stl = $upload['basedir'] . '/p3d/' . $model;
    if (!file_exists($stl)) wp_send_json(array('ok' => false));
    $res = i3db_call_slicer($stl, array('scale' => '1'), '/analyze');
    if (is_wp_error($res)) wp_send_json(array('ok' => false));
    // Niveau selon la gravité du surplomb (fraction de surface en surplomb).
    $frac = isset($res['overhang_area_fraction']) ? (float) $res['overhang_area_fraction'] : 0.0;
    if ($frac > 0.06)     { $tier = 'required'; }     // surplomb important -> supports verrouillés
    elseif ($frac > 0.02) { $tier = 'recommended'; }  // surplomb léger -> conseillés (décochables)
    else                  { $tier = 'none'; }
    wp_send_json(array('ok' => true, 'tier' => $tier, 'fraction' => round($frac, 4)));
}

/* ---------------------------------------------------------------------
 *  Prix détaillé en direct sur le formulaire (sans et avec supports)
 * ------------------------------------------------------------------- */
add_action('wp_ajax_i3db_price', 'i3db_ajax_price');
add_action('wp_ajax_nopriv_i3db_price', 'i3db_ajax_price');
function i3db_ajax_price() {
    $model       = isset($_POST['model']) ? basename(sanitize_file_name($_POST['model'])) : '';
    $printer_id  = isset($_POST['printer'])  ? (int) $_POST['printer']  : 0;
    $material_id = isset($_POST['material']) ? (int) $_POST['material'] : 0;
    $infill_id   = isset($_POST['infill'])   ? (int) $_POST['infill']   : 0;
    $scale       = isset($_POST['scale']) ? (float) $_POST['scale'] : 1.0;
    if ($scale <= 0) $scale = 1.0;
    if ($model === '' || !function_exists('p3dlite_get_option')) wp_send_json(array('ok' => false));

    $upload = wp_upload_dir();
    $stl = $upload['basedir'] . '/p3d/' . $model;
    if (!file_exists($stl)) wp_send_json(array('ok' => false));

    $printers  = p3dlite_get_option('p3dlite_printers');
    $materials = p3dlite_get_option('p3dlite_materials');
    $infills   = p3dlite_get_option('p3dlite_infills');
    $printer_name  = isset($printers[$printer_id]['name'])  ? $printers[$printer_id]['name']  : '';
    $material_name = isset($materials[$material_id]['name']) ? $materials[$material_id]['name'] : '';
    $infill_pct    = isset($infills[$infill_id]['infill'])   ? (int) round($infills[$infill_id]['infill']) : 100;
    $machine_key  = i3db_printer_key($printer_name);
    $material_key = i3db_material_key_from_name($material_name);
    if (!$machine_key || !$material_key) wp_send_json(array('ok' => false));

    // Une seule découpe, pour l'état "supports" demandé (base OU avec supports).
    $supports = !empty($_POST['supports']);
    $q = i3db_quote($stl, $machine_key, $material_key, $infill_pct, $supports, $scale);
    if (is_wp_error($q)) wp_send_json(array('ok' => false, 'error' => $q->get_error_message()));
    wp_send_json(array(
        'ok'            => true,
        'supports'      => $supports ? 1 : 0,
        'fits'          => (bool) $q['fits'],
        'material'      => $q['cost_material'],
        'time'          => $q['cost_time'],
        'base'          => $q['base_fee'],
        'total'         => $q['price'],
        'sym'           => html_entity_decode(get_woocommerce_currency_symbol()),
        'dimensions_mm' => $q['dimensions_mm'],
        'weight_g'      => $q['weight_g'],
        'hours'         => $q['hours'],
        'printer_key'   => $machine_key,
    ));
}

/** Calcule un devis complet pour un fichier. Renvoie un tableau ou un WP_Error. */
function i3db_quote($stl_path, $printer, $material_key, $infill, $supports, $scale) {
    $materials = i3db_materials();
    if (!isset($materials[$material_key])) {
        return new WP_Error('material', 'Matériau inconnu : ' . $material_key);
    }
    $mat = $materials[$material_key];

    $data = i3db_call_slicer($stl_path, array(
        'printer'          => $printer,
        'infill'           => (string)intval($infill),
        'material_density' => $mat['density'],
        'supports'         => $supports ? '1' : '0',
        'scale'            => $scale,
    ));
    if (is_wp_error($data)) return $data;

    $base_fee      = floatval(i3db_get('base_fee', '0'));
    $cost_material = $data['weight_g'] * $mat['price_g'];
    $hours         = $data['print_time_hours'] * i3db_time_factor($printer);
    $cost_time     = $hours * i3db_machine_rate($printer);
    $price         = $cost_material + $cost_time + $base_fee;

    return array(
        'price'         => round($price, 2),
        'cost_material' => round($cost_material, 2),
        'cost_time'     => round($cost_time, 2),
        'base_fee'      => round($base_fee, 2),
        'weight_g'      => $data['weight_g'],
        'hours'         => round($hours, 3),
        'fits'          => $data['fits'],
        'dimensions_mm' => $data['dimensions_mm'],
        'material'      => $mat['name'],
        'printer'       => $data['printer'],
    );
}

/* =====================================================================
 *  PRODUIT WOOCOMMERCE GÉNÉRIQUE (créé une fois, à la volée)
 * ===================================================================== */

function i3db_product_id() {
    $id = (int) i3db_get('product_id', 0);
    if ($id && function_exists('wc_get_product') && wc_get_product($id)) {
        return $id;
    }
    if (!class_exists('WC_Product_Simple')) return 0;
    $p = new WC_Product_Simple();
    $p->set_name('Impression 3D sur mesure');
    $p->set_status('publish');
    $p->set_catalog_visibility('hidden');
    $p->set_regular_price('0');
    $p->set_sold_individually(false);
    $id = $p->save();
    i3db_set('product_id', $id);
    return $id;
}

/* =====================================================================
 *  INTERCEPTION DU FORMULAIRE -> CALCUL -> PANIER
 * ===================================================================== */

// On désactive le traitement "devis" de 3DPrint Lite quand c'est notre formulaire,
// pour le remplacer par l'ajout au panier (évite l'e-mail de devis en double).
add_action('init', function () {
    if (!empty($_POST['action']) && $_POST['action'] === 'request_price') {
        remove_action('init', 'p3dlite_request_price');
    }
}, 1);

// Notre traitement, sur wp_loaded : à ce moment le panier WooCommerce est prêt.
add_action('wp_loaded', 'i3db_process_to_cart', 99);
function i3db_process_to_cart() {
    if (empty($_POST['action']) || $_POST['action'] !== 'request_price') return;
    if (empty($_POST['p3d_price_request']) || !wp_verify_nonce($_POST['p3d_price_request'], 'request')) return;
    if (!function_exists('WC') || !WC()->cart) return;
    if (!function_exists('p3dlite_get_option')) return;

    // Choix du client
    $printer_id  = isset($_POST['attribute_pa_p3dlite_printer'])  ? (int) $_POST['attribute_pa_p3dlite_printer']  : 0;
    $material_id = isset($_POST['attribute_pa_p3dlite_material']) ? (int) $_POST['attribute_pa_p3dlite_material'] : 0;
    $infill_id   = isset($_POST['attribute_pa_p3dlite_infill'])   ? (int) $_POST['attribute_pa_p3dlite_infill']   : 0;
    $model_file  = isset($_POST['attribute_pa_p3dlite_model'])    ? basename(sanitize_file_name($_POST['attribute_pa_p3dlite_model'])) : '';
    $quantity    = isset($_POST['p3dlite_quantity']) ? max(1, (int) $_POST['p3dlite_quantity']) : 1;

    // Résolution des noms via les tables de 3DPrint Lite
    $printers  = p3dlite_get_option('p3dlite_printers');
    $materials = p3dlite_get_option('p3dlite_materials');
    $infills   = p3dlite_get_option('p3dlite_infills');

    $printer_name  = isset($printers[$printer_id]['name'])   ? $printers[$printer_id]['name']   : '';
    $material_name = isset($materials[$material_id]['name'])  ? $materials[$material_id]['name'] : '';
    $infill_pct    = isset($infills[$infill_id]['infill'])    ? (int) round($infills[$infill_id]['infill']) : 100;

    $machine_key  = i3db_printer_key($printer_name);
    $material_key = i3db_material_key_from_name($material_name);

    // Fichier déposé par 3DPrint Lite
    $upload = wp_upload_dir();
    $stl_path = $upload['basedir'] . '/p3d/' . $model_file;

    if (!$machine_key || !$material_key || $model_file === '' || !file_exists($stl_path)) {
        i3db_cart_error('Impossible de retrouver la machine, le matériau ou le fichier. Vérifie les noms côté 3DPrint Lite.');
        return;
    }

    // Supports demandés par le client (case ajoutée par notre pont)
    $supports = !empty($_POST['i3db_supports']);

    // Échelle choisie (multiplicateur déjà prêt côté Lite : 1 = 100 %, 25.4 si pouces).
    $scale = isset($_POST['p3dlite_resize_scale']) ? (float) $_POST['p3dlite_resize_scale'] : 1.0;
    if ($scale <= 0) $scale = 1.0;

    // Calcul (au prix réel, échelle comprise)
    $q = i3db_quote($stl_path, $machine_key, $material_key, $infill_pct, $supports, $scale);
    if (is_wp_error($q)) { i3db_cart_error($q->get_error_message()); return; }
    if (empty($q['fits'])) { i3db_cart_error('Le modèle ne rentre pas dans la machine choisie (' . $printer_name . ').'); return; }
    if ($q['price'] <= 0)  { i3db_cart_error('Prix calculé nul, vérifie tes tarifs.'); return; }

    $pid = i3db_product_id();
    if (!$pid) { i3db_cart_error('Produit WooCommerce introuvable.'); return; }

    // Ajout au panier avec toutes les infos rattachées
    WC()->cart->add_to_cart($pid, $quantity, 0, array(), array('i3db' => array(
        'price'    => $q['price'],
        'model'    => $model_file,
        'printer'  => $printer_name,
        'material' => $material_name,
        'infill'   => $infill_pct,
        'weight'   => $q['weight_g'],
        'hours'    => $q['hours'],
        'dim'      => $q['dimensions_mm'],
        'unique'   => md5($model_file . $machine_key . $material_key . $infill_pct . microtime(true)),
    )));

    wp_safe_redirect(wc_get_cart_url());
    exit;
}

/** Affiche une erreur sur la page panier et y redirige. */
function i3db_cart_error($msg) {
    if (function_exists('wc_add_notice')) {
        wc_add_notice('Devis impression 3D : ' . $msg, 'error');
    }
    wp_safe_redirect(wc_get_cart_url());
    exit;
}

/* =====================================================================
 *  PRIX PERSONNALISÉ + AFFICHAGE DES INFOS DANS LE PANIER / LA COMMANDE
 * ===================================================================== */

// Applique notre prix calculé à chaque ligne concernée.
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['i3db']['price']) && isset($item['data'])) {
            $item['data']->set_price((float) $item['i3db']['price']);
        }
    }
}, 20, 1);

// Montre les caractéristiques dans le panier.
add_filter('woocommerce_get_item_data', function ($data, $cart_item) {
    if (!empty($cart_item['i3db'])) {
        $d = $cart_item['i3db'];
        $data[] = array('name' => 'Machine',      'value' => $d['printer']);
        $data[] = array('name' => 'Matériau',     'value' => $d['material']);
        $data[] = array('name' => 'Remplissage',  'value' => $d['infill'] . ' %');
        $data[] = array('name' => 'Poids estimé', 'value' => $d['weight'] . ' g');
        $data[] = array('name' => 'Fichier',      'value' => $d['model']);
    }
    return $data;
}, 10, 2);

// Enregistre les caractéristiques dans la commande (visible côté admin).
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    if (!empty($values['i3db'])) {
        $d = $values['i3db'];
        $item->add_meta_data('Machine', $d['printer']);
        $item->add_meta_data('Matériau', $d['material']);
        $item->add_meta_data('Remplissage', $d['infill'] . ' %');
        $item->add_meta_data('Poids estimé', $d['weight'] . ' g');
        $item->add_meta_data('Temps estimé', $d['hours'] . ' h');
        $item->add_meta_data('Fichier', $d['model']);
    }
}, 10, 4);

// Déclare la compatibilité avec le stockage de commandes HPOS de WooCommerce.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/* =====================================================================
 *  CONSERVATION DU FICHIER STL PAR COMMANDE + LIEN DE TÉLÉCHARGEMENT
 * ===================================================================== */

/** Copie le STL d'une commande dans un dossier protégé rangé par n° de commande. */
function i3db_store_order_file($order_id, $filename) {
    $upload = wp_upload_dir();
    $src = $upload['basedir'] . '/p3d/' . $filename;
    if (!file_exists($src)) return false;
    $base = $upload['basedir'] . '/i3db-orders';
    $dir  = $base . '/' . intval($order_id);
    if (!file_exists($dir)) wp_mkdir_p($dir);
    // Garde-fou : interdit l'accès direct au dossier (si serveur Apache).
    $ht = $base . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    $dest = $dir . '/' . $filename;
    if (!file_exists($dest)) @copy($src, $dest);
    return file_exists($dest);
}

/** À la création de la commande, copie le(s) fichier(s) et marque la ligne. */
function i3db_save_order_files($order_id, $order = null) {
    if (!$order) $order = wc_get_order($order_id);
    if (!$order) return;
    foreach ($order->get_items() as $item) {
        $file = $item->get_meta('Fichier');
        if (!$file) continue;
        if (i3db_store_order_file($order_id, $file)) {
            $item->update_meta_data('_i3db_file', $file);
            $item->save();
        }
    }
}
// Checkout classique
add_action('woocommerce_checkout_order_processed', function ($order_id, $posted, $order) {
    i3db_save_order_files($order_id, $order);
}, 20, 3);
// Checkout en blocs (Store API)
add_action('woocommerce_store_api_checkout_order_processed', function ($order) {
    i3db_save_order_files($order->get_id(), $order);
}, 20, 1);

/** Bouton "Télécharger le STL" sous la ligne de commande, côté admin. */
add_action('woocommerce_after_order_itemmeta', function ($item_id, $item, $product) {
    if (!is_a($item, 'WC_Order_Item_Product')) return;
    $file = $item->get_meta('_i3db_file');
    if (!$file) return;
    $order_id = $item->get_order_id();
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=i3db_download&order=' . $order_id . '&file=' . rawurlencode($file)),
        'i3db_dl_' . $order_id
    );
    echo '<p style="margin:6px 0"><a href="' . esc_url($url) . '" class="button">⬇ Télécharger le STL</a></p>';
}, 10, 3);

/** Téléchargement sécurisé (réservé aux gestionnaires de la boutique). */
add_action('admin_post_i3db_download', function () {
    if (!current_user_can('manage_woocommerce')) wp_die('Accès refusé.');
    $order_id = isset($_GET['order']) ? intval($_GET['order']) : 0;
    $file     = isset($_GET['file']) ? basename(sanitize_file_name($_GET['file'])) : '';
    $nonce    = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (!$order_id || $file === '' || !wp_verify_nonce($nonce, 'i3db_dl_' . $order_id)) {
        wp_die('Lien invalide.');
    }
    $upload = wp_upload_dir();
    $path = $upload['basedir'] . '/i3db-orders/' . $order_id . '/' . $file;
    if (!file_exists($path)) wp_die('Fichier introuvable.');
    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
});

/* =====================================================================
 *  PAGE D'ADMINISTRATION (réglages + tests)
 * ===================================================================== */

add_action('admin_menu', function () {
    add_options_page('Pont Impression 3D', 'Pont Impression 3D', 'manage_options', 'i3db', 'i3db_render_page');
});

function i3db_render_page() {
    if (!current_user_can('manage_options')) return;

    $notice = ''; $test_conn = ''; $quote_out = '';

    if (isset($_POST['i3db_save']) && check_admin_referer('i3db_save_action')) {
        $o = get_option('i3db_settings', array());
        $o['slicer_url'] = esc_url_raw(trim($_POST['slicer_url']));
        $o['api_key']    = sanitize_text_field($_POST['api_key']);
        $o['materials']  = sanitize_textarea_field($_POST['materials']);
        $o['base_fee']   = sanitize_text_field($_POST['base_fee']);
        $o['rate_a1']    = sanitize_text_field($_POST['rate_a1']);
        $o['rate_p1s']   = sanitize_text_field($_POST['rate_p1s']);
        $o['rate_v400']  = sanitize_text_field($_POST['rate_v400']);
        $o['tf_a1']      = sanitize_text_field($_POST['tf_a1']);
        $o['tf_p1s']     = sanitize_text_field($_POST['tf_p1s']);
        $o['tf_v400']    = sanitize_text_field($_POST['tf_v400']);
        update_option('i3db_settings', $o);
        $notice = 'Réglages enregistrés.';
    }

    if (isset($_POST['i3db_test']) && check_admin_referer('i3db_test_action')) {
        $base = rtrim(i3db_get('slicer_url', 'http://127.0.0.1:8099'), '/');
        $resp = wp_remote_get($base . '/health', array('timeout' => 10));
        $test_conn = is_wp_error($resp)
            ? 'ÉCHEC : ' . esc_html($resp->get_error_message())
            : 'Réponse HTTP ' . intval(wp_remote_retrieve_response_code($resp)) . ' : ' . esc_html(wp_remote_retrieve_body($resp));
    }

    if (isset($_POST['i3db_quote']) && check_admin_referer('i3db_quote_action') && !empty($_FILES['test_stl']['tmp_name'])) {
        $q = i3db_quote($_FILES['test_stl']['tmp_name'], sanitize_text_field($_POST['t_printer']),
            sanitize_text_field($_POST['t_material']), sanitize_text_field($_POST['t_infill']),
            !empty($_POST['t_supports']), sanitize_text_field($_POST['t_scale']));
        if (is_wp_error($q)) {
            $quote_out = 'ERREUR : ' . esc_html($q->get_error_message());
        } else {
            $dim = implode(' × ', array_map('floatval', $q['dimensions_mm']));
            $quote_out = 'Matériau : ' . esc_html($q['material']) . ' sur ' . esc_html($q['printer']) . "\n"
                . 'Dimensions : ' . esc_html($dim) . " mm  (rentre : " . ($q['fits'] ? 'oui' : 'NON') . ")\n"
                . 'Poids : ' . esc_html($q['weight_g']) . " g\nTemps : " . esc_html($q['hours']) . " h\n---\n"
                . 'Coût matière : ' . esc_html($q['cost_material']) . " €\nCoût temps   : " . esc_html($q['cost_time']) . " €\n"
                . 'Forfait      : ' . esc_html($q['base_fee']) . " €\nPRIX TOTAL   : " . esc_html($q['price']) . " €";
        }
    }

    $slicer_url = i3db_get('slicer_url', 'http://127.0.0.1:8099');
    $api_key    = i3db_get('api_key', '');
    $materials  = i3db_get('materials', "pla|PLA|1.24|0.05\npetg|PETG|1.27|0.06");
    $base_fee   = i3db_get('base_fee', '0');
    $mats       = i3db_materials();
    ?>
    <div class="wrap">
        <h1>Pont Impression 3D</h1>
        <p style="color:#888;margin-top:-6px">Build : <code><?php echo esc_html(defined('I3DB_BUILD') ? I3DB_BUILD : '?'); ?></code></p>
        <?php if ($notice): ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <h2>Réglages</h2>
        <form method="post">
            <?php wp_nonce_field('i3db_save_action'); ?>
            <table class="form-table">
                <tr><th>Adresse du slicer</th><td><input type="text" name="slicer_url" value="<?php echo esc_attr($slicer_url); ?>" class="regular-text"><p class="description">Même machine : <code>http://127.0.0.1:8099</code></p></td></tr>
                <tr><th>Clé secrète</th><td><input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td></tr>
                <tr><th>Matériaux</th><td><textarea name="materials" rows="4" class="large-text" style="font-family:monospace"><?php echo esc_textarea($materials); ?></textarea><p class="description"><code>cle|Nom|densité|prix_au_gramme</code> (le Nom doit se retrouver dans le nom du matériau côté 3DPrint Lite)</p></td></tr>
                <tr><th>Prix horaire (€/h)</th><td>A1 : <input type="text" name="rate_a1" value="<?php echo esc_attr(i3db_get('rate_a1','1.5')); ?>" size="6"> P1S : <input type="text" name="rate_p1s" value="<?php echo esc_attr(i3db_get('rate_p1s','1.5')); ?>" size="6"> V400 : <input type="text" name="rate_v400" value="<?php echo esc_attr(i3db_get('rate_v400','1.5')); ?>" size="6"></td></tr>
                <tr><th>Coefficient de temps<br><span style="font-weight:normal;font-size:11px">(calibration : temps réel ÷ temps estimé. 1 = aucune correction)</span></th><td>A1 : <input type="text" name="tf_a1" value="<?php echo esc_attr(i3db_get('tf_a1','1.0')); ?>" size="6"> P1S : <input type="text" name="tf_p1s" value="<?php echo esc_attr(i3db_get('tf_p1s','1.0')); ?>" size="6"> V400 : <input type="text" name="tf_v400" value="<?php echo esc_attr(i3db_get('tf_v400','1.0')); ?>" size="6"></td></tr>
                <tr><th>Forfait de base (€)</th><td><input type="text" name="base_fee" value="<?php echo esc_attr($base_fee); ?>" size="6"></td></tr>
            </table>
            <p><button type="submit" name="i3db_save" class="button button-primary">Enregistrer</button></p>
        </form>
        <hr>
        <h2>Test de connexion</h2>
        <form method="post"><?php wp_nonce_field('i3db_test_action'); ?><p><button type="submit" name="i3db_test" class="button">Tester la connexion</button></p></form>
        <?php if ($test_conn): ?><div class="notice notice-info"><p><strong>Résultat :</strong> <?php echo $test_conn; ?></p></div><?php endif; ?>
        <hr>
        <h2>Test de devis</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('i3db_quote_action'); ?>
            <table class="form-table">
                <tr><th>Fichier STL</th><td><input type="file" name="test_stl" accept=".stl" required></td></tr>
                <tr><th>Machine</th><td><select name="t_printer"><option value="a1">Bambu Lab A1</option><option value="p1s">Bambu Lab P1S</option><option value="v400">FLSUN V400</option></select></td></tr>
                <tr><th>Matériau</th><td><select name="t_material"><?php foreach ($mats as $k => $m): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($m['name']); ?></option><?php endforeach; ?></select></td></tr>
                <tr><th>Remplissage (%)</th><td><input type="text" name="t_infill" value="20" size="4"></td></tr>
                <tr><th>Échelle</th><td><input type="text" name="t_scale" value="1" size="4"></td></tr>
                <tr><th>Supports</th><td><label><input type="checkbox" name="t_supports" value="1"> activer</label></td></tr>
            </table>
            <p><button type="submit" name="i3db_quote" class="button">Calculer le devis</button></p>
        </form>
        <?php if ($quote_out): ?><div class="notice notice-info"><pre style="white-space:pre-wrap;margin:0"><?php echo esc_html($quote_out); ?></pre></div><?php endif; ?>
    </div>
    <?php
}


/* =====================================================================
 *  PERSONNALISATION DU FORMULAIRE CLIENT (sans modifier 3DPrint Lite)
 * ===================================================================== */

// Renomme le bouton "Request a Quote" -> "Ajouter au panier".
add_filter('gettext', function ($translated, $text, $domain) {
    if ($domain === '3dprint-lite') {
        if ($text === 'Request a Quote') return 'Ajouter au panier';
        if ($text === 'Estimated Price:') return 'Prix estime :';
    }
    return $translated;
}, 20, 3);

// Case "supports", masquage e-mail/commentaire, et style propre.
add_action('wp_footer', function () {
    ?>
    <style>
    form.p3dlite_form .price-request-field { display:block; width:100%; max-width:340px; margin:8px 0; padding:10px 12px; border:1px solid #d9d9d9; border-radius:8px; box-sizing:border-box; }
    form.p3dlite_form input[name="p3dlite_email_address"],
    form.p3dlite_form input[name="p3dlite_request_comment"] { display:none !important; }
    #price-wrapper, #price-container { display:none !important; }
    form.p3dlite_form .i3db-supports { display:flex; align-items:center; gap:8px; margin:14px 0; font-size:15px; cursor:pointer; }
    form.p3dlite_form .i3db-supports input:disabled { cursor:not-allowed; }
    form.p3dlite_form .i3db-supports input:disabled + span { color:#8a8a8a; }
    form.p3dlite_form button[type="submit"].button.alt { float:none !important; width:100%; max-width:340px; padding:14px 18px; font-size:16px; font-weight:600; border:0; border-radius:10px; cursor:pointer; }
    #i3db-support-note { display:none; max-width:340px; margin:10px 0; padding:10px 12px; border-radius:8px; font-size:14px; line-height:1.4; }
    #i3db-support-note.warn { background:#fff4e5; border:1px solid #ffce85; color:#7a4b00; }
    #i3db-support-note.ok   { background:#eef7ee; border:1px solid #bcdcb8; color:#2f5d2a; }
    #i3db-price { display:none; max-width:340px; margin:14px 0; border:1px solid #e2e2e2; border-radius:10px; padding:14px 16px; background:#fafafa; }
    #i3db-price .i3db-title { font-weight:700; margin-bottom:10px; font-size:15px; }
    #i3db-price .i3db-line { display:flex; justify-content:space-between; padding:4px 0; font-size:14px; }
    #i3db-price .i3db-line.muted { color:#9a9a9a; }
    #i3db-price .i3db-line.total { border-top:1px solid #ddd; margin-top:8px; padding-top:8px; font-weight:700; font-size:17px; }
    #i3db-price .i3db-loading { color:#666; font-size:14px; }
    #i3db-price .i3db-warn { color:#9a3b00; font-size:14px; }
    </style>
    <script>
    (function () {
        var AJAX = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        var sym = '\u20AC';

        /* ---- State ---- */
        var lastModel = '';
        var curCfgKey = '';
        var baseWithout = null;   // scale-1 result, supports OFF
        var baseWith    = null;   // scale-1 result, supports ON
        var debounceTimer = null;
        var controllers   = [];   // active AbortControllers

        /* ---- Enveloppes machine ----
           Doivent rester identiques au dictionnaire MACHINES de slicer/app.py :
           mêmes dimensions ET même règle de contrôle, sinon le site et le
           service de découpe se contredisent. */
        var BUILDS = {
            a1:   { box: [256, 256], z: 256 },
            p1s:  { box: [256, 256], z: 256 },
            v400: { diameter: 300,   z: 410 }
        };

        /* ---- Helpers ---- */
        function q(n){ var e=document.querySelector('form.p3dlite_form [name="'+n+'"]'); return e?e.value:''; }
        function supOn(){ var b=document.querySelector('.i3db-box'); return b?b.checked:false; }
        function money(v,s){ return (Math.round(v*100)/100).toFixed(2)+' '+s; }
        function row(l,v,c){ return '<div class="i3db-line '+(c||'')+'"><span>'+l+'</span><span>'+v+'</span></div>'; }
        function getScale(){ var s=parseFloat(q('p3dlite_resize_scale'))||1; return s>0?s:1; }

        /* Config key WITHOUT scale (slicer only needs machine/mat/infill/model) */
        function cfgKey(){
            var model=q('attribute_pa_p3dlite_model'), printer=q('attribute_pa_p3dlite_printer'),
                material=q('attribute_pa_p3dlite_material'), infill=q('attribute_pa_p3dlite_infill');
            if(!model||!printer||!material) return null;
            return {model:model,printer:printer,material:material,infill:infill,
                    key:model+'|'+printer+'|'+material+'|'+infill};
        }

        /* ---- Contrôle "ça rentre" ----
           Reproduit exactement fits_in_machine() côté serveur :
           - plateau rectangulaire : l'empreinte tient à plat, dans un sens ou
             dans l'autre (on ne couche pas la pièce, la hauteur reste la hauteur) ;
           - plateau rond (delta)  : la diagonale de l'empreinte tient dans le
             cercle. C'est bien la diagonale, pas le plus grand côté : une pièce
             de 280 x 80 passe sur un plateau de 300 (diagonale 291). */
        function checkFits(dims, pk){
            var b=BUILDS[pk]; if(!b) return false;
            if(dims[2] > b.z) return false;
            if(b.box){
                return (dims[0]<=b.box[0] && dims[1]<=b.box[1]) ||
                       (dims[0]<=b.box[1] && dims[1]<=b.box[0]);
            }
            return Math.sqrt(dims[0]*dims[0] + dims[1]*dims[1]) <= b.diameter;
        }

        /* ---- Scale extrapolation (math, no slicer call) ---- */
        function extrapolate(base, scale){
            if(!base||!base.ok) return base;
            var s3=scale*scale*scale;
            var dims=base.dimensions_mm.map(function(d){return d*scale;});
            return {
                ok:true,
                /* À l'échelle 1, c'est le verdict du serveur qui fait foi ;
                   au-delà on refait le calcul avec la même règle. */
                fits: (scale===1) ? !!base.fits : checkFits(dims, base.printer_key),
                material: Math.round(base.material*s3*100)/100,
                time:     Math.round(base.time*s3*100)/100,
                base:     base.base,
                total:    Math.round((base.material*s3 + base.time*s3 + base.base)*100)/100
            };
        }

        /* ---- Abort all in-flight requests ---- */
        function abortAll(){
            controllers.forEach(function(c){ try{c.abort();}catch(e){} });
            controllers=[];
        }

        /* ---- Fetch from slicer (always scale=1) ---- */
        function fetchBase(c, sup, cb){
            var ctrl=new AbortController(); controllers.push(ctrl);
            var d=new FormData();
            d.append('action','i3db_price'); d.append('model',c.model);
            d.append('printer',c.printer);   d.append('material',c.material);
            d.append('infill',c.infill);     d.append('scale','1');
            d.append('supports',sup);
            fetch(AJAX,{method:'POST',body:d,credentials:'same-origin',signal:ctrl.signal})
                .then(function(r){return r.json();})
                .then(function(j){cb(j);})
                .catch(function(e){ if(e.name!=='AbortError') cb(null); });
        }

        /* ---- Loading indicator ---- */
        function showLoading(){
            var box=document.getElementById('i3db-price');
            if(box){ box.style.display='block';
                box.querySelector('.i3db-body').innerHTML='<div class="i3db-loading">Calcul du prix en cours\u2026</div>'; }
        }

        /* ---- Trigger slicer (one call at scale 1, supports off then on) ---- */
        function triggerSlice(){
            var c=cfgKey(); if(!c) return;
            if(c.key===curCfgKey && baseWithout){ renderPrice(); return; }
            curCfgKey=c.key; baseWithout=null; baseWith=null;
            abortAll(); showLoading();
            var saved=c.key;
            fetchBase(c, 0, function(j){
                if(saved!==curCfgKey) return;
                baseWithout=j; if(j&&j.sym) sym=j.sym;
                renderPrice();
                fetchBase(c, 1, function(j2){
                    if(saved!==curCfgKey) return;
                    baseWith=j2; renderPrice();
                });
            });
        }

        /* ---- Render price (extrapolated to current scale) ---- */
        function renderPrice(){
            var box=document.getElementById('i3db-price'); if(!box) return;
            var body=box.querySelector('.i3db-body'); box.style.display='block';
            var scale=getScale();
            var data=baseWithout ? extrapolate(baseWithout, scale) : null;
            if(!data){ body.innerHTML='<div class="i3db-loading">Calcul du prix en cours\u2026</div>'; return; }
            if(!data.ok){ body.innerHTML='<div class="i3db-warn">Devis indisponible pour cette configuration.</div>'; return; }
            if(!data.fits){ body.innerHTML='<div class="i3db-warn">\u26A0 La pi\u00e8ce ne rentre pas dans la machine choisie.</div>'; return; }
            var on=supOn(), html='';
            html+=row('Mati\u00e8re', money(data.material,sym));
            html+=row('Temps machine', money(data.time,sym));
            if(data.base>0) html+=row('Forfait', money(data.base,sym));
            var supData=baseWith ? extrapolate(baseWith, scale) : null;
            var supCost=(supData&&supData.ok)?Math.max(0,supData.total-data.total):null;
            html+=row('Supports'+(on?'':' (si activ\u00e9s)'), supCost===null?'\u2026':'+ '+money(supCost,sym), on?'':'muted');
            var total=(on&&supData&&supData.ok)?supData.total:data.total;
            html+=row('Total', money(total,sym), 'total');
            body.innerHTML=html;
        }

        /* ---- Support tier analysis (unchanged) ---- */
        function ensureNote(){
            var form=document.querySelector('form.p3dlite_form'); if(!form) return null;
            var n=document.getElementById('i3db-support-note');
            if(!n){ n=document.createElement('div'); n.id='i3db-support-note';
                var sup=form.querySelector('.i3db-supports');
                if(sup) sup.parentNode.insertBefore(n,sup); else form.appendChild(n); }
            n.style.display='block'; return n;
        }
        function addHidden(form){ if(document.getElementById('i3db-sup-hidden'))return;
            var h=document.createElement('input'); h.type='hidden'; h.id='i3db-sup-hidden'; h.name='i3db_supports'; h.value='1'; form.appendChild(h); }
        function removeHidden(){ var h=document.getElementById('i3db-sup-hidden'); if(h) h.parentNode.removeChild(h); }
        function applyTier(tier){
            var box=document.querySelector('.i3db-box'); if(!box) return;
            var form=document.querySelector('form.p3dlite_form'); var n=ensureNote(); removeHidden();
            if(tier==='required'){
                box.checked=true; box.disabled=true; addHidden(form);
                n.className='warn'; n.textContent="\u26A0 Supports requis : cette pi\u00e8ce pr\u00e9sente des surplombs importants. Ils sont inclus automatiquement.";
            } else if(tier==='recommended'){
                box.checked=true; box.disabled=false;
                n.className='warn'; n.textContent="\u26A0 Supports fortement conseill\u00e9s : cette pi\u00e8ce pr\u00e9sente des surplombs. Vous pouvez les retirer, \u00e0 vos risques.";
            } else {
                box.checked=false; box.disabled=false;
                n.className='ok'; n.textContent="\u2713 Supports non n\u00e9cessaires pour cette pi\u00e8ce.";
            }
            renderPrice();
        }
        function analyze(model){
            var ctrl=new AbortController(); controllers.push(ctrl);
            var d=new FormData(); d.append('action','i3db_analyze'); d.append('model',model);
            fetch(AJAX,{method:'POST',body:d,credentials:'same-origin',signal:ctrl.signal})
                .then(function(r){return r.json();})
                .then(function(j){ if(j&&j.ok) applyTier(j.tier); })
                .catch(function(){});
        }

        /* ---- Debounced config change handler ---- */
        function onConfigChange(){
            clearTimeout(debounceTimer);
            showLoading();
            debounceTimer=setTimeout(triggerSlice, 2000);
        }

        /* ---- DOM setup (wait for 3DPrint Lite form) ---- */
        var tries=0;
        var iv=setInterval(function(){
            var form=document.querySelector('form.p3dlite_form');
            var btn=form?form.querySelector('button[type="submit"]'):null;
            if(form&&btn){
                clearInterval(iv);
                var em=form.querySelector('[name="p3dlite_email_address"]');
                if(em&&!em.value) em.value='panier@impression3d.local';
                if(!document.getElementById('i3db-price')){
                    var pb=document.createElement('div'); pb.id='i3db-price';
                    pb.innerHTML='<div class="i3db-title">Votre devis</div><div class="i3db-body"></div>';
                    btn.parentNode.insertBefore(pb,btn);
                }
                if(!document.querySelector('.i3db-box')){
                    var lbl=document.createElement('label'); lbl.className='i3db-supports';
                    lbl.innerHTML='<input type="checkbox" class="i3db-box" name="i3db_supports" value="1"> <span>Ajouter des supports d\'impression (si la pi\u00e8ce en a besoin)</span>';
                    btn.parentNode.insertBefore(lbl,btn);
                }

                /* Event listeners: config changes -> debounce 2s */
                ['attribute_pa_p3dlite_printer','attribute_pa_p3dlite_material','attribute_pa_p3dlite_infill'].forEach(function(name){
                    var el=form.querySelector('[name="'+name+'"]');
                    if(el) el.addEventListener('change', onConfigChange);
                });

                /* Scale change -> instant recalc (no slicer call) */
                var scaleEl=form.querySelector('[name="p3dlite_resize_scale"]');
                if(scaleEl){
                    scaleEl.addEventListener('input', renderPrice);
                    scaleEl.addEventListener('change', renderPrice);
                }

                /* Supports checkbox -> instant recalc */
                document.addEventListener('change', function(e){
                    if(e.target&&e.target.name==='i3db_supports') renderPrice();
                });
            }
            if(++tries>40) clearInterval(iv);
        },250);

        /* Model change detection (3DPrint Lite sets it via JS, must poll) */
        setInterval(function(){
            var mf=document.getElementById('pa_p3dlite_model');
            if(mf&&mf.value&&mf.value!==lastModel){
                lastModel=mf.value;
                analyze(mf.value);
                onConfigChange();
            }
        },1000);

        /* ---- Abort everything if user leaves the page ---- */
        window.addEventListener('beforeunload', abortAll);
    })();
    </script>
    <?php
});
