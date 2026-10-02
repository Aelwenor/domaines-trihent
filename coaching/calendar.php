<?php
/*
 * Agenda du coach a synchroniser avec son telephone (abonnement par URL secrete).
 * Chaque rendez-vous y apparait trajet compris, pour voir le vrai temps bloque.
 */
require __DIR__ . '/inc/bootstrap.php';

if (!hash_equals(setting('ics_key'), get('k'))) {
    http_response_code(403);
    exit('Accès refusé');
}

$st = db()->prepare("SELECT b.*, c.first_name, c.last_name, c.phone FROM bookings b JOIN clients c ON c.id = b.client_id
                     WHERE b.status IN ('pending', 'confirmed') AND b.start_at >= ? ORDER BY b.start_at");
$st->execute([date('Y-m-d', strtotime('-60 days'))]);

$events = [];
foreach ($st as $b) {
    $title = ($b['status'] === 'pending' ? '[À valider] ' : '') . "{$b['first_name']} {$b['last_name']} — {$b['service_name']}";
    $desc  = 'Séance : ' . fr_time($b['start_at']) . ' - ' . fr_time($b['end_at']) . "\n";
    if ($b['travel'] > 0) {
        $desc .= "Trajet : {$b['travel']} min aller / {$b['travel']} min retour\n";
    }
    $desc .= "Lieu : {$b['location_name']}\nTél : {$b['phone']}\n" . admin_link($b);
    $events[] = ics_event($b, $title, $b['occ_start'], $b['occ_end'], $desc);
}

header('Content-Type: text/calendar; charset=utf-8');
echo ics_calendar($events, brand_name());
