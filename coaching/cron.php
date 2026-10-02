<?php
/*
 * Rappels automatiques par email (la veille de la seance, par defaut 24 h avant).
 * A lancer regulierement (ex. toutes les heures) via une "tache Cron" Hostinger :
 *     php /home/VOTRE_COMPTE/public_html/coaching/cron.php
 * ou par adresse web : https://votre-site/coaching/cron.php?k=CLE (cle visible dans Reglages).
 */
require __DIR__ . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli' && !hash_equals(setting('cron_key'), get('k'))) {
    http_response_code(403);
    exit('Accès refusé');
}

$limit = date('Y-m-d H:i', time() + setting_int('reminder_hours', 24) * 3600);
$st = db()->prepare("SELECT id FROM bookings WHERE status = 'confirmed' AND reminder_sent = 0 AND start_at > ? AND start_at <= ?");
$st->execute([date('Y-m-d H:i'), $limit]);

$sent = 0;
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $b = find_booking((int) $id);
    $body = "Bonjour {$b['first_name']},\n\nPetit rappel de votre séance :\n\n" . booking_summary($b)
          . "\nDétails et messages : " . client_link($b) . "\n";
    send_mail($b['email'], 'Rappel : votre séance ' . fr_date($b['start_at']) . ' à ' . fr_time($b['start_at']), $body, setting('coach_email'));
    db()->prepare('UPDATE bookings SET reminder_sent = 1 WHERE id = ?')->execute([$id]);
    $sent++;
}
echo "Rappels envoyés : $sent\n";
