<?php
/* Permet d'installer l'espace coach comme une appli sur l'ecran d'accueil du telephone. */
require __DIR__ . '/inc/bootstrap.php';

$icons = [];
if (setting('logo') !== '' && is_file(UPLOAD_DIR . '/' . setting('logo'))) {
    $icons[] = ['src' => url('uploads/' . setting('logo')), 'sizes' => '512x512', 'purpose' => 'any'];
}
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => brand_name(),
    'short_name'       => mb_substr(brand_name(), 0, 12),
    'start_url'        => url(is_admin() ? 'admin/' : ''),
    'display'          => 'standalone',
    'background_color' => '#f6f7f9',
    'theme_color'      => setting('brand_color', '#0f766e'),
    'icons'            => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
