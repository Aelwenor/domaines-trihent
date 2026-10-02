<?php
/*
 * Rappels automatiques par email, entierement parametrables par le coach (page Rappels).
 *
 * Types de rappel :
 *   morning : chaque jour a l'heure choisie, le programme du jour
 *   eve     : chaque jour a l'heure choisie, le programme du lendemain
 *   weekly  : le jour et l'heure choisis, les 7 jours a venir
 *   before  : X heures avant chaque seance
 * Chaque rappel vise soit le coach (recapitulatif de son agenda), soit les clients (leurs seances).
 *
 * run_reminders() est appelee par cron.php (tache planifiee) et, a defaut, automatiquement
 * lors des visites sur l'application (au plus toutes les 10 minutes).
 */

const RAPPEL_TYPES = [
    'morning' => 'Chaque matin : le programme du jour',
    'eve'     => 'Chaque veille : le programme du lendemain',
    'weekly'  => 'Chaque semaine : les 7 jours à venir',
    'before'  => 'Avant chaque séance',
];

/* Fenetre pendant laquelle un rappel a heure fixe peut encore partir (cron en retard, site peu visite...) */
const RAPPEL_FENETRE = 3 * 3600;

function reminder_label(array $r): string
{
    return match ($r['kind']) {
        'morning' => 'Chaque matin à ' . str_replace(':', 'h', $r['time']) . ' : le programme du jour',
        'eve'     => 'Chaque soir à ' . str_replace(':', 'h', $r['time']) . ' : le programme du lendemain',
        'weekly'  => 'Chaque ' . JOURS[(int) $r['weekday']] . ' à ' . str_replace(':', 'h', $r['time']) . ' : les 7 jours à venir',
        'before'  => fr_duration((int) $r['hours'] * 60) . ' avant chaque séance',
        default   => $r['kind'],
    };
}

/* Periode couverte par un rappel a heure fixe, a partir de l'instant $now. */
function reminder_range(string $kind, int $now): array
{
    $today = date('Y-m-d', $now);
    $tomorrow = date('Y-m-d', strtotime("$today +1 day"));
    return match ($kind) {
        'morning' => ["$today 00:00", "$tomorrow 00:00"],
        'eve'     => ["$tomorrow 00:00", date('Y-m-d', strtotime("$today +2 days")) . ' 00:00'],
        'weekly'  => [date('Y-m-d H:i', $now), date('Y-m-d H:i', $now + 7 * 86400)],
    };
}

function bookings_between(string $from, string $to, array $statuses = ['confirmed', 'pending'], ?int $clientId = null): array
{
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT b.*, c.first_name, c.last_name, c.phone, c.email, c.access_token FROM bookings b JOIN clients c ON c.id = b.client_id
            WHERE b.status IN ($in) AND b.start_at >= ? AND b.start_at < ?" . ($clientId ? ' AND b.client_id = ?' : '') . ' ORDER BY b.start_at';
    $params = array_merge($statuses, [$from, $to], $clientId ? [$clientId] : []);
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------- Contenu des emails ---------- */

function coach_digest(string $kind, int $now): ?array
{
    [$from, $to] = reminder_range($kind, $now);
    $rows = bookings_between($from, $to);
    $pending = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
    if (!$rows && !$pending) {
        return null;
    }
    $titles = ['morning' => 'Votre programme du jour', 'eve' => 'Votre programme de demain', 'weekly' => 'Vos 7 prochains jours'];
    $body = '';
    $day = '';
    foreach ($rows as $b) {
        if (substr($b['start_at'], 0, 10) !== $day) {
            $day = substr($b['start_at'], 0, 10);
            $body .= "\n" . mb_strtoupper(fr_date($day)) . "\n";
        }
        $body .= '- ' . fr_time($b['start_at']) . '-' . fr_time($b['end_at']) . " : {$b['first_name']} {$b['last_name']} — {$b['service_name']}"
               . ($b['status'] === 'pending' ? ' [À VALIDER]' : '') . "\n  {$b['location_name']}" . ($b['address'] !== '' ? " — {$b['address']}" : '') . "\n";
        if ($b['travel'] > 0) {
            $body .= '  Départ ' . fr_time($b['occ_start']) . ', retour vers ' . fr_time(date('Y-m-d H:i', strtotime($b['end_at']) + $b['travel'] * 60)) . "\n";
        }
        $body .= "  Tél : {$b['phone']}\n";
    }
    if (!$rows) {
        $body .= "\nAucune séance prévue sur cette période.\n";
    }
    $n = count($rows);
    if ($pending) {
        $body .= "\n⚠ $pending demande(s) en attente de validation.\n";
    }
    $body .= "\nTableau de bord : " . absolute_url('admin/');
    $subject = $titles[$kind] . ' — ' . ($n ? "$n séance(s)" : 'aucune séance') . ($kind === 'weekly' ? '' : ' · ' . fr_date($from));
    return [$subject, "Bonjour,\n\n" . $titles[$kind] . " :\n" . $body];
}

function client_digest(string $kind, array $client, array $rows): array
{
    $intro = [
        'morning' => "Votre séance d'aujourd'hui :",
        'eve'     => 'Petit rappel de votre séance de demain :',
        'weekly'  => 'Vos séances des 7 prochains jours :',
        'before'  => 'Votre séance approche :',
    ][$kind];
    $body = "Bonjour {$client['first_name']},\n\n$intro\n\n";
    foreach ($rows as $b) {
        $body .= booking_summary($b) . "\n";
    }
    $body .= 'Votre espace (déplacer, annuler, écrire au coach) : ' . espace_link($client['access_token']) . "\n";
    $body .= cancel_policy_text();
    $first = $rows[0];
    $subject = $kind === 'weekly'
        ? 'Vos séances de la semaine'
        : 'Rappel : séance ' . fr_date($first['start_at']) . ' à ' . fr_time($first['start_at']);
    return [$subject, $body];
}

/* ---------- Envoi ---------- */

/* Marque un rappel comme envoye ; renvoie false s'il l'etait deja. */
function reminder_claim(int $id, string $ref): bool
{
    $st = db()->prepare('INSERT OR IGNORE INTO reminder_log(reminder_id, ref, sent_at) VALUES (?, ?, ?)');
    $st->execute([$id, $ref, now_str()]);
    return $st->rowCount() > 0;
}

function run_reminders(?int $now = null): int
{
    $now ??= time();
    $sent = 0;
    $rules = db()->query('SELECT * FROM reminders WHERE active = 1')->fetchAll();

    foreach ($rules as $r) {
        $id = (int) $r['id'];

        if ($r['kind'] === 'before') {
            $limit = date('Y-m-d H:i', $now + (int) $r['hours'] * 3600);
            foreach (bookings_between(date('Y-m-d H:i', $now), $limit, ['confirmed']) as $b) {
                if (!reminder_claim($id, 'b' . $b['id'] . '@' . $b['start_at'])) {
                    continue;
                }
                if ($r['target'] === 'coach') {
                    mail_coach("Bientôt : {$b['first_name']} {$b['last_name']} à " . fr_time($b['start_at']),
                        booking_summary($b) . ($b['travel'] > 0 ? 'Départ conseillé : ' . fr_time($b['occ_start']) . "\n" : '') . "Tél : {$b['phone']}\n" . admin_link($b));
                } else {
                    [$subject, $body] = client_digest('before', $b, [$b]);
                    send_mail($b['email'], $subject, $body, setting('coach_email'));
                }
                $sent++;
            }
            continue;
        }

        // Rappels a heure fixe
        if ($r['kind'] === 'weekly' && (int) date('N', $now) !== (int) $r['weekday']) {
            continue;
        }
        $at = strtotime(date('Y-m-d', $now) . ' ' . $r['time']);
        if ($now < $at || $now >= $at + RAPPEL_FENETRE) {
            continue;
        }
        $period = date('Y-m-d', $now);

        if ($r['target'] === 'coach') {
            $mail = coach_digest($r['kind'], $now);
            if ($mail && reminder_claim($id, $period)) {
                mail_coach($mail[0], $mail[1]);
                $sent++;
            }
            continue;
        }

        [$from, $to] = reminder_range($r['kind'], $now);
        $byClient = [];
        foreach (bookings_between($from, $to, ['confirmed']) as $b) {
            $byClient[$b['client_id']][] = $b;
        }
        foreach ($byClient as $clientId => $rows) {
            if (!reminder_claim($id, "$period:$clientId")) {
                continue;
            }
            [$subject, $body] = client_digest($r['kind'], $rows[0], $rows);
            send_mail($rows[0]['email'], $subject, $body, setting('coach_email'));
            $sent++;
        }
    }

    // Menage : on oublie les traces de plus de 60 jours
    db()->prepare('DELETE FROM reminder_log WHERE sent_at < ?')->execute([date('Y-m-d', $now - 60 * 86400)]);
    return $sent;
}

/*
 * Lancement automatique pendant les visites, pour les hebergements sans tache planifiee.
 * Au plus une fois toutes les 10 minutes, apres l'envoi de la page au visiteur.
 */
function auto_run_reminders(): void
{
    if (PHP_SAPI === 'cli' || time() - setting_int('last_auto_run') < 600) {
        return;
    }
    set_setting('last_auto_run', (string) time());
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            run_reminders();
        } catch (Throwable $e) {
            @file_put_contents(DATA_DIR . '/mail.log', '[' . now_str() . '] Erreur rappels : ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    });
}
