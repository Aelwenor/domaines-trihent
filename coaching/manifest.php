<?php
/*
 * Permet d'installer l'application sur l'ecran d'accueil du telephone.
 * - coach : ouvre l'espace coach
 * - client (?c=jeton) : ouvre directement son espace personnel
 */
require __DIR__ . '/inc/bootstrap.php';

$client = find_client_by_token(get('c'));
$start = $client ? url('espace.php?c=' . $client['access_token']) : url(is_admin() ? 'admin/' : '');

$icons = [];
if (setting('logo') !== '' && is_file(UPLOAD_DIR . '/' . setting('logo'))) {
    [$w, $h] = @getimagesize(UPLOAD_DIR . '/' . setting('logo')) ?: [512, 512];
    $icons[] = ['src' => url('uploads/' . setting('logo')), 'sizes' => "{$w}x{$h}", 'purpose' => 'any'];
}
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => brand_name(),
    'short_name'       => mb_substr(brand_name(), 0, 12),
    'start_url'        => $start,
    'scope'            => url(''),
    'display'          => 'standalone',
    'background_color' => '#f6f7f9',
    'theme_color'      => setting('brand_color', '#0f766e'),
    'icons'            => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
