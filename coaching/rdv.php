<?php
/*
 * Page personnelle d'un rendez-vous (lien secret envoye par email au client) :
 * statut, ajout a l'agenda, deplacement / annulation (jusqu'a 48 h avant), messages avec le coach.
 */
require __DIR__ . '/inc/bootstrap.php';

$b = find_booking_by_token(get('t'));
if (!$b) {
    http_response_code(404);
    page_header('Rendez-vous introuvable');
    echo '<h1>Rendez-vous introuvable</h1><p>Le lien est peut-être incomplet. <a href="' . e(url('mes-rdv.php')) . '">Retrouver mes rendez-vous</a></p>';
    page_footer();
    exit;
}

/* Fichier .ics pour ajouter la seance a son agenda (telephone, Google, Outlook) */
if (get('ics') === '1') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="rendez-vous-coaching.ics"');
    echo ics_calendar([ics_event($b, brand_name() . ' — ' . $b['service_name'], $b['start_at'], $b['end_at'])]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'cancel') {
        if (client_can_change($b)) {
            db()->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'client', updated_at = ? WHERE id = ?")
                ->execute([now_str(), $b['id']]);
            notify_client_cancelled($b);
            flash('Votre rendez-vous est annulé.');
        } else {
            flash('Il est trop tard pour annuler en ligne : contactez directement votre coach.', 'error');
        }
    } elseif ($action === 'message' && post('body') !== '') {
        $text = mb_substr(post('body'), 0, 2000);
        add_message((int) $b['id'], 'client', $text);
        notify_message($b, 'client', $text);
        flash('Message envoyé.');
    }
    redirect('rdv.php?t=' . $b['token']);
}

mark_messages_read((int) $b['id'], 'client');
$messages = booking_messages((int) $b['id']);
$active = in_array($b['status'], ['pending', 'confirmed'], true);
$future = hours_until($b['start_at']) > 0;
$phone = setting('coach_phone');

page_header('Mon rendez-vous');
?>
<?php if (get('new') === '1'): ?>
  <div class="alert alert-ok">
    <?= $b['status'] === 'confirmed' ? 'C\'est réservé ! Votre rendez-vous est confirmé.' : 'Merci ! Votre demande a été envoyée au coach. Vous recevrez un email dès qu\'elle sera validée.' ?>
    Un email récapitulatif vous a été envoyé : gardez-le, il contient le lien vers cette page.
  </div>
<?php endif; ?>

<h1>Mon rendez-vous</h1>
<div class="card">
  <p><?= status_badge($b['status']) ?></p>
  <h2 style="margin-top:0"><?= e($b['service_name']) ?></h2>
  <p><strong><?= e(ucfirst(fr_slot($b['start_at'], $b['end_at']))) ?></strong><br>
     <?= e($b['location_name']) ?><?= $b['address'] !== '' ? '<br>' . e($b['address']) : '' ?></p>
  <p class="muted small">Au nom de <?= e($b['first_name'] . ' ' . $b['last_name']) ?></p>

  <?php if ($active && $future): ?>
    <div class="actions">
      <a class="btn btn-light" href="<?= e(url('rdv.php?t=' . $b['token'] . '&ics=1')) ?>">📅 Ajouter à mon agenda</a>
      <?php if (client_can_change($b)): ?>
        <a class="btn btn-light" href="<?= e(url('reserver.php?t=' . $b['token'])) ?>">Déplacer</a>
        <form method="post" onsubmit="return confirm('Annuler ce rendez-vous ?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <button class="btn btn-danger" type="submit">Annuler</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if (!client_can_change($b)): ?>
      <div class="alert alert-warn">Le rendez-vous a lieu dans moins de <?= setting_int('cancel_hours', 48) ?> h : il ne peut plus être déplacé ou annulé en ligne.
        Contactez directement votre coach<?= $phone !== '' ? ' par WhatsApp ou par téléphone' : '' ?>.</div>
    <?php else: ?>
      <p class="muted small"><?= e(cancel_policy_text()) ?></p>
    <?php endif; ?>
  <?php elseif (!$active): ?>
    <div class="actions"><a class="btn" href="<?= e(url('reserver.php')) ?>">Réserver un autre créneau</a></div>
  <?php endif; ?>
</div>

<h2>Échanger avec le coach</h2>
<div class="card">
  <?php if ($messages): ?>
    <div class="thread">
      <?php foreach ($messages as $m): ?>
        <div class="msg <?= $m['sender'] === 'client' ? 'me' : 'them' ?>"><?= e($m['body']) ?><small><?= $m['sender'] === 'client' ? 'Vous' : 'Coach' ?> · <?= e(fr_date($m['created_at']) . ' ' . fr_time($m['created_at'])) ?></small></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">Une question, une précision ? Écrivez au coach ici, il reçoit votre message par email.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="message">
    <textarea name="body" required maxlength="2000" placeholder="Votre message…"></textarea>
    <div class="actions">
      <button class="btn" type="submit">Envoyer</button>
      <?php if ($phone !== ''): ?>
        <a class="btn btn-wa" target="_blank" rel="noopener" href="<?= e(whatsapp_link($phone, "Bonjour, c'est {$b['first_name']} {$b['last_name']} pour mon rendez-vous du " . fr_date($b['start_at']) . ' à ' . fr_time($b['start_at']) . '. ')) ?>">Ou par WhatsApp</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php page_footer();
