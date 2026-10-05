<?php
/*
 * Ajout de rendez-vous par le coach lui-meme : un client sous contrat (seances fixes chaque semaine),
 * ou un client qui a reserve par WhatsApp. Les creneaux sont alors bloques en ligne.
 * Option "repeter chaque semaine" pour planifier un forfait en une fois.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$clients = db()->query('SELECT id, first_name, last_name, email, address FROM clients ORDER BY last_name, first_name')->fetchAll();
$errors = [];
$selectedClient = (int) post('client_id', get('client'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $service  = find_row('services', (int) post('service_id'));
    $location = find_row('locations', (int) post('location_id'));
    $date = post('date');
    $time = post('time');
    $duration = (int) post('duration');
    $weeks = min(52, max(1, (int) post('weeks', '1')));
    if (!$service || !$location) $errors[] = 'Choisissez un cours et un lieu.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Date ou heure invalide.';
    if ($service && $duration < 15) $duration = (int) $service['duration'];

    $clientId = $selectedClient;
    if (!$clientId) {
        if (post('first_name') === '' || post('last_name') === '') $errors[] = 'Indiquez le prénom et le nom du nouveau client.';
        if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $errors[] = "L'email du client n'est pas valide.";
    }

    if (!$errors) {
        $pdo = db();
        if (!$clientId) {
            $clientId = save_client(['first_name' => post('first_name'), 'last_name' => post('last_name'), 'age' => post('age'),
                                     'email' => post('email'), 'phone' => post('phone'), 'objective' => $service['name'], 'address' => post('address')]);
        }
        $travel = (int) $location['travel'];
        $created = [];
        $skipped = [];
        for ($i = 0; $i < $weeks; $i++) {
            $day = date('Y-m-d', strtotime("$date +$i week"));
            if (slot_conflicts("$day $time", $duration, $travel) && post('force') !== '1') {
                $skipped[] = fr_date($day);
                continue;
            }
            $t = booking_times("$day $time", $duration, $travel);
            $token = random_token();
            $pdo->prepare("INSERT INTO bookings(token, client_id, service_id, location_id, service_name, location_name, location_kind, travel,
                           start_at, end_at, occ_start, occ_end, address, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)")
                ->execute([$token, $clientId, $service['id'], $location['id'], $service['name'], $location['name'], $location['kind'],
                           $travel, $t['start_at'], $t['end_at'], $t['occ_start'], $t['occ_end'],
                           $location['kind'] === 'travel' ? post('address') : '', now_str(), now_str()]);
            $created[] = find_booking_by_token($token);
        }
        // Memorise l'adresse sur la fiche client pour les prochaines fois
        if (post('address') !== '' && $location['kind'] === 'travel') {
            $pdo->prepare("UPDATE clients SET address = ? WHERE id = ? AND address = ''")->execute([post('address'), $clientId]);
        }

        if (!$created) {
            $errors[] = 'Aucune séance ajoutée : le créneau est déjà pris (trajet compris) à chaque date. Choisissez un autre horaire ou cochez « forcer ».';
        } else {
            if (post('notify') === '1' && has_real_email($created[0]['email'])) {
                notify_planned($created);
            }
            flash(count($created) === 1 ? 'Séance ajoutée.' : count($created) . ' séances ajoutées, une par semaine.');
            if ($skipped) {
                flash('Non ajoutées car le créneau était déjà pris : ' . implode(', ', $skipped) . '. Placez-les à un autre horaire.', 'warn');
            }
            redirect('admin/clients.php?id=' . $clientId);
        }
    }
}

page_header('Nouveau rendez-vous', true);
$services = active_services();
$current = $selectedClient ? find_row('clients', $selectedClient) : null;
?>
<h1>Planifier des séances</h1>
<p class="muted">Pour un client sous contrat (mêmes jour et heure chaque semaine) ou un client qui vous a contacté par WhatsApp. Les créneaux sont aussitôt bloqués en ligne, trajet compris.</p>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<?php if ($current): ?><div class="card"><?= forfait_html($current) ?: '<span class="muted small">Pas de forfait mensuel pour ce client.</span>' ?></div><?php endif; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label for="client_id">Client</label>
  <select id="client_id" name="client_id" onchange="document.getElementById('newclient').style.display = this.value === '0' ? '' : 'none'; var a = this.selectedOptions[0].dataset.address; if (a) document.getElementById('address').value = a;">
    <option value="0">+ Nouveau client</option>
    <?php foreach ($clients as $c): ?>
      <option value="<?= (int) $c['id'] ?>" data-address="<?= e($c['address']) ?>" <?= $selectedClient === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['last_name'] . ' ' . $c['first_name']) ?></option>
    <?php endforeach; ?>
  </select>
  <div id="newclient" class="grid2" <?= $selectedClient ? 'style="display:none"' : '' ?>>
    <div><label>Prénom</label><input name="first_name" value="<?= e(post('first_name')) ?>"></div>
    <div><label>Nom</label><input name="last_name" value="<?= e(post('last_name')) ?>"></div>
    <div><label>Email (conseillé)</label><input type="email" name="email" value="<?= e(post('email')) ?>"></div>
    <div><label>Téléphone</label><input type="tel" name="phone" value="<?= e(post('phone')) ?>"></div>
    <div><label>Âge</label><input type="number" name="age" value="<?= e(post('age')) ?>"></div>
  </div>
  <div class="grid2">
    <div><label for="service_id">Cours</label>
      <select id="service_id" name="service_id" onchange="document.getElementById('duration').value = this.selectedOptions[0].dataset.duration">
        <?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>" data-duration="<?= (int) $s['duration'] ?>" <?= (int) post('service_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label for="duration">Durée de la séance (minutes)</label>
      <input id="duration" type="number" name="duration" min="15" step="15" value="<?= e(post('duration', (string) ($services[0]['duration'] ?? 60))) ?>"></div>
    <div><label for="location_id">Lieu</label>
      <select id="location_id" name="location_id"><?php foreach (active_locations() as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) post('location_id') === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?><?= $l['travel'] ? ' (+' . (int) $l['travel'] . ' min trajet)' : '' ?></option><?php endforeach; ?></select></div>
    <div><label for="address">Adresse (si vous vous déplacez)</label><input id="address" name="address" value="<?= e(post('address', $current['address'] ?? '')) ?>"></div>
    <div><label for="date">Date de la 1re séance</label><input id="date" type="date" name="date" required value="<?= e(post('date')) ?>"></div>
    <div><label for="time">Heure de début</label><input id="time" type="time" name="time" required step="300" value="<?= e(post('time')) ?>"></div>
  </div>
  <label for="weeks">Répéter chaque semaine, même jour, même heure</label>
  <select id="weeks" name="weeks" style="max-width:320px">
    <?php foreach ([1 => 'Non, une seule séance', 2 => '2 semaines', 4 => '4 semaines (1 mois)', 8 => '8 semaines (2 mois)', 13 => '13 semaines (3 mois)', 26 => '26 semaines (6 mois)'] as $n => $l): ?>
      <option value="<?= $n ?>" <?= (int) post('weeks', '1') === $n ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <p class="help">Les dates déjà prises sont sautées et listées : vous pourrez les placer à un autre horaire.</p>
  <label class="check"><input type="checkbox" name="notify" value="1" checked> Envoyer au client le récapitulatif par email</label>
  <label class="check"><input type="checkbox" name="force" value="1"> Forcer même si le créneau est déjà occupé</label>
  <p></p>
  <button class="btn" type="submit">Planifier</button>
</form>
<?php page_footer();
