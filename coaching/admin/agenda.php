<?php
/* Vue semaine de l'agenda : rendez-vous (trajet compris) et indisponibilites. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('w')) ? get('w') : date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
$nextMonday = date('Y-m-d', strtotime("$monday +7 days"));

$st = db()->prepare("SELECT b.*, c.first_name, c.last_name FROM bookings b JOIN clients c ON c.id = b.client_id
                     WHERE b.status IN ('pending', 'confirmed') AND b.start_at >= ? AND b.start_at < ? ORDER BY b.start_at");
$st->execute([$monday, $nextMonday]);
$byDay = [];
foreach ($st as $b) {
    $byDay[substr($b['start_at'], 0, 10)][] = $b;
}
$st = db()->prepare('SELECT * FROM blocks WHERE start_at < ? AND end_at > ? ORDER BY start_at');
$st->execute([$nextMonday, $monday]);
$blocks = $st->fetchAll();

page_header('Agenda', true);
?>
<h1>Agenda</h1>
<div class="actions">
  <a class="btn btn-light btn-small" href="?w=<?= e(date('Y-m-d', strtotime("$monday -7 days"))) ?>">‹ Semaine précédente</a>
  <a class="btn btn-light btn-small" href="?">Cette semaine</a>
  <a class="btn btn-light btn-small" href="?w=<?= e($nextMonday) ?>">Semaine suivante ›</a>
  <a class="btn btn-small" href="<?= e(url('admin/nouveau.php')) ?>">+ Ajouter un RDV</a>
  <a class="btn btn-light btn-small" href="<?= e(url('admin/disponibilites.php')) ?>">Bloquer un créneau</a>
</div>
<p class="muted">Semaine du <?= e(fr_date($monday)) ?></p>
<div class="week">
  <?php for ($i = 0; $i < 7; $i++): $day = date('Y-m-d', strtotime("$monday +$i days")); ?>
    <div class="week-day <?= $day === date('Y-m-d') ? 'today' : '' ?>">
      <h3><?= e(ucfirst(JOURS[$i + 1]) . ' ' . (int) substr($day, 8, 2)) ?></h3>
      <?php foreach ($blocks as $bl): if ($bl['start_at'] < "$day 23:59" && $bl['end_at'] > "$day 00:00"): ?>
        <span class="ev block">⛔ <?= e(substr($bl['start_at'], 0, 10) < $day ? '0h00' : fr_time($bl['start_at'])) ?>–<?= e(substr($bl['end_at'], 0, 10) > $day ? '24h' : fr_time($bl['end_at'])) ?> <?= e($bl['reason']) ?></span>
      <?php endif; endforeach; ?>
      <?php foreach ($byDay[$day] ?? [] as $b): ?>
        <a class="ev <?= e($b['status']) ?>" href="<?= e(url('admin/rdv.php?id=' . $b['id'])) ?>">
          <strong><?= e(fr_time($b['start_at'])) ?>–<?= e(fr_time($b['end_at'])) ?></strong> <?= e($b['first_name'] . ' ' . mb_substr($b['last_name'], 0, 1) . '.') ?><br>
          <?= e($b['service_name']) ?>
          <?php if ($b['travel'] > 0): ?><br><span class="travel">🚗 <?= e(fr_time($b['occ_start'])) ?>–<?= e(fr_time($b['occ_end'])) ?></span><?php endif; ?>
          <?php if ($b['status'] === 'pending'): ?><br><b>À valider</b><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endfor; ?>
</div>
<?php page_footer();
