<?php
/*
 * Disponibilites du coach :
 *  - plages habituelles de la semaine (ex. lundi 8h-12h et 14h-20h)
 *  - indisponibilites ponctuelles (conges, journee ou heures bloquees)
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch (post('action')) {
        case 'add_window':
            $start = post('start_time');
            $end = post('end_time');
            $days = array_map('intval', (array) ($_POST['weekdays'] ?? []));
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                flash("L'heure de fin doit être après l'heure de début.", 'error');
            } elseif (!$days) {
                flash('Cochez au moins un jour.', 'error');
            } else {
                $st = $pdo->prepare('INSERT INTO availability(weekday, start_time, end_time) VALUES (?, ?, ?)');
                foreach ($days as $d) {
                    if ($d >= 1 && $d <= 7) {
                        $st->execute([$d, $start, $end]);
                    }
                }
                flash('Plage ajoutée.');
            }
            break;

        case 'delete_window':
            $pdo->prepare('DELETE FROM availability WHERE id = ?')->execute([(int) post('id')]);
            flash('Plage supprimée.');
            break;

        case 'add_block':
            $from = post('date_from');
            $to = post('date_to') ?: $from;
            $hs = post('hour_from');
            $he = post('hour_to');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
                flash('Dates invalides.', 'error');
                break;
            }
            $st = $pdo->prepare('INSERT INTO blocks(start_at, end_at, reason) VALUES (?, ?, ?)');
            if ($hs === '' && $he === '') {
                // Journee(s) entiere(s)
                $st->execute(["$from 00:00", date('Y-m-d', strtotime("$to +1 day")) . ' 00:00', post('reason')]);
            } else {
                $hs = $hs ?: '00:00';
                $he = $he ?: '23:59';
                if ($hs >= $he) {
                    flash("L'heure de fin doit être après l'heure de début.", 'error');
                    break;
                }
                // Memes heures bloquees sur chaque jour de la periode
                for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                    $st->execute(["$d $hs", "$d $he", post('reason')]);
                }
            }
            $check = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','confirmed') AND occ_start < ? AND occ_end > ?");
            $check->execute([date('Y-m-d', strtotime("$to +1 day")) . ' 00:00', "$from 00:00"]);
            flash('Indisponibilité enregistrée : ces créneaux ne sont plus proposés aux clients.');
            if ($check->fetchColumn() > 0) {
                flash('Attention : des rendez-vous existent déjà sur cette période. Pensez à les déplacer ou les annuler (voir Agenda).', 'warn');
            }
            break;

        case 'delete_block':
            $pdo->prepare('DELETE FROM blocks WHERE id = ?')->execute([(int) post('id')]);
            flash('Indisponibilité supprimée.');
            break;
    }
    redirect('admin/disponibilites.php');
}

$windows = [];
foreach ($pdo->query('SELECT * FROM availability ORDER BY weekday, start_time') as $w) {
    $windows[(int) $w['weekday']][] = $w;
}
$st = $pdo->prepare('SELECT * FROM blocks WHERE end_at >= ? ORDER BY start_at');
$st->execute([date('Y-m-d H:i')]);
$blocks = $st->fetchAll();

page_header('Disponibilités', true);
?>
<h1>Disponibilités</h1>

<h2>Mes horaires habituels</h2>
<p class="muted">Les clients ne peuvent réserver que dans ces plages. Si vous vous déplacez, le trajet doit aussi tenir dedans
  (ex. plage 9h–12h, séance à Pordic : première séance possible à 9h30, dernière à 10h30).</p>
<div class="card">
  <?php for ($d = 1; $d <= 7; $d++): ?>
    <div class="list-item">
      <strong style="min-width:100px"><?= e(ucfirst(JOURS[$d])) ?></strong>
      <div class="actions" style="margin:0;flex:1">
        <?php foreach ($windows[$d] ?? [] as $w): ?>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_window"><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
            <span class="badge badge-confirmed"><?= e(str_replace(':', 'h', $w['start_time'])) ?> – <?= e(str_replace(':', 'h', $w['end_time'])) ?>
              <button type="submit" title="Supprimer" style="border:0;background:none;cursor:pointer;color:inherit">✕</button></span>
          </form>
        <?php endforeach; ?>
        <?php if (empty($windows[$d])): ?><span class="muted">Pas de coaching</span><?php endif; ?>
      </div>
    </div>
  <?php endfor; ?>
</div>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="add_window">
  <h3>Ajouter une plage</h3>
  <div class="actions">
    <?php for ($d = 1; $d <= 7; $d++): ?>
      <label class="check" style="margin:0"><input type="checkbox" name="weekdays[]" value="<?= $d ?>"> <?= e(ucfirst(JOURS[$d])) ?></label>
    <?php endfor; ?>
  </div>
  <div class="grid3">
    <div><label>De</label><input type="time" name="start_time" required value="09:00"></div>
    <div><label>À</label><input type="time" name="end_time" required value="12:00"></div>
    <div><label>&nbsp;</label><button class="btn" type="submit">Ajouter</button></div>
  </div>
</form>

<h2>Bloquer des jours ou des heures</h2>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="add_block">
  <div class="grid2">
    <div><label>Du</label><input type="date" name="date_from" required value="<?= e(date('Y-m-d')) ?>"></div>
    <div><label>Au (facultatif)</label><input type="date" name="date_to"></div>
    <div><label>De (heure, facultatif)</label><input type="time" name="hour_from"></div>
    <div><label>À (heure, facultatif)</label><input type="time" name="hour_to"></div>
  </div>
  <p class="help">Sans heures : la ou les journées entières sont bloquées. Avec des heures : ces heures sont bloquées chaque jour de la période.</p>
  <label>Motif (visible par vous seul)</label>
  <input name="reason" placeholder="Vacances, rendez-vous perso…">
  <p></p>
  <button class="btn" type="submit">Bloquer</button>
</form>

<?php if ($blocks): ?>
  <div class="card">
    <h3>Indisponibilités à venir</h3>
    <?php foreach ($blocks as $bl): ?>
      <div class="list-item">
        <div>
          <?php $fullDays = substr($bl['start_at'], 11) === '00:00' && substr($bl['end_at'], 11) === '00:00'; ?>
          <?php if ($fullDays): ?>
            <?php $last = date('Y-m-d', strtotime($bl['end_at'] . ' -1 day')); ?>
            <strong><?= e(ucfirst(fr_date($bl['start_at']))) ?><?= $last !== substr($bl['start_at'], 0, 10) ? ' → ' . e(fr_date($last)) : '' ?></strong> (journée entière)
          <?php else: ?>
            <strong><?= e(ucfirst(fr_date($bl['start_at']))) ?></strong> de <?= e(fr_time($bl['start_at'])) ?> à <?= e(fr_time($bl['end_at'])) ?>
          <?php endif; ?>
          <?= $bl['reason'] !== '' ? '<span class="muted"> — ' . e($bl['reason']) . '</span>' : '' ?>
        </div>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_block"><input type="hidden" name="id" value="<?= (int) $bl['id'] ?>">
          <button class="btn btn-light btn-small" type="submit">Débloquer</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
