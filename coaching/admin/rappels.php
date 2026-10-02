<?php
/*
 * Rappels parametrables : pour le coach (programme du jour, du lendemain, de la semaine)
 * et pour les clients (veille, semaine, quelques heures avant).
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post('id');
    if (post('action') === 'delete') {
        $pdo->prepare('DELETE FROM reminders WHERE id = ?')->execute([$id]);
        flash('Rappel supprimé.');
    } elseif (post('action') === 'test') {
        $mail = coach_digest(post('kind'), time());
        if ($mail) {
            mail_coach('[Essai] ' . $mail[0], $mail[1]);
            flash('Email d\'essai envoyé à ' . setting('coach_email') . '.');
        } else {
            flash('Rien à envoyer : aucune séance ni demande en attente sur cette période.', 'info');
        }
    } else {
        $kind = array_key_exists(post('kind'), RAPPEL_TYPES) ? post('kind') : 'eve';
        $data = [
            post('target') === 'client' ? 'client' : 'coach',
            $kind,
            min(7, max(1, (int) post('weekday', '7'))),
            preg_match('/^\d{2}:\d{2}$/', post('time')) ? post('time') : '08:00',
            min(168, max(1, (int) post('hours', '24'))),
            post('active') === '1' ? 1 : 0,
        ];
        if ($id) {
            $pdo->prepare('UPDATE reminders SET target = ?, kind = ?, weekday = ?, time = ?, hours = ?, active = ? WHERE id = ?')->execute([...$data, $id]);
        } else {
            $pdo->prepare('INSERT INTO reminders(target, kind, weekday, time, hours, active) VALUES (?, ?, ?, ?, ?, ?)')->execute($data);
        }
        flash('Rappel enregistré.');
    }
    redirect('admin/rappels.php');
}

$rules = $pdo->query("SELECT * FROM reminders ORDER BY target, kind, time")->fetchAll();
$byTarget = ['coach' => [], 'client' => []];
foreach ($rules as $r) {
    $byTarget[$r['target']][] = $r;
}

function reminder_form(array $r, string $target): string
{
    ob_start(); ?>
    <form method="post" class="reminder-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>"><input type="hidden" name="target" value="<?= e($target) ?>">
      <div class="reminder-grid">
        <label class="check" style="margin:0"><input type="checkbox" name="active" value="1" <?= !empty($r['active']) ? 'checked' : '' ?>> Actif</label>
        <select name="kind" aria-label="Type de rappel" onchange="this.form.dataset.kind = this.value">
          <?php foreach (RAPPEL_TYPES as $k => $label): if ($target === 'coach' && $k === 'before') $label = 'Avant chaque séance (alerte)'; ?>
            <option value="<?= $k ?>" <?= ($r['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="weekday" class="only-weekly" aria-label="Jour">
          <?php for ($d = 1; $d <= 7; $d++): ?><option value="<?= $d ?>" <?= (int) ($r['weekday'] ?? 7) === $d ? 'selected' : '' ?>>le <?= JOURS[$d] ?></option><?php endfor; ?>
        </select>
        <input type="time" name="time" class="only-time" value="<?= e($r['time'] ?? '08:00') ?>" aria-label="Heure d'envoi">
        <span class="only-before"><input type="number" name="hours" min="1" max="168" value="<?= (int) ($r['hours'] ?? 24) ?>" aria-label="Heures avant" style="width:80px"> h avant</span>
        <span class="actions" style="margin:0">
          <button class="btn btn-small" type="submit"><?= empty($r['id']) ? 'Ajouter' : 'Enregistrer' ?></button>
          <?php if (!empty($r['id'])): ?><button class="btn btn-light btn-small" type="submit" name="action" value="delete">Supprimer</button><?php endif; ?>
        </span>
      </div>
    </form>
    <?php return ob_get_clean();
}

$cronUrl = absolute_url('cron.php?k=' . setting('cron_key'));
page_header('Rappels', true);
?>
<h1>Rappels</h1>
<p class="muted">Les rappels partent par email, aux heures que vous choisissez. Ajoutez-en autant que vous voulez.</p>

<h2>Pour moi (coach)</h2>
<div class="card">
  <?php foreach ($byTarget['coach'] as $r): ?>
    <div class="reminder-row"><strong><?= e(reminder_label($r)) ?></strong> <?= $r['active'] ? '' : '<span class="badge badge-cancelled">désactivé</span>' ?>
      <?= reminder_form($r, 'coach') ?></div>
  <?php endforeach; ?>
  <div class="reminder-row"><strong>+ Nouveau rappel pour moi</strong><?= reminder_form(['kind' => 'morning', 'time' => '07:00', 'active' => 1], 'coach') ?></div>
  <div class="actions">
    <span class="muted small">Recevoir un essai maintenant :</span>
    <?php foreach (['morning' => 'Aujourd\'hui', 'eve' => 'Demain', 'weekly' => '7 jours'] as $k => $l): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="kind" value="<?= $k ?>">
        <button class="btn btn-light btn-small" type="submit"><?= $l ?></button></form>
    <?php endforeach; ?>
  </div>
</div>

<h2>Pour mes clients</h2>
<div class="card">
  <p class="muted small">Chaque client ne reçoit que ses propres séances confirmées, avec le lien vers son espace.</p>
  <?php foreach ($byTarget['client'] as $r): ?>
    <div class="reminder-row"><strong><?= e(reminder_label($r)) ?></strong> <?= $r['active'] ? '' : '<span class="badge badge-cancelled">désactivé</span>' ?>
      <?= reminder_form($r, 'client') ?></div>
  <?php endforeach; ?>
  <div class="reminder-row"><strong>+ Nouveau rappel pour les clients</strong><?= reminder_form(['kind' => 'eve', 'time' => '18:00', 'active' => 1], 'client') ?></div>
</div>

<div class="card">
  <h2 style="margin-top:0">Pour des rappels à l'heure pile</h2>
  <p>Sans réglage, les rappels partent lorsque quelqu'un ouvre l'application. Pour qu'ils partent à l'heure exacte, créez une <strong>tâche planifiée</strong> chez votre hébergeur, toutes les 15 minutes, avec cette commande :</p>
  <input readonly value="php <?= e(APP_ROOT) ?>/cron.php" onclick="this.select()">
  <p class="help">Ou, si l'hébergeur demande une adresse web : <code><?= e($cronUrl) ?></code></p>
</div>
<style>
  .reminder-row { padding: 12px 0; border-bottom: 1px solid var(--line); }
  .reminder-row:last-of-type { border-bottom: 0; }
  .reminder-grid { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
  .reminder-grid select, .reminder-grid input[type=time] { width: auto; }
  .reminder-form:not([data-kind="weekly"]) .only-weekly,
  .reminder-form[data-kind="before"] .only-time,
  .reminder-form:not([data-kind="before"]) .only-before { display: none; }
</style>
<script>document.querySelectorAll('.reminder-form').forEach(function (f) { f.dataset.kind = f.querySelector('[name=kind]').value; });</script>
<?php page_footer();
