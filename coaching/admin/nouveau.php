<?php
/*
 * Ajout d'un rendez-vous par le coach lui-meme (ex. un client qui a reserve par WhatsApp),
 * pour que l'agenda reste a jour et que les creneaux en ligne en tiennent compte.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$clients = db()->query('SELECT id, first_name, last_name, email FROM clients ORDER BY last_name, first_name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $service  = find_row('services', (int) post('service_id'));
    $location = find_row('locations', (int) post('location_id'));
    $date = post('date');
    $time = post('time');
    if (!$service || !$location) $errors[] = 'Choisissez un cours et un lieu.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Date ou heure invalide.';

    $clientId = (int) post('client_id');
    if (!$clientId) {
        if (post('first_name') === '' || post('last_name') === '') $errors[] = 'Indiquez le prénom et le nom du nouveau client.';
        if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $errors[] = "L'email du client n'est pas valide.";
    }
    if (!$errors && slot_conflicts("$date $time", (int) $service['duration'], (int) $location['travel']) && post('force') !== '1') {
        $errors[] = 'Ce créneau chevauche un autre rendez-vous ou une indisponibilité (trajet compris). Cochez « forcer » pour le placer quand même.';
    }

    if (!$errors) {
        $pdo = db();
        if (!$clientId) {
            $clientId = save_client(['first_name' => post('first_name'), 'last_name' => post('last_name'), 'age' => post('age'),
                                     'email' => post('email'), 'phone' => post('phone'), 'objective' => $service['name']]);
        }
        $t = booking_times("$date $time", (int) $service['duration'], (int) $location['travel']);
        $token = random_token();
        $pdo->prepare("INSERT INTO bookings(token, client_id, service_id, location_id, service_name, location_name, location_kind, travel,
                       start_at, end_at, occ_start, occ_end, address, status, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)")
            ->execute([$token, $clientId, $service['id'], $location['id'], $service['name'], $location['name'], $location['kind'],
                       (int) $location['travel'], $t['start_at'], $t['end_at'], $t['occ_start'], $t['occ_end'], post('address'), now_str(), now_str()]);
        $b = find_booking_by_token($token);
        if (post('notify') === '1' && has_real_email($b['email'])) {
            notify_client_status($b);
        }
        flash('Rendez-vous ajouté.' . (post('notify') === '1' ? ' Le client a reçu la confirmation par email.' : ''));
        redirect('admin/rdv.php?id=' . $b['id']);
    }
}

page_header('Nouveau rendez-vous', true);
?>
<h1>Ajouter un rendez-vous</h1>
<p class="muted">Pour un client qui vous a contacté par WhatsApp ou par téléphone : le créneau sera bloqué en ligne.</p>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>Client</label>
  <select name="client_id" onchange="document.getElementById('newclient').style.display = this.value === '0' ? '' : 'none'">
    <option value="0">+ Nouveau client</option>
    <?php foreach ($clients as $c): ?>
      <option value="<?= (int) $c['id'] ?>" <?= (int) post('client_id', get('client')) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['last_name'] . ' ' . $c['first_name'] . ' — ' . $c['email']) ?></option>
    <?php endforeach; ?>
  </select>
  <div id="newclient" class="grid2" <?= (int) post('client_id', get('client')) ? 'style="display:none"' : '' ?>>
    <div><label>Prénom</label><input name="first_name" value="<?= e(post('first_name')) ?>"></div>
    <div><label>Nom</label><input name="last_name" value="<?= e(post('last_name')) ?>"></div>
    <div><label>Email (conseillé)</label><input type="email" name="email" value="<?= e(post('email')) ?>"></div>
    <div><label>Téléphone</label><input type="tel" name="phone" value="<?= e(post('phone')) ?>"></div>
    <div><label>Âge</label><input type="number" name="age" value="<?= e(post('age')) ?>"></div>
  </div>
  <div class="grid2">
    <div><label>Cours</label>
      <select name="service_id"><?php foreach (active_services() as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) post('service_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ' (' . fr_duration((int) $s['duration']) . ')') ?></option><?php endforeach; ?></select></div>
    <div><label>Lieu</label>
      <select name="location_id"><?php foreach (active_locations() as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) post('location_id') === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?><?= $l['travel'] ? ' (+' . (int) $l['travel'] . ' min trajet)' : '' ?></option><?php endforeach; ?></select></div>
    <div><label>Date</label><input type="date" name="date" required value="<?= e(post('date')) ?>"></div>
    <div><label>Heure de début de séance</label><input type="time" name="time" required step="300" value="<?= e(post('time')) ?>"></div>
  </div>
  <label>Adresse (si vous vous déplacez)</label>
  <input name="address" value="<?= e(post('address')) ?>">
  <label class="check"><input type="checkbox" name="notify" value="1" checked> Envoyer la confirmation par email au client (avec son lien pour suivre le rendez-vous)</label>
  <label class="check"><input type="checkbox" name="force" value="1"> Forcer même si le créneau est déjà occupé</label>
  <p></p>
  <button class="btn" type="submit">Ajouter le rendez-vous</button>
</form>
<?php page_footer();
