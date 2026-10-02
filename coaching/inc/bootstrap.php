<?php
/*
 * Point d'entree commun : charge la configuration, la base de donnees et les outils.
 * Chaque page de l'application commence par : require __DIR__ . '/inc/bootstrap.php';
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', APP_ROOT . '/uploads');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('coaching_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/slots.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/ics.php';
require __DIR__ . '/layout.php';
