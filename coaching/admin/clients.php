<?php
/* Liste des clients et fiche client (profil, historique, notes privees du coach). */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'create') {
        if (post('first_name') === '' || post('last_name') === '') {
            flash('Indiquez au moins le prénom et le nom.', 'error');
            redirect('admin/clients.php?new=1');
        }
        if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
            flash("L'email n'est pas valide.", 'error');
            redirect('admin/clients.php?new=1');
        }
        $id = save_client(['first_name' => post('first_name'), 'last_name' => post('last_name'), 'age' => post('age'),
                           'email' => post('email'), 'phone' => post('phone'), 'objective' => post('objective'),
                           'address' => post('address'), 'monthly_hours' => str_replace(',', '.', post('monthly_hours')), 'coach_notes' => post('coach_notes')]);
        flash('Fiche créée. Étape suivante : envoyez-lui son lien, puis planifiez ses séances.');
        redirect('admin/clients.php?id=' . $id);
    }
    $c = find_row('clients', (int) post('id'));
    if ($c && post('action') === 'invite_email') {
        $ok = send_mail($c['email'], 'Votre espace coaching — ' . brand_name(), invitation_text($c), setting('coach_email'));
        flash($ok ? 'Invitation envoyée par email.' : 'Pas d\'email valide pour ce client : utilisez WhatsApp.', $ok ? 'ok' : 'error');
        redirect('admin/clients.php?id=' . $c['id']);
    }
    if ($c && post('action') === 'regen') {
        $pdo->prepare('UPDATE clients SET access_token = ? WHERE id = ?')->execute([random_token(), $c['id']]);
        flash('Nouveau lien créé : l\'ancien ne fonctionne plus.');
        redirect('admin/clients.php?id=' . $c['id']);
    }
    $pdo->prepare('UPDATE clients SET coach_notes = ?, phone = ?, age = ?, objective = ?, address = ?, monthly_hours = ? WHERE id = ?')
        ->execute([post('coach_notes'), post('phone'), post('age') !== '' ? (int) post('age') : null, post('objective'),
                   post('address'), max(0, (float) str_replace(',', '.', post('monthly_hours'))), (int) post('id')]);
    flash('Fiche client enregistrée.');
    redirect('admin/clients.php?id=' . (int) post('id'));
}

function invitation_text(array $c): string
{
    $coach = setting('coach_name') !== '' ? setting('coach_name') : brand_name();
    return "Bonjour {$c['first_name']},\n\nBienvenue ! Voici ton espace pour réserver tes séances, les déplacer et m'écrire : "
         . espace_link($c['access_token'])
         . "\n\nAstuce : ouvre le lien sur ton téléphone puis « Ajouter à l'écran d'accueil » pour l'avoir comme une appli.\n\n$coach";
}

$client = get('id') ? find_row('clients', (int) get('id')) : null;
page_header('Clients', true);

if (get('new')): ?>
  <p><a href="<?= e(url('admin/clients.php')) ?>">← Tous les clients</a></p>
  <h1>Nouveau client</h1>
  <p class="muted">Par exemple dès la signature du contrat : vous créez sa fiche, puis vous lui envoyez son lien d'invitation.</p>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="grid2">
      <div><label>Prénom *</label><input name="first_name" required></div>
      <div><label>Nom *</label><input name="last_name" required></div>
      <div><label>Téléphone (WhatsApp)</label><input type="tel" name="phone"></div>
      <div><label>Email</label><input type="email" name="email"></div>
      <div><label>Âge</label><input type="number" name="age" min="5" max="110"></div>
      <div><label>Objectif</label>
        <select name="objective"><?php foreach (active_services() as $s): ?><option><?= e($s['name']) ?></option><?php endforeach; ?><option>Autre</option></select></div>
      <div><label>Adresse des séances (si vous vous déplacez)</label><input name="address" placeholder="N°, rue, commune"></div>
      <div><label>Forfait : heures par mois (facultatif)</label><input name="monthly_hours" inputmode="decimal" placeholder="Ex. 8"></div>
    </div>
    <label>Mes notes privées (santé, contrat… jamais visibles par le client)</label>
    <textarea name="coach_notes" rows="3" placeholder="Ex. douleur dorsale, contrat 8 h/mois signé le…"></textarea>
    <p></p><button class="btn" type="submit">Créer la fiche</button>
  </form>
<?php elseif ($client):
    $st = $pdo->prepare('SELECT * FROM bookings WHERE client_id = ? ORDER BY start_at DESC');
    $st->execute([$client['id']]);
    $bookings = $st->fetchAll();
    $done = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed' && $b['end_at'] < date('Y-m-d H:i')));
?>
  <p><a href="<?= e(url('admin/clients.php')) ?>">← Tous les clients</a></p>
  <h1><?= e($client['first_name'] . ' ' . $client['last_name']) ?></h1>
  <?php if ($f = forfait_html($client)): ?><div class="card"><?= $f ?><?= forfait_html($client, date('Y-m', strtotime('first day of next month'))) ?></div><?php endif; ?>
  <div class="card">
    <h2 style="margin-top:0">📲 Lien d'invitation</h2>
    <p class="small">Le client ouvre ce lien sur son téléphone et l'ajoute à son écran d'accueil : il retrouve ses séances, réserve, déplace et vous écrit.</p>
    <div class="actions">
      <input readonly value="<?= e(espace_link($client['access_token'])) ?>" onclick="this.select()" style="flex:1;min-width:220px">
      <button class="btn btn-light btn-small" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copié ✓'">Copier</button>
    </div>
    <div class="actions">
      <?php $wa = whatsapp_link($client['phone'], invitation_text($client)); ?>
      <?php if ($wa): ?><a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="<?= e($wa) ?>">Envoyer par WhatsApp</a><?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><input type="hidden" name="action" value="invite_email">
        <button class="btn btn-light btn-small" type="submit" <?= has_real_email($client['email']) ? '' : 'disabled title="Pas d\'email"' ?>>Envoyer par email</button></form>
      <form method="post" class="inline" onsubmit="return confirm('Créer un nouveau lien ? L\'ancien ne fonctionnera plus.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><input type="hidden" name="action" value="regen">
        <button class="btn btn-light btn-small" type="submit">Nouveau lien</button></form>
    </div>
  </div>
  <div class="actions">
    <?php if ($client['phone'] !== ''): ?><a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="<?= e(whatsapp_link($client['phone'])) ?>">WhatsApp</a><?php endif; ?>
    <?php if (has_real_email($client['email'])): ?><a class="btn btn-light btn-small" href="mailto:<?= e($client['email']) ?>">Email</a><?php endif; ?>
    <a class="btn btn-small" href="<?= e(url('admin/nouveau.php?client=' . $client['id'])) ?>">📅 Planifier ses séances</a>
  </div>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
    <p class="muted small"><?= has_real_email($client['email']) ? e($client['email']) : 'pas d\'email' ?> · client depuis le <?= e(fr_date($client['created_at'])) ?> · <?= $done ?> séance(s) réalisée(s)</p>
    <div class="grid3">
      <div><label>Téléphone</label><input name="phone" value="<?= e($client['phone']) ?>"></div>
      <div><label>Âge</label><input type="number" name="age" value="<?= e((string) $client['age']) ?>"></div>
      <div><label>Objectif</label><input name="objective" value="<?= e($client['objective']) ?>"></div>
    </div>
    <div class="grid2">
      <div><label>Adresse des séances</label><input name="address" value="<?= e($client['address']) ?>"></div>
      <div><label>Forfait : heures par mois (0 = sans forfait)</label><input name="monthly_hours" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float) $client['monthly_hours'], 1, '.', ''), '0'), '.')) ?>"></div>
    </div>
    <label>Mes notes privées (suivi, mesures, blessures… jamais visibles par le client)</label>
    <textarea name="coach_notes" rows="6"><?= e($client['coach_notes']) ?></textarea>
    <p></p><button class="btn" type="submit">Enregistrer</button>
  </form>
  <h2>Historique</h2>
  <div class="card">
    <?php foreach ($bookings as $b): ?>
      <div class="list-item">
        <div><a href="<?= e(url('admin/rdv.php?id=' . $b['id'])) ?>"><?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?></a><br>
          <span class="muted small"><?= e($b['service_name'] . ' · ' . $b['location_name']) ?></span></div>
        <?= status_badge($b['status']) ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$bookings): ?><p class="muted">Aucun rendez-vous.</p><?php endif; ?>
  </div>
<?php else:
    $q = get('q');
    $st = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM bookings b WHERE b.client_id = c.id AND b.status = 'confirmed') AS nb,
                         (SELECT MAX(start_at) FROM bookings b WHERE b.client_id = c.id AND b.status IN ('pending','confirmed')) AS last
                         FROM clients c WHERE (c.first_name || ' ' || c.last_name || ' ' || c.email || ' ' || c.phone) LIKE ? ORDER BY c.last_name, c.first_name");
    $st->execute(['%' . $q . '%']);
    $clients = $st->fetchAll();
?>
  <h1>Clients</h1>
  <div class="actions"><a class="btn" href="?new=1">+ Nouveau client (envoyer une invitation)</a></div>
  <form class="actions"><input name="q" value="<?= e($q) ?>" placeholder="Rechercher un nom, un email…" style="flex:1"><button class="btn btn-light">Rechercher</button></form>
  <div class="table-wrap">
    <table>
      <tr><th>Nom</th><th>Objectif</th><th>Téléphone</th><th>Ce mois-ci</th><th>Dernier / prochain RDV</th></tr>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><a href="?id=<?= (int) $c['id'] ?>"><?= e($c['last_name'] . ' ' . $c['first_name']) ?></a><?= $c['age'] ? ' <span class="muted small">(' . (int) $c['age'] . ' ans)</span>' : '' ?></td>
          <td><?= e($c['objective']) ?></td>
          <td class="nowrap"><?= e($c['phone']) ?></td>
          <td class="nowrap"><?php $m = client_month_minutes((int) $c['id'], date('Y-m')); ?><?= e(fr_duration($m)) ?><?= $c['monthly_hours'] > 0 ? ' / ' . e(fr_duration((int) round($c['monthly_hours'] * 60))) : '' ?></td>
          <td class="nowrap"><?= $c['last'] ? e(date('d/m/Y', strtotime($c['last']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clients): ?><tr><td colspan="5" class="muted">Aucun client pour le moment.</td></tr><?php endif; ?>
    </table>
  </div>
<?php endif;
page_footer();
