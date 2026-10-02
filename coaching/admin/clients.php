<?php
/* Liste des clients et fiche client (profil, historique, notes privees du coach). */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo->prepare('UPDATE clients SET coach_notes = ?, phone = ?, age = ?, objective = ? WHERE id = ?')
        ->execute([post('coach_notes'), post('phone'), post('age') !== '' ? (int) post('age') : null, post('objective'), (int) post('id')]);
    flash('Fiche client enregistrée.');
    redirect('admin/clients.php?id=' . (int) post('id'));
}

$client = get('id') ? find_row('clients', (int) get('id')) : null;
page_header('Clients', true);

if ($client):
    $st = $pdo->prepare('SELECT * FROM bookings WHERE client_id = ? ORDER BY start_at DESC');
    $st->execute([$client['id']]);
    $bookings = $st->fetchAll();
    $done = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed' && $b['end_at'] < date('Y-m-d H:i')));
?>
  <p><a href="<?= e(url('admin/clients.php')) ?>">← Tous les clients</a></p>
  <h1><?= e($client['first_name'] . ' ' . $client['last_name']) ?></h1>
  <div class="actions">
    <?php if ($client['phone'] !== ''): ?><a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="<?= e(whatsapp_link($client['phone'])) ?>">WhatsApp</a><?php endif; ?>
    <a class="btn btn-light btn-small" href="mailto:<?= e($client['email']) ?>">Email</a>
    <a class="btn btn-small" href="<?= e(url('admin/nouveau.php?client=' . $client['id'])) ?>">+ Rendez-vous</a>
  </div>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>">
    <p class="muted small"><?= e($client['email']) ?> · client depuis le <?= e(fr_date($client['created_at'])) ?> · <?= $done ?> séance(s) réalisée(s)</p>
    <div class="grid3">
      <div><label>Téléphone</label><input name="phone" value="<?= e($client['phone']) ?>"></div>
      <div><label>Âge</label><input type="number" name="age" value="<?= e((string) $client['age']) ?>"></div>
      <div><label>Objectif</label><input name="objective" value="<?= e($client['objective']) ?>"></div>
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
  <form class="actions"><input name="q" value="<?= e($q) ?>" placeholder="Rechercher un nom, un email…" style="flex:1"><button class="btn btn-light">Rechercher</button></form>
  <div class="table-wrap">
    <table>
      <tr><th>Nom</th><th>Objectif</th><th>Téléphone</th><th>Séances</th><th>Dernier / prochain RDV</th></tr>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><a href="?id=<?= (int) $c['id'] ?>"><?= e($c['last_name'] . ' ' . $c['first_name']) ?></a><?= $c['age'] ? ' <span class="muted small">(' . (int) $c['age'] . ' ans)</span>' : '' ?></td>
          <td><?= e($c['objective']) ?></td>
          <td class="nowrap"><?= e($c['phone']) ?></td>
          <td><?= (int) $c['nb'] ?></td>
          <td class="nowrap"><?= $c['last'] ? e(date('d/m/Y', strtotime($c['last']))) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$clients): ?><tr><td colspan="5" class="muted">Aucun client pour le moment.</td></tr><?php endif; ?>
    </table>
  </div>
<?php endif;
page_footer();
