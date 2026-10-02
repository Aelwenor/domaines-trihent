<?php
/*
 * Calcul des creneaux disponibles.
 *
 * Principe : un rendez-vous bloque dans l'agenda du coach
 *     [debut - trajet aller]  ->  [fin de seance + trajet retour + pause]
 * Exemple Pordic (30 min de trajet) pour une seance de 1 h a 10h00 :
 *     le coach est occupe de 9h30 a 11h30, soit 2 h.
 * Tout ce bloc doit tenir dans ses plages d'ouverture, sans chevaucher
 * un autre rendez-vous (en attente ou confirme) ni une indisponibilite.
 */

function hm_to_minutes(string $hm): int
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    return $h * 60 + $m;
}

/* Temps bloque [debut, fin] (timestamps) pour une seance commencant a $start. */
function occupation(int $start, int $duration, int $travel, ?int $buffer = null): array
{
    $buffer ??= setting_int('buffer_minutes', 0);
    return [$start - $travel * 60, $start + ($duration + $travel + $buffer) * 60];
}

/* Intervalles deja pris (rendez-vous actifs + indisponibilites) entre deux dates. */
function busy_intervals(string $from, string $to, ?int $excludeBooking = null): array
{
    $busy = [];
    $st = db()->prepare("SELECT occ_start, occ_end FROM bookings
                         WHERE status IN ('pending', 'confirmed') AND occ_start < ? AND occ_end > ? AND id != ?");
    $st->execute([$to, $from, $excludeBooking ?? 0]);
    foreach ($st as $r) {
        $busy[] = [strtotime($r['occ_start']), strtotime($r['occ_end'])];
    }
    $st = db()->prepare('SELECT start_at, end_at FROM blocks WHERE start_at < ? AND end_at > ?');
    $st->execute([$to, $from]);
    foreach ($st as $r) {
        $busy[] = [strtotime($r['start_at']), strtotime($r['end_at'])];
    }
    return $busy;
}

function overlaps(array $a, array $busy): bool
{
    foreach ($busy as $b) {
        if ($a[0] < $b[1] && $a[1] > $b[0]) {
            return true;
        }
    }
    return false;
}

/* Plages d'ouverture d'une journee, en timestamps. */
function day_windows(string $date): array
{
    $weekday = (int) dt($date)->format('N');
    $st = db()->prepare('SELECT start_time, end_time FROM availability WHERE weekday = ? ORDER BY start_time');
    $st->execute([$weekday]);
    $windows = [];
    foreach ($st as $r) {
        $windows[] = [strtotime("$date {$r['start_time']}"), strtotime("$date {$r['end_time']}")];
    }
    return $windows;
}

/*
 * Creneaux libres d'une journee pour une prestation et un lieu.
 * Retourne une liste d'heures de debut de seance "HH:MM".
 * $respectRules = false (cote coach) ignore le delai de prevenance et l'horizon.
 */
function available_slots(string $date, int $duration, int $travel, ?int $excludeBooking = null, bool $respectRules = true): array
{
    $step = max(5, setting_int('slot_step', 30));
    $buffer = setting_int('buffer_minutes', 0);

    if ($respectRules) {
        $today = date('Y-m-d');
        $last = date('Y-m-d', strtotime('+' . setting_int('horizon_days', 60) . ' days'));
        if ($date < $today || $date > $last) {
            return [];
        }
    }
    $earliest = $respectRules ? time() + setting_int('min_notice_hours', 24) * 3600 : PHP_INT_MIN;

    $busy = busy_intervals("$date 00:00", date('Y-m-d', strtotime("$date +1 day")) . ' 23:59', $excludeBooking);
    $midnight = strtotime("$date 00:00");
    $slots = [];

    foreach (day_windows($date) as [$ws, $we]) {
        // Premier debut possible : apres le trajet aller, cale sur la grille ($step minutes depuis minuit)
        $first = $ws + $travel * 60;
        $offset = ($first - $midnight) % ($step * 60);
        if ($offset !== 0) {
            $first += $step * 60 - $offset;
        }
        for ($t = $first; $t + ($duration + $travel) * 60 <= $we; $t += $step * 60) {
            if ($t < $earliest) {
                continue;
            }
            if (!overlaps(occupation($t, $duration, $travel, $buffer), $busy)) {
                $slots[date('H:i', $t)] = true;
            }
        }
    }
    return array_keys($slots);
}

/* Jours d'un mois ayant au moins un creneau libre : ['2026-10-14' => 5, ...] */
function available_days(int $year, int $month, int $duration, int $travel, ?int $excludeBooking = null): array
{
    $days = [];
    $n = cal_days($year, $month);
    for ($d = 1; $d <= $n; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $count = count(available_slots($date, $duration, $travel, $excludeBooking));
        if ($count > 0) {
            $days[$date] = $count;
        }
    }
    return $days;
}

function cal_days(int $year, int $month): int
{
    return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
}

/* Verification finale juste avant d'enregistrer un rendez-vous. */
function slot_is_available(string $date, string $time, int $duration, int $travel, ?int $excludeBooking = null): bool
{
    return in_array($time, available_slots($date, $duration, $travel, $excludeBooking), true);
}

/* Le creneau chevauche-t-il quelque chose ? (utilise cote coach, sans les regles clients) */
function slot_conflicts(string $startAt, int $duration, int $travel, ?int $excludeBooking = null): bool
{
    $occ = occupation(strtotime($startAt), $duration, $travel);
    $busy = busy_intervals(date('Y-m-d H:i', $occ[0]), date('Y-m-d H:i', $occ[1]), $excludeBooking);
    return overlaps($occ, $busy);
}

/* Champs horaires d'un rendez-vous, prets a enregistrer. */
function booking_times(string $startAt, int $duration, int $travel): array
{
    $t = strtotime($startAt);
    [$os, $oe] = occupation($t, $duration, $travel);
    return [
        'start_at'  => date('Y-m-d H:i', $t),
        'end_at'    => date('Y-m-d H:i', $t + $duration * 60),
        'occ_start' => date('Y-m-d H:i', $os),
        'occ_end'   => date('Y-m-d H:i', $oe),
    ];
}
