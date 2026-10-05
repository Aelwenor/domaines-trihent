<?php
/* Fiche d'un rendez-vous cote coach : client, actions, deplacement, messages. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$b = find_booking((int) get('id'));
if (!$b) {
    flash('Rendez-vous introuvable.', 'error');
    redirect('admin/');
}
mark_messages_read((int) $b['id'], 'coach');
$messages = booking_messages((int) $b['id']);
$active = in_array($b['status'], ['pending', 'confirmed'], true);
$duration = (int) ((strtotime($b['end_at']) - strtotime($b['start_at'])) / 60);

// Creneaux libres pour proposer un deplacement (jour choisi, sans les regles imposees aux clients)
$moveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('d')) ? get('d') : substr($b['start_at'], 0, 10);
$freeSlots = $active ? available_slots($moveDate, $duration, (int) $b['travel'], (int) $b['id'], false) : [];

$wa = whatsapp_link($b['phone'], "Bonjour {$b['first_name']}, c'est " . (setting('coach_name') ?: brand_name()) . ' pour ta séance du ' . fr_date($b['start_at']) . ' à ' . fr_time($b['start_at']) . '. ');

function action_form(array $b, string $action, string $label, string $class, bool $withNote = false, string $confirm = ''): string
{
    $h = '<form method="post" action="' . e(url('admin/actions.php')) . '"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>';
    $h .= csrf_field() . '<input type="hidden" name="id" value="' . (int) $b['id'] . '"><input type="hidden" name="action" value="' . e($action) . '">';
    if ($withNote) {
        $h .= '<textarea name="note" placeholder="Message pour le client (facultatif)"></textarea><p></p>';
    }
    return $h . '<button class="btn ' . $class . '" type="submit">' . e($label) . '</button></form>';
}

page_header('Rendez-vous', true);
?>
<p><a href="<?= e(url('admin/')) ?>">← Tableau de bord</a></p>
<h1><?= e($b['first_name'] . ' ' . $b['last_name']) ?> <?= status_badge($b['status']) ?></h1>

<div class="grid2">
  <div class="card">
    <h3><?= e($b['service_name']) ?></h3>
    <p><strong><?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?></strong><br>
      <?= e($b['location_name']) ?><?= $b['address'] !== '' ? '<br>📍 <a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=' . e(rawurlencode($b['address'])) . '">' . e($b['address']) . '</a>' : '' ?></p>
    <?php if ($b['travel'] > 0): ?>
      <p class="travel">🚗 Trajet <?= (int) $b['travel'] ?> min aller / <?= (int) $b['travel'] ?> min retour — agenda bloqué de <?= e(fr_time($b['occ_start'])) ?> à <?= e(fr_time($b['occ_end'])) ?></p>
    <?php endif; ?>
    <?php if ($b['client_note'] !== ''): ?><p><em>« <?= nl2br(e($b['client_note'])) ?> »</em></p><?php endif; ?>
    <p class="muted small">Créé le <?= e(fr_date($b['created_at']) . ' à ' . fr_time($b['created_at'])) ?>
      <?= $b['cancelled_by'] === 'client' ? ' · annulé par le client' : '' ?></p>
  </div>
  <div class="card">
    <h3>Client</h3>
    <p><?= e($b['first_name'] . ' ' . $b['last_name']) ?><?= $b['age'] ? ', ' . (int) $b['age'] . ' ans' : '' ?><br>
      Objectif : <?= e($b['objective'] ?: '—') ?><br>
      📞 <a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a><br>
      ✉️ <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></p>
    <?php if ($b['coach_notes'] !== ''): ?><p class="small" style="background:var(--bg);padding:8px 10px;border-radius:8px">📝 <?= nl2br(e($b['coach_notes'])) ?></p><?php endif; ?>
    <div class="actions">
      <?php if ($wa): ?><a class="btn btn-wa btn-small" target="_blank" rel="noopener" href="<?= e($wa) ?>">WhatsApp</a><?php endif; ?>
      <a class="btn btn-light btn-small" href="<?= e(url('admin/clients.php?id=' . $b['client_id'])) ?>">Fiche client</a>
    </div>
  </div>
</div>

<?php if ($b['status'] === 'pending'): ?>
  <h2>Valider la demande ?</h2>
  <div class="grid2">
    <div class="card"><?= action_form($b, 'confirm', '✓ Valider', 'btn-ok', true) ?></div>
    <div class="card"><?= action_form($b, 'refuse', '✕ Refuser', 'btn-danger', true, 'Refuser cette demande ?') ?></div>
  </div>
<?php endif; ?>

<?php if ($active): ?>
  <h2>Déplacer le rendez-vous</h2>
  <div class="card">
    <form method="get" class="actions">
      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <label for="d" style="margin:0">Créneaux libres le</label>
      <input id="d" type="date" name="d" value="<?= e($moveDate) ?>" style="width:auto">
      <button class="btn btn-light btn-small" type="submit">Voir</button>
    </form>
    <p class="small muted">
      <?= $freeSlots ? 'Libres (trajet compris) : ' . e(implode(', ', array_map(fn($s) => str_replace(':', 'h', $s), $freeSlots))) : 'Aucun créneau libre ce jour-là dans vos plages habituelles.' ?>
    </p>
    <form method="post" action="<?= e(url('admin/actions.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="move">
      <div class="grid3">
        <div><label>Nouvelle date</label><input type="date" name="date" required value="<?= e($moveDate) ?>"></div>
        <div><label>Heure de début</label><input type="time" name="time" required step="300" value="<?= e($freeSlots[0] ?? substr($b['start_at'], 11, 5)) ?>"></div>
        <div><label class="check" style="margin-top:42px"><input type="checkbox" name="force" value="1"> forcer même si occupé</label></div>
      </div>
      <label>Message pour le client (facultatif)</label>
      <textarea name="note"></textarea>
      <p></p>
      <button class="btn" type="submit">Déplacer et prévenir le client</button>
    </form>
  </div>

  <div class="actions">
    <?= action_form($b, 'cancel', 'Annuler ce rendez-vous', 'btn-light', false, 'Annuler ce rendez-vous ? Le client sera prévenu par email.') ?>
  </div>
<?php endif; ?>

<h2 id="messages">Messages</h2>
<div class="card">
  <?php if ($messages): ?>
    <div class="thread">
      <?php foreach ($messages as $m): ?>
        <div class="msg <?= $m['sender'] === 'coach' ? 'me' : 'them' ?>"><?= e($m['body']) ?><small><?= $m['sender'] === 'coach' ? 'Vous' : e($b['first_name']) ?> · <?= e(fr_date($m['created_at']) . ' ' . fr_time($m['created_at'])) ?></small></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="muted">Aucun message pour ce rendez-vous.</p><?php endif; ?>
  <form method="post" action="<?= e(url('admin/actions.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="action" value="message">
    <textarea name="note" required placeholder="Votre message (le client le reçoit par email)"></textarea>
    <p></p><button class="btn" type="submit">Envoyer</button>
  </form>
</div>
<?php page_footer();
