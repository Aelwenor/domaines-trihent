<?php
/*
 * Espace personnel du client, ouvert depuis le lien d'invitation envoye par le coach.
 * Le client l'installe sur l'ecran d'accueil de son telephone : il y retrouve ses seances,
 * reserve, deplace ou annule, et ecrit a son coach.
 */
require __DIR__ . '/inc/bootstrap.php';

$client = find_client_by_token(get('c')) ?? current_client();
if (!$client) {
    http_response_code(404);
    page_header('Espace introuvable');
    echo '<h1>Espace introuvable</h1><p>Le lien est incomplet ou a été remplacé. Demandez un nouveau lien à votre coach.</p>';
    page_footer();
    exit;
}
// Memorise le client sur ce telephone (utile pour la reservation)
$_SESSION['client_token'] = $client['access_token'];

$now = date('Y-m-d H:i');
$st = db()->prepare("SELECT * FROM bookings WHERE client_id = ? AND status IN ('pending', 'confirmed') AND end_at >= ? ORDER BY start_at");
$st->execute([$client['id'], $now]);
$upcoming = $st->fetchAll();
$st = db()->prepare("SELECT * FROM bookings WHERE client_id = ? AND (end_at < ? OR status IN ('refused', 'cancelled')) ORDER BY start_at DESC LIMIT 10");
$st->execute([$client['id'], $now]);
$past = $st->fetchAll();
$st = db()->prepare("SELECT COUNT(*) FROM messages m JOIN bookings b ON b.id = m.booking_id WHERE b.client_id = ? AND m.sender = 'coach' AND m.is_read = 0");
$st->execute([$client['id']]);
$unread = (int) $st->fetchColumn();
$phone = setting('coach_phone');
$selfUrl = 'espace.php?c=' . $client['access_token'];

page_header('Mon espace', false, url('manifest.php?c=' . $client['access_token']));
?>
<h1>Bonjour <?= e($client['first_name']) ?> 👋</h1>

<div class="card install-card" id="install">
  <strong>📲 Installez votre espace sur votre téléphone</strong>
  <p class="small" id="install-ios" hidden>Sur iPhone : touchez <strong>Partager</strong> <span aria-hidden="true">(carré avec une flèche)</span>, puis <strong>« Sur l'écran d'accueil »</strong>.</p>
  <p class="small" id="install-android" hidden>Sur Android : ouvrez le menu <strong>⋮</strong> de Chrome, puis <strong>« Installer l'application »</strong> ou « Ajouter à l'écran d'accueil ».</p>
  <p class="small" id="install-other">Ouvrez ce lien sur votre téléphone, puis ajoutez-le à l'écran d'accueil : votre espace s'ouvrira comme une application.</p>
  <button class="btn btn-small" id="install-btn" type="button" hidden>Installer</button>
</div>

<div class="actions">
  <a class="btn" href="<?= e(url('reserver.php')) ?>">Réserver une séance</a>
  <?php if ($phone !== ''): ?><a class="btn btn-wa" target="_blank" rel="noopener" href="<?= e(whatsapp_link($phone, 'Bonjour, c\'est ' . $client['first_name'] . '. ')) ?>">WhatsApp du coach</a><?php endif; ?>
</div>

<?php if ($unread): ?><div class="alert alert-info">Vous avez <?= $unread ?> nouveau(x) message(s) de votre coach : ouvrez la séance concernée.</div><?php endif; ?>

<h2>Mes prochaines séances</h2>
<div class="card">
  <?php foreach ($upcoming as $b): ?>
    <div class="list-item">
      <div><strong><?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?></strong><br>
        <span class="muted small"><?= e($b['service_name'] . ' · ' . $b['location_name']) ?></span></div>
      <div class="actions" style="margin:0"><?= status_badge($b['status']) ?>
        <a class="btn btn-light btn-small" href="<?= e(url('rdv.php?t=' . $b['token'])) ?>">Ouvrir</a></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$upcoming): ?><p class="muted">Aucune séance prévue. <a href="<?= e(url('reserver.php')) ?>">Réserver un créneau</a></p><?php endif; ?>
</div>
<p class="muted small"><?= e(cancel_policy_text()) ?></p>

<?php if ($past): ?>
  <h2>Historique</h2>
  <div class="card">
    <?php foreach ($past as $b): ?>
      <div class="list-item">
        <div><?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?><br><span class="muted small"><?= e($b['service_name']) ?></span></div>
        <?= $b['status'] === 'confirmed' ? '<span class="badge badge-confirmed">Réalisée</span>' : status_badge($b['status']) ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  var card = document.getElementById('install');
  if (standalone) { card.hidden = true; return; }
  var ua = navigator.userAgent;
  if (/iPhone|iPad|iPod/.test(ua)) { show('install-ios'); }
  else if (/Android/.test(ua)) { show('install-android'); }
  function show(id) { document.getElementById(id).hidden = false; document.getElementById('install-other').hidden = true; }
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    var btn = document.getElementById('install-btn');
    btn.hidden = false;
    btn.onclick = function () { e.prompt(); };
  });
})();
</script>
<?php page_footer();
