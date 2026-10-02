<?php
/*
 * Prise de rendez-vous cote client, facon Calendly :
 *   1. choix du cours  2. choix du lieu  3. jour + heure  4. coordonnees
 * Sert aussi a deplacer un rendez-vous existant (?t=jeton du rendez-vous).
 */
require __DIR__ . '/inc/bootstrap.php';

/* ---------- Mode "deplacer un rendez-vous" ---------- */
$resched = null;
if (get('t') !== '') {
    $resched = find_booking_by_token(get('t'));
    if (!$resched) {
        flash('Rendez-vous introuvable.', 'error');
        redirect('');
    }
    if (!client_can_change($resched)) {
        flash('Ce rendez-vous ne peut plus être déplacé en ligne (moins de ' . setting_int('cancel_hours', 48) . ' h avant). Contactez directement votre coach.', 'error');
        redirect('rdv.php?t=' . $resched['token']);
    }
}

/* ---------- Lecture des choix ---------- */
$sid = (int) get('s', $resched ? (string) $resched['service_id'] : '');
$lid = (int) get('l', $resched ? (string) $resched['location_id'] : '');
$service  = $sid ? find_row('services', $sid) : null;
$location = $lid ? find_row('locations', $lid) : null;
if ($service && !$service['active']) $service = null;
if ($location && !$location['active']) $location = null;

$locations = active_locations();
if ($service && !$location && count($locations) === 1) {
    $location = $locations[0];
}

$date = get('d');
$time = get('h');
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';

/* Construit l'adresse de cette page en gardant les choix deja faits. */
function step_url(array $params): string
{
    global $resched;
    if ($resched) {
        $params = ['t' => $resched['token']] + $params;
    }
    return url('reserver.php?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null)));
}

$duration = $service ? (int) $service['duration'] : 0;
$travel   = $location ? (int) $location['travel'] : 0;
$exclude  = $resched ? (int) $resched['id'] : null;
$slotOk   = $service && $location && $date !== '' && $time !== ''
            && slot_is_available($date, $time, $duration, $travel, $exclude);

/* ---------- Envoi du formulaire ---------- */
$errors = [];
$form = $_SESSION['client_prefill'] ?? [];
if ($me = current_client()) {
    // Client venu de son espace : profil deja connu
    $form = ['first_name' => $me['first_name'], 'last_name' => $me['last_name'], 'age' => (string) $me['age'],
             'email' => has_real_email($me['email']) ? $me['email'] : '', 'phone' => $me['phone'], 'objective' => $me['objective']] + $form;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $service && $location && $date !== '' && $time !== '') {
    csrf_check();
    if (post('website') !== '') { // champ piege anti-robots
        redirect('');
    }
    $pdo = db();

    if ($resched) {
        $pdo->exec('BEGIN IMMEDIATE');
        if (!slot_is_available($date, $time, $duration, $travel, $exclude)) {
            $pdo->exec('ROLLBACK');
            flash("Ce créneau vient d'être pris, merci d'en choisir un autre.", 'error');
            header('Location: ' . step_url(['s' => $sid, 'l' => $lid, 'd' => $date]));
            exit;
        }
        $t = booking_times("$date $time", $duration, $travel);
        $status = setting('auto_confirm') === '1' ? 'confirmed' : 'pending';
        $pdo->prepare('UPDATE bookings SET service_id = ?, location_id = ?, service_name = ?, location_name = ?, location_kind = ?, travel = ?,
                       start_at = ?, end_at = ?, occ_start = ?, occ_end = ?, status = ?, updated_at = ? WHERE id = ?')
            ->execute([$service['id'], $location['id'], $service['name'], $location['name'], $location['kind'], $travel,
                       $t['start_at'], $t['end_at'], $t['occ_start'], $t['occ_end'], $status, now_str(), $resched['id']]);
        $pdo->exec('COMMIT');
        notify_moved(find_booking((int) $resched['id']), $resched['start_at'], 'client');
        flash('Votre rendez-vous a bien été déplacé.' . ($status === 'pending' ? ' Votre coach doit valider le nouvel horaire.' : ''));
        redirect('rdv.php?t=' . $resched['token']);
    }

    $form = [
        'first_name' => post('first_name'),
        'last_name'  => post('last_name'),
        'age'        => post('age'),
        'email'      => post('email'),
        'phone'      => post('phone'),
        'objective'  => post('objective'),
        'address'    => post('address'),
        'note'       => post('note'),
    ];
    if ($form['first_name'] === '') $errors[] = 'Le prénom est obligatoire.';
    if ($form['last_name'] === '')  $errors[] = 'Le nom est obligatoire.';
    if ($form['age'] !== '' && (!ctype_digit($form['age']) || (int) $form['age'] < 5 || (int) $form['age'] > 110)) $errors[] = "L'âge n'est pas valide.";
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse email n'est pas valide.";
    if (strlen(preg_replace('/\D/', '', $form['phone'])) < 9) $errors[] = 'Le téléphone est obligatoire.';
    if ($location['kind'] === 'travel' && $form['address'] === '') $errors[] = "Indiquez l'adresse où le coach doit venir.";
    if (post('consent') !== '1') $errors[] = 'Merci d\'accepter les conditions de réservation.';

    if (!$errors) {
        $pdo->exec('BEGIN IMMEDIATE');
        if (!slot_is_available($date, $time, $duration, $travel)) {
            $pdo->exec('ROLLBACK');
            flash("Ce créneau vient d'être pris, merci d'en choisir un autre.", 'error');
            header('Location: ' . step_url(['s' => $sid, 'l' => $lid, 'd' => $date]));
            exit;
        }
        // Fiche client (retrouvee par son email, mise a jour a chaque reservation)
        if ($me) {
            $clientId = (int) $me['id'];
            $pdo->prepare('UPDATE clients SET first_name = ?, last_name = ?, age = COALESCE(?, age), phone = ?, objective = ? WHERE id = ?')
                ->execute([$form['first_name'], $form['last_name'], $form['age'] !== '' ? (int) $form['age'] : null, $form['phone'], $form['objective'], $clientId]);
            if (!has_real_email($me['email'])) {
                try {
                    $pdo->prepare('UPDATE clients SET email = ? WHERE id = ?')->execute([$form['email'], $clientId]);
                } catch (PDOException $e) {
                    // email deja utilise par une autre fiche : on garde la fiche de l'espace
                }
            }
        } else {
            $clientId = save_client($form);
        }
        $t = booking_times("$date $time", $duration, $travel);
        $token = random_token();
        $pdo->prepare('INSERT INTO bookings(token, client_id, service_id, location_id, service_name, location_name, location_kind, travel,
                       start_at, end_at, occ_start, occ_end, address, client_note, status, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$token, $clientId, $service['id'], $location['id'], $service['name'], $location['name'], $location['kind'], $travel,
                       $t['start_at'], $t['end_at'], $t['occ_start'], $t['occ_end'],
                       $location['kind'] === 'travel' ? $form['address'] : '', $form['note'],
                       setting('auto_confirm') === '1' ? 'confirmed' : 'pending', now_str(), now_str()]);
        $pdo->exec('COMMIT');

        $_SESSION['client_prefill'] = array_diff_key($form, ['note' => 1]);
        notify_new_booking(find_booking_by_token($token));
        redirect('rdv.php?t=' . $token . '&new=1');
    }
}

/* ---------- Affichage ---------- */
page_header($resched ? 'Déplacer mon rendez-vous' : 'Réserver');

$step = !$service ? 1 : (!$location ? 2 : (!$slotOk ? 3 : 4));
$labels = [1 => 'Cours', 2 => 'Lieu', 3 => 'Date & heure', 4 => $resched ? 'Confirmation' : 'Vos coordonnées'];
?>
<h1><?= $resched ? 'Déplacer mon rendez-vous' : 'Réserver une séance' ?></h1>
<?php if ($resched): ?>
  <div class="alert alert-info">Rendez-vous actuel : <strong><?= e(fr_slot($resched['start_at'], $resched['end_at'])) ?></strong>. Choisissez un nouveau créneau.</div>
<?php endif; ?>
<div class="steps">
  <?php foreach ($labels as $n => $label): ?><span class="<?= $n === $step ? 'on' : '' ?>"><?= $n ?>. <?= e($label) ?></span><?php endforeach; ?>
</div>

<?php if ($service || $location): ?>
  <div class="recap">
    <?php if ($service): ?><strong><?= e($service['name']) ?></strong> · <?= e(fr_duration($duration)) ?> <a class="small" href="<?= e(step_url([])) ?>">modifier</a><br><?php endif; ?>
    <?php if ($location): ?><?= e($location['name']) ?><?php if (count($locations) > 1): ?> <a class="small" href="<?= e(step_url(['s' => $sid])) ?>">modifier</a><?php endif; ?><br><?php endif; ?>
    <?php if ($slotOk): ?><strong><?= e(fr_slot("$date $time", date('Y-m-d H:i', strtotime("$date $time") + $duration * 60))) ?></strong> <a class="small" href="<?= e(step_url(['s' => $sid, 'l' => $lid, 'd' => $date])) ?>">modifier</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($step === 1): ?>
  <h2>Quel cours souhaitez-vous ?</h2>
  <div class="cards">
    <?php foreach (active_services() as $s): ?>
      <a class="choice" href="<?= e(step_url(['s' => $s['id']])) ?>">
        <h3><?= e($s['name']) ?></h3>
        <p><?= nl2br(e($s['description'])) ?></p>
        <p class="meta"><?= e(fr_duration((int) $s['duration'])) ?><?= $s['price'] !== '' ? ' · ' . e($s['price']) : '' ?></p>
      </a>
    <?php endforeach; ?>
  </div>

<?php elseif ($step === 2): ?>
  <h2>Où souhaitez-vous faire la séance ?</h2>
  <div class="cards">
    <?php foreach ($locations as $l): ?>
      <a class="choice" href="<?= e(step_url(['s' => $sid, 'l' => $l['id']])) ?>">
        <h3><?= e($l['name']) ?></h3>
        <p class="meta">
          <?php if ($l['kind'] === 'base'): ?>Vous venez chez le coach.
          <?php else: ?>Le coach se déplace chez vous (<?= (int) $l['travel'] ?> min de trajet).<?php endif; ?>
        </p>
      </a>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Votre commune n'est pas dans la liste ? Contactez le coach<?php if (setting('coach_phone') !== ''): ?> sur <a href="<?= e(whatsapp_link(setting('coach_phone'), 'Bonjour, je souhaiterais un coaching à ')) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>.</p>

<?php elseif ($step === 3): ?>
  <?php
    // Mois affiche : celui demande, sinon celui de la date choisie, sinon le premier mois avec des creneaux
    $today = new DateTimeImmutable('first day of this month');
    $lastDay = new DateTimeImmutable('+' . setting_int('horizon_days', 60) . ' days');
    $m = get('m') ?: ($date !== '' ? substr($date, 0, 7) : '');
    if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
        $m = $today->format('Y-m');
        $days = available_days((int) $today->format('Y'), (int) $today->format('n'), $duration, $travel, $exclude);
        if (!$days) {
            $m = $today->modify('+1 month')->format('Y-m');
        }
    }
    [$y, $mo] = array_map('intval', explode('-', $m));
    if ($m < $today->format('Y-m')) { [$y, $mo] = [(int) $today->format('Y'), (int) $today->format('n')]; $m = $today->format('Y-m'); }
    $days = available_days($y, $mo, $duration, $travel, $exclude);
    $cur  = new DateTimeImmutable("$m-01");
    $prev = $cur > $today ? step_url(['s' => $sid, 'l' => $lid, 'm' => $cur->modify('-1 month')->format('Y-m')]) : null;
    $next = $cur->modify('+1 month') <= $lastDay ? step_url(['s' => $sid, 'l' => $lid, 'm' => $cur->modify('+1 month')->format('Y-m')]) : null;
    $slots = $date !== '' ? available_slots($date, $duration, $travel, $exclude) : [];
  ?>
  <?php if ($travel > 0): ?>
    <div class="alert alert-info">Le coach se déplace : <?= $travel ?> min de trajet aller et <?= $travel ?> min retour.
      Ce rendez-vous réserve donc <strong><?= e(fr_duration($duration + 2 * $travel)) ?></strong> dans son agenda : seuls les créneaux compatibles sont proposés.</div>
  <?php endif; ?>
  <div class="booking-layout">
    <?= month_calendar($y, $mo, $days, fn($d) => step_url(['s' => $sid, 'l' => $lid, 'd' => $d]), $date, $prev, $next) ?>
    <div>
      <?php if ($date === ''): ?>
        <p class="muted">Choisissez un jour en surbrillance dans le calendrier.</p>
        <?php if (!$days): ?><p>Aucun créneau libre ce mois-ci. Essayez le mois suivant.</p><?php endif; ?>
      <?php elseif (!$slots): ?>
        <p>Plus aucun créneau libre le <?= e(fr_date($date)) ?>. Choisissez un autre jour.</p>
      <?php else: ?>
        <h3><?= e(ucfirst(fr_date($date))) ?></h3>
        <div class="slots">
          <?php foreach ($slots as $h): ?>
            <a class="slot" href="<?= e(step_url(['s' => $sid, 'l' => $lid, 'd' => $date, 'h' => $h])) ?>"><?= e(str_replace(':', 'h', $h)) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($time !== '' && !$slotOk): ?><div class="alert alert-error" style="margin-top:16px">Ce créneau n'est plus disponible, choisissez-en un autre.</div><?php endif; ?>

<?php elseif ($resched): ?>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <p>Confirmer le déplacement de votre rendez-vous au <strong><?= e(fr_slot("$date $time", date('Y-m-d H:i', strtotime("$date $time") + $duration * 60))) ?></strong> ?</p>
    <?php if (setting('auto_confirm') !== '1'): ?><p class="muted small">Le nouvel horaire devra être validé par votre coach.</p><?php endif; ?>
    <button class="btn" type="submit">Confirmer le nouveau créneau</button>
  </form>

<?php else: ?>
  <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
    <h2 style="margin-top:0">Votre profil</h2>
    <div class="grid2">
      <div><label for="first_name">Prénom *</label><input id="first_name" name="first_name" required value="<?= e($form['first_name'] ?? '') ?>" autocomplete="given-name"></div>
      <div><label for="last_name">Nom *</label><input id="last_name" name="last_name" required value="<?= e($form['last_name'] ?? '') ?>" autocomplete="family-name"></div>
      <div><label for="age">Âge</label><input id="age" name="age" type="number" min="5" max="110" value="<?= e($form['age'] ?? '') ?>"></div>
      <div>
        <label for="objective">Objectif</label>
        <select id="objective" name="objective">
          <?php $obj = $form['objective'] ?? $service['name']; ?>
          <?php foreach (active_services() as $s): ?>
            <option <?= $obj === $s['name'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
          <option <?= $obj === 'Autre' ? 'selected' : '' ?>>Autre</option>
        </select>
      </div>
      <div><label for="email">Email *</label><input id="email" name="email" type="email" required value="<?= e($form['email'] ?? '') ?>" autocomplete="email"></div>
      <div><label for="phone">Téléphone (WhatsApp) *</label><input id="phone" name="phone" type="tel" required value="<?= e($form['phone'] ?? '') ?>" autocomplete="tel"></div>
    </div>
    <?php if ($location['kind'] === 'travel'): ?>
      <label for="address">Adresse de la séance *</label>
      <input id="address" name="address" required value="<?= e($form['address'] ?? '') ?>" autocomplete="street-address" placeholder="N°, rue, commune">
    <?php endif; ?>
    <label for="note">Un message pour le coach ? (blessure, niveau, matériel…)</label>
    <textarea id="note" name="note"><?= e($_POST['note'] ?? '') ?></textarea>
    <label class="check"><input type="checkbox" name="consent" value="1" required>
      <span>J'ai bien noté qu'il faut prévenir au moins <?= setting_int('cancel_hours', 48) ?> h à l'avance pour annuler ou déplacer la séance, et j'accepte que mes informations soient utilisées par le coach pour organiser mes séances.</span></label>
    <p></p>
    <button class="btn btn-block" type="submit"><?= setting('auto_confirm') === '1' ? 'Réserver ce créneau' : 'Envoyer ma demande' ?></button>
    <?php if (setting('auto_confirm') !== '1'): ?><p class="help">Le coach valide chaque demande : vous recevrez un email de confirmation.</p><?php endif; ?>
  </form>
<?php endif; ?>
<?php page_footer();
