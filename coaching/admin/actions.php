<?php
/*
 * Actions du coach sur un rendez-vous (valider, refuser, annuler, deplacer, message).
 * Appele en POST depuis le tableau de bord et la fiche rendez-vous.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
csrf_check();

$b = find_booking((int) post('id'));
if (!$b) {
    flash('Rendez-vous introuvable.', 'error');
    redirect('admin/');
}
$back = post('back') === 'dashboard' ? 'admin/' : 'admin/rdv.php?id=' . $b['id'];
$note = mb_substr(post('note'), 0, 2000);

function set_status(array $b, string $status, string $note): void
{
    db()->prepare("UPDATE bookings SET status = ?, cancelled_by = ?, updated_at = ? WHERE id = ?")
        ->execute([$status, $status === 'cancelled' ? 'coach' : '', now_str(), $b['id']]);
    if ($note !== '') {
        add_message((int) $b['id'], 'coach', $note);
    }
    $b['status'] = $status;
    notify_client_status($b, $note);
}

switch (post('action')) {
    case 'confirm':
        if ($b['status'] === 'pending') {
            set_status($b, 'confirmed', $note);
            flash('Rendez-vous confirmé, le client est prévenu par email.');
        }
        break;

    case 'refuse':
        if ($b['status'] === 'pending') {
            set_status($b, 'refused', $note);
            flash('Demande refusée, le client est prévenu par email.');
        }
        break;

    case 'cancel':
        if (in_array($b['status'], ['pending', 'confirmed'], true)) {
            set_status($b, 'cancelled', $note);
            flash('Rendez-vous annulé, le client est prévenu par email.');
        }
        break;

    case 'move':
        $date = post('date');
        $time = post('time');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            flash('Date ou heure invalide.', 'error');
            break;
        }
        $duration = (int) ((strtotime($b['end_at']) - strtotime($b['start_at'])) / 60);
        $travel = (int) $b['travel'];
        if (slot_conflicts("$date $time", $duration, $travel, (int) $b['id']) && post('force') !== '1') {
            flash('Ce créneau chevauche un autre rendez-vous ou une indisponibilité (trajet compris). Cochez « forcer » pour le placer quand même.', 'error');
            break;
        }
        $t = booking_times("$date $time", $duration, $travel);
        $status = $b['status'] === 'pending' ? 'confirmed' : $b['status'];
        db()->prepare('UPDATE bookings SET start_at = ?, end_at = ?, occ_start = ?, occ_end = ?, status = ?, reminder_sent = 0, updated_at = ? WHERE id = ?')
            ->execute([$t['start_at'], $t['end_at'], $t['occ_start'], $t['occ_end'], $status, now_str(), $b['id']]);
        if ($note !== '') {
            add_message((int) $b['id'], 'coach', $note);
        }
        notify_moved(find_booking((int) $b['id']), $b['start_at'], 'coach');
        flash('Rendez-vous déplacé, le client est prévenu par email.');
        break;

    case 'message':
        if ($note !== '') {
            add_message((int) $b['id'], 'coach', $note);
            notify_message($b, 'coach', $note);
            flash('Message envoyé au client.');
        }
        break;
}
redirect($back);
