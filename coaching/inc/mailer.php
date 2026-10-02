<?php
/*
 * Envoi des emails (fonction mail() de PHP, disponible chez Hostinger).
 * Chaque envoi est aussi note dans data/mail.log pour garder une trace.
 */

function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = $_SERVER['HTTP_HOST'] ?? (parse_url(setting('site_url'), PHP_URL_HOST) ?: 'localhost');
    $host = preg_replace('/^www\./', '', (string) $host);
    $fromName = '=?UTF-8?B?' . base64_encode(brand_name()) . '?=';

    $headers  = "From: $fromName <no-reply@$host>\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= "Reply-To: $replyTo\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";

    $body .= "\n\n-- \n" . brand_name();
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $ok = getenv('COACHING_NO_MAIL') ? true : @mail($to, $subjectEnc, $body, $headers);
    @file_put_contents(DATA_DIR . '/mail.log',
        sprintf("[%s] %s -> %s : %s\n", now_str(), $ok ? 'OK' : 'ECHEC', $to, $subject), FILE_APPEND);
    return $ok;
}

function mail_coach(string $subject, string $body, string $replyTo = ''): bool
{
    return send_mail(setting('coach_email'), $subject, $body, $replyTo);
}

/* Resume d'un rendez-vous, reutilise dans tous les emails. */
function booking_summary(array $b): string
{
    $lines  = "Prestation : {$b['service_name']}\n";
    $lines .= 'Date       : ' . fr_slot($b['start_at'], $b['end_at']) . "\n";
    $lines .= "Lieu       : {$b['location_name']}\n";
    if ($b['address'] !== '') {
        $lines .= "Adresse    : {$b['address']}\n";
    }
    return $lines;
}

function client_link(array $b): string
{
    return absolute_url('rdv.php?t=' . $b['token']);
}

function admin_link(array $b): string
{
    return absolute_url('admin/rdv.php?id=' . $b['id']);
}

/* ---------- Notifications ---------- */

function notify_new_booking(array $b): void
{
    $client = "{$b['first_name']} {$b['last_name']}";
    $pending = $b['status'] === 'pending';

    $body  = $pending ? "Nouvelle demande de coaching à valider.\n\n" : "Nouveau rendez-vous réservé (validé automatiquement).\n\n";
    $body .= "Client     : $client" . ($b['age'] ? " ({$b['age']} ans)" : '') . "\n";
    $body .= "Téléphone  : {$b['phone']}\nEmail      : {$b['email']}\n";
    $body .= "Objectif   : {$b['objective']}\n";
    $body .= booking_summary($b);
    if ($b['travel'] > 0) {
        $body .= "Trajet     : {$b['travel']} min aller + {$b['travel']} min retour (bloqué de "
               . fr_time($b['occ_start']) . ' à ' . fr_time($b['occ_end']) . ")\n";
    }
    if ($b['client_note'] !== '') {
        $body .= "\nMessage du client :\n{$b['client_note']}\n";
    }
    $body .= "\n" . ($pending ? 'Valider ou refuser : ' : 'Voir le rendez-vous : ') . admin_link($b);
    mail_coach(($pending ? 'Demande à valider : ' : 'Nouveau RDV : ') . $client . ' — ' . fr_date($b['start_at']), $body, $b['email']);

    $body  = "Bonjour {$b['first_name']},\n\n";
    $body .= $pending
        ? "Votre demande de rendez-vous a bien été envoyée. Vous recevrez un email dès qu'elle sera validée.\n\n"
        : "Votre rendez-vous est confirmé. À bientôt !\n\n";
    $body .= booking_summary($b);
    $body .= "\nSuivre, déplacer ou annuler votre rendez-vous : " . client_link($b) . "\n";
    $body .= cancel_policy_text() . "\n";
    send_mail($b['email'], $pending ? 'Demande de rendez-vous envoyée' : 'Rendez-vous confirmé', $body, setting('coach_email'));
}

function notify_client_status(array $b, string $note = ''): void
{
    $subjects = [
        'confirmed' => 'Votre rendez-vous est confirmé',
        'refused'   => 'Votre demande de rendez-vous',
        'cancelled' => 'Votre rendez-vous est annulé',
    ];
    $intro = [
        'confirmed' => "Bonne nouvelle : votre rendez-vous est confirmé.",
        'refused'   => "Malheureusement, votre demande de rendez-vous n'a pas pu être acceptée. N'hésitez pas à choisir un autre créneau.",
        'cancelled' => "Votre rendez-vous a été annulé par votre coach.",
    ];
    $body  = "Bonjour {$b['first_name']},\n\n{$intro[$b['status']]}\n\n" . booking_summary($b);
    if ($note !== '') {
        $body .= "\nMessage de votre coach :\n$note\n";
    }
    $body .= "\nDétails : " . client_link($b) . "\n";
    if ($b['status'] !== 'confirmed') {
        $body .= 'Réserver un autre créneau : ' . absolute_url('reserver.php') . "\n";
    }
    send_mail($b['email'], $subjects[$b['status']], $body, setting('coach_email'));
}

function notify_moved(array $b, string $oldStart, string $by): void
{
    $was = 'Ancien horaire : ' . fr_slot($oldStart, date('Y-m-d H:i', strtotime($oldStart) + (strtotime($b['end_at']) - strtotime($b['start_at'])))) . "\n";
    if ($by === 'client') {
        $body = "{$b['first_name']} {$b['last_name']} a déplacé son rendez-vous.\n\n$was" . 'Nouvel horaire  : ' . fr_slot($b['start_at'], $b['end_at']) . "\n"
              . booking_summary($b) . "\nStatut : " . STATUTS[$b['status']] . "\n" . admin_link($b);
        mail_coach('RDV déplacé : ' . $b['first_name'] . ' ' . $b['last_name'], $body, $b['email']);
    }
    $body = "Bonjour {$b['first_name']},\n\nVotre rendez-vous a été déplacé.\n\n$was" . booking_summary($b)
          . "\nStatut : " . STATUTS[$b['status']] . "\nDétails : " . client_link($b) . "\n";
    send_mail($b['email'], 'Rendez-vous déplacé — ' . fr_date($b['start_at']), $body, setting('coach_email'));
}

function notify_client_cancelled(array $b): void
{
    $body = "{$b['first_name']} {$b['last_name']} a annulé son rendez-vous.\n\n" . booking_summary($b) . "\nLe créneau est de nouveau libre.\n" . admin_link($b);
    mail_coach('RDV annulé : ' . $b['first_name'] . ' ' . $b['last_name'], $body, $b['email']);
    $body = "Bonjour {$b['first_name']},\n\nVotre rendez-vous a bien été annulé.\n\n" . booking_summary($b)
          . "\nRéserver un nouveau créneau : " . absolute_url('reserver.php') . "\n";
    send_mail($b['email'], 'Annulation confirmée', $body, setting('coach_email'));
}

function notify_message(array $b, string $sender, string $text): void
{
    if ($sender === 'client') {
        mail_coach("Message de {$b['first_name']} {$b['last_name']}",
            "{$b['first_name']} vous a écrit à propos du rendez-vous du " . fr_date($b['start_at']) . " :\n\n$text\n\nRépondre : " . admin_link($b), $b['email']);
    } else {
        send_mail($b['email'], 'Nouveau message de votre coach',
            "Bonjour {$b['first_name']},\n\nVotre coach vous a écrit :\n\n$text\n\nRépondre : " . client_link($b), setting('coach_email'));
    }
}

function cancel_policy_text(): string
{
    $h = setting_int('cancel_hours', 48);
    return "Rappel : merci de prévenir au moins {$h} h à l'avance pour annuler ou déplacer votre séance.";
}
