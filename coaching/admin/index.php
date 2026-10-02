<?php
/* Tableau de bord du coach : demandes a valider, messages, prochains rendez-vous. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$pdo = db();
$now = date('Y-m-d H:i');
$pending = $pdo->query("SELECT b.*, c.first_name, c.last_name, c.phone, c.age, c.objective FROM bookings b JOIN clients c ON c.id = b.client_id
                        WHERE b.status = 'pending' ORDER BY b.start_at")->fetchAll();
$st = $pdo->prepare("SELECT b.*, c.first_name, c.last_name, c.phone FROM bookings b JOIN clients c ON c.id = b.client_id
                     WHERE b.status = 'confirmed' AND b.end_at >= ? AND b.start_at < ? ORDER BY b.start_at");
$st->execute([$now, date('Y-m-d', strtotime('+15 days'))]);
$upcoming = $st->fetchAll();
$unread = $pdo->query("SELECT m.*, b.id AS bid, c.first_name, c.last_name FROM messages m JOIN bookings b ON b.id = m.booking_id
                       JOIN clients c ON c.id = b.client_id WHERE m.sender = 'client' AND m.is_read = 0 ORDER BY m.id DESC")->fetchAll();
$st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND start_at >= ? AND start_at < ?");
$st->execute([date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('monday next week'))]);
$thisWeek = (int) $st->fetchColumn();
$clients = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();

$todo = [];
if (setting('coach_phone') === '') $todo[] = ['admin/profil.php', 'Ajoutez votre numéro WhatsApp et votre logo'];
if (setting('coach_email') === '') $todo[] = ['admin/profil.php', 'Ajoutez votre email pour recevoir les demandes'];
if (!$pdo->query('SELECT COUNT(*) FROM availability')->fetchColumn()) $todo[] = ['admin/disponibilites.php', 'Indiquez vos jours et heures de coaching'];

$bookingUrl = absolute_url('');

page_header('Tableau de bord', true);
?>
<h1>Bonjour<?= setting('coach_name') !== '' ? ' ' . e(explode(' ', setting('coach_name'))[0]) : '' ?> 👋</h1>

<?php foreach ($todo as [$link, $label]): ?>
  <div class="alert alert-warn">À faire : <a href="<?= e(url($link)) ?>"><?= e($label) ?></a></div>
<?php endforeach; ?>

<div class="stats">
  <div class="stat"><b><?= count($pending) ?></b>demande(s) à valider</div>
  <div class="stat"><b><?= $thisWeek ?></b>séance(s) cette semaine</div>
  <div class="stat"><b><?= count($unread) ?></b>message(s) non lu(s)</div>
  <div class="stat"><b><?= $clients ?></b>client(s)</div>
</div>

<div class="card">
  <strong>Votre lien de réservation à partager :</strong>
  <div class="actions">
    <input readonly value="<?= e($bookingUrl) ?>" onclick="this.select()" style="flex:1;min-width:220px">
    <button class="btn btn-light btn-small" type="button" onclick="navigator.clipboard.writeText('<?= e($bookingUrl) ?>');this.textContent='Copié ✓'">Copier</button>
    <a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode('Pour réserver ta séance de coaching, choisis ton créneau ici : ' . $bookingUrl)) ?>">Envoyer par WhatsApp</a>
  </div>
  <p class="help">Nouveau client (contrat signé) ? <a href="<?= e(url('admin/clients.php?new=1')) ?>">Créez sa fiche et envoyez-lui son lien d'espace personnel</a>.</p>
  <p class="help">Mode de validation : <strong><?= setting('auto_confirm') === '1' ? 'automatique' : 'manuel (vous validez chaque demande)' ?></strong> — <a href="<?= e(url('admin/reglages.php')) ?>">modifier</a></p>
</div>

<h2>Demandes à valider</h2>
<?php if (!$pending): ?><p class="muted">Aucune demande en attente.</p><?php endif; ?>
<?php foreach ($pending as $b): ?>
  <div class="card">
    <div class="list-item" style="border:0;padding:0">
      <div>
        <strong><a href="<?= e(url('admin/rdv.php?id=' . $b['id'])) ?>"><?= e($b['first_name'] . ' ' . $b['last_name']) ?></a></strong>
        <?= $b['age'] ? '<span class="muted">· ' . (int) $b['age'] . ' ans</span>' : '' ?><br>
        <?= e($b['service_name']) ?> · <?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?><br>
        <span class="muted"><?= e($b['location_name']) ?></span>
        <?php if ($b['travel'] > 0): ?><br><span class="travel">🚗 Bloqué de <?= e(fr_time($b['occ_start'])) ?> à <?= e(fr_time($b['occ_end'])) ?> (trajet compris)</span><?php endif; ?>
        <?php if ($b['client_note'] !== ''): ?><br><em class="small">« <?= e($b['client_note']) ?> »</em><?php endif; ?>
      </div>
      <div class="actions">
        <form method="post" action="<?= e(url('admin/actions.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="back" value="dashboard">
          <button class="btn btn-ok btn-small" name="action" value="confirm">✓ Valider</button>
          <button class="btn btn-danger btn-small" name="action" value="refuse" onclick="return confirm('Refuser cette demande ?')">✕ Refuser</button>
        </form>
        <a class="btn btn-light btn-small" href="<?= e(url('admin/rdv.php?id=' . $b['id'])) ?>">Proposer un autre horaire</a>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($unread): ?>
  <h2>Messages non lus</h2>
  <div class="card">
    <?php foreach ($unread as $m): ?>
      <div class="list-item">
        <div><strong><?= e($m['first_name'] . ' ' . $m['last_name']) ?></strong> : <?= e(mb_strimwidth($m['body'], 0, 120, '…')) ?></div>
        <a class="btn btn-light btn-small" href="<?= e(url('admin/rdv.php?id=' . $m['bid'])) ?>#messages">Répondre</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2>Prochaines séances (15 jours)</h2>
<div class="card">
  <?php if (!$upcoming): ?><p class="muted">Aucune séance confirmée pour le moment.</p><?php endif; ?>
  <?php $day = ''; foreach ($upcoming as $b): ?>
    <?php if ($day !== substr($b['start_at'], 0, 10)): $day = substr($b['start_at'], 0, 10); ?>
      <h3 style="margin-top:12px"><?= e(ucfirst(fr_date($day))) ?></h3>
    <?php endif; ?>
    <div class="list-item">
      <div>
        <span class="time"><?= e(fr_time($b['start_at']) . ' – ' . fr_time($b['end_at'])) ?></span>
        <a href="<?= e(url('admin/rdv.php?id=' . $b['id'])) ?>"><?= e($b['first_name'] . ' ' . $b['last_name']) ?></a> · <?= e($b['service_name']) ?><br>
        <span class="muted small"><?= e($b['location_name']) ?><?= $b['address'] !== '' ? ' — ' . e($b['address']) : '' ?></span>
        <?php if ($b['travel'] > 0): ?><span class="travel"> · départ <?= e(fr_time($b['occ_start'])) ?>, retour <?= e(fr_time(date('Y-m-d H:i', strtotime($b['end_at']) + $b['travel'] * 60))) ?></span><?php endif; ?>
      </div>
      <?php if ($b['phone'] !== ''): ?><a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="<?= e(whatsapp_link($b['phone'])) ?>">WhatsApp</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php page_footer();
