<?php
/*
 * Agenda du coach, en vue semaine ou mois (le choix est memorise).
 * Rendez-vous (trajet compris) et indisponibilites, avec un bilan de la periode.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

// Vue choisie : memorisee comme preference du coach
$view = get('v');
if (in_array($view, ['week', 'month'], true)) {
    if ($view !== setting('agenda_view')) {
        set_setting('agenda_view', $view);
    }
} else {
    $view = setting('agenda_view', 'week') === 'month' ? 'month' : 'week';
}

$ref = preg_match('/^\d{4}-\d{2}-\d{2}$/', get('d')) ? get('d') : date('Y-m-d');
if ($view === 'month') {
    $first = substr($ref, 0, 7) . '-01';
    $nextFirst = date('Y-m-d', strtotime("$first +1 month"));
    $from = date('Y-m-d', strtotime('monday this week', strtotime($first)));            // grille du lundi...
    $to = date('Y-m-d', strtotime('monday next week', strtotime("$nextFirst -1 day"))); // ...au dimanche
    [$sumFrom, $sumTo] = [$first, $nextFirst];
    $prev = date('Y-m-d', strtotime("$first -1 month"));
    $next = $nextFirst;
    $title = ucfirst(MOIS[(int) substr($first, 5, 2)]) . ' ' . substr($first, 0, 4);
} else {
    $from = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
    $to = date('Y-m-d', strtotime("$from +7 days"));
    [$sumFrom, $sumTo] = [$from, $to];
    $prev = date('Y-m-d', strtotime("$from -7 days"));
    $next = $to;
    $title = 'Semaine du ' . fr_date($from);
}

$st = db()->prepare("SELECT b.*, c.first_name, c.last_name FROM bookings b JOIN clients c ON c.id = b.client_id
                     WHERE b.status IN ('pending', 'confirmed') AND b.start_at >= ? AND b.start_at < ? ORDER BY b.start_at");
$st->execute([$from, $to]);
$byDay = [];
$sum = ['confirmed' => 0, 'pending' => 0, 'coaching' => 0, 'travel' => 0];
foreach ($st as $b) {
    $day = substr($b['start_at'], 0, 10);
    $byDay[$day][] = $b;
    if ($day >= $sumFrom && $day < $sumTo) {
        $sum[$b['status']]++;
        if ($b['status'] === 'confirmed') {
            $sum['coaching'] += (strtotime($b['end_at']) - strtotime($b['start_at'])) / 60;
            $sum['travel'] += 2 * (int) $b['travel'];
        }
    }
}
$st = db()->prepare('SELECT * FROM blocks WHERE start_at < ? AND end_at > ? ORDER BY start_at');
$st->execute([$to, $from]);
$blocks = $st->fetchAll();

/* Indisponibilites d'un jour : 'full' si toute la journee est bloquee, sinon la liste des plages */
function day_blocks(array $blocks, string $day): array
{
    $out = [];
    foreach ($blocks as $bl) {
        if ($bl['start_at'] < "$day 24:00" && $bl['end_at'] > "$day 00:00") {
            $full = $bl['start_at'] <= "$day 00:00" && $bl['end_at'] >= date('Y-m-d', strtotime("$day +1 day")) . ' 00:00';
            $out[] = $full ? 'full' : $bl;
        }
    }
    return $out;
}

function nav_url(string $view, string $date): string
{
    return '?v=' . $view . '&d=' . $date;
}

$today = date('Y-m-d');
page_header('Agenda', true);
?>
<h1>Agenda</h1>
<div class="actions">
  <span class="seg">
    <a class="<?= $view === 'week' ? 'on' : '' ?>" href="<?= e(nav_url('week', $view === 'month' && substr($today, 0, 7) !== substr($first, 0, 7) ? $first : $ref)) ?>">Semaine</a>
    <a class="<?= $view === 'month' ? 'on' : '' ?>" href="<?= e(nav_url('month', $ref)) ?>">Mois</a>
  </span>
  <a class="btn btn-light btn-small" href="<?= e(nav_url($view, $prev)) ?>" aria-label="Période précédente">‹</a>
  <a class="btn btn-light btn-small" href="<?= e(nav_url($view, $today)) ?>"><?= $view === 'month' ? 'Ce mois-ci' : 'Cette semaine' ?></a>
  <a class="btn btn-light btn-small" href="<?= e(nav_url($view, $next)) ?>" aria-label="Période suivante">›</a>
  <a class="btn btn-small" href="<?= e(url('admin/nouveau.php')) ?>">+ Ajouter un RDV</a>
  <a class="btn btn-light btn-small" href="<?= e(url('admin/disponibilites.php')) ?>">Bloquer un créneau</a>
</div>

<h2 style="margin-top:.4em"><?= e(ucfirst($title)) ?></h2>
<div class="stats">
  <div class="stat"><b><?= $sum['confirmed'] ?></b>séance(s) confirmée(s)</div>
  <div class="stat"><b><?= $sum['pending'] ?></b>demande(s) à valider</div>
  <div class="stat"><b><?= e(fr_duration((int) $sum['coaching'])) ?></b>de coaching</div>
  <div class="stat"><b><?= e(fr_duration((int) $sum['travel'])) ?></b>de route</div>
</div>

<?php if ($view === 'week'): ?>
  <div class="week-wrap"><div class="week">
    <?php for ($i = 0; $i < 7; $i++): $day = date('Y-m-d', strtotime("$from +$i days")); ?>
      <div class="week-day <?= $day === $today ? 'today' : '' ?>">
        <h3><?= e(ucfirst(JOURS[$i + 1]) . ' ' . (int) substr($day, 8, 2)) ?></h3>
        <?php foreach (day_blocks($blocks, $day) as $bl): ?>
          <span class="ev block">⛔ <?= $bl === 'full' ? 'Journée bloquée' : e(fr_time(max($bl['start_at'], "$day 00:00")) . '–' . (substr($bl['end_at'], 0, 10) > $day ? '24h' : fr_time($bl['end_at'])) . ' ' . $bl['reason']) ?></span>
        <?php endforeach; ?>
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
  </div></div>

<?php else: ?>
  <div class="month">
    <?php foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $d): ?><div class="month-dow"><?= $d ?></div><?php endforeach; ?>
    <?php for ($day = $from; $day < $to; $day = date('Y-m-d', strtotime("$day +1 day"))):
        $items = $byDay[$day] ?? [];
        $bls = day_blocks($blocks, $day);
        $full = in_array('full', $bls, true);
        $pending = count(array_filter($items, fn($b) => $b['status'] === 'pending'));
        $cls = 'month-day' . ($day === $today ? ' today' : '') . (substr($day, 0, 7) !== substr($first, 0, 7) ? ' other' : '') . ($full ? ' blocked' : '');
    ?>
      <a class="<?= $cls ?>" href="<?= e(nav_url('week', $day)) ?>" title="Voir la semaine">
        <span class="month-num"><?= (int) substr($day, 8, 2) ?></span>
        <?php if ($full): ?><span class="mev block">⛔ Bloqué</span><?php elseif ($bls): ?><span class="mev block">⛔ <?= e(fr_time($bls[0]['start_at'])) ?></span><?php endif; ?>
        <?php foreach (array_slice($items, 0, 3) as $b): ?>
          <span class="mev <?= e($b['status']) ?>"><?= e(fr_time($b['start_at'])) ?> <?= e($b['first_name']) ?><?= $b['travel'] > 0 ? ' 🚗' : '' ?></span>
        <?php endforeach; ?>
        <?php if (count($items) > 3): ?><span class="mev more">+<?= count($items) - 3 ?> autre(s)</span><?php endif; ?>
        <?php if ($items): ?><span class="mcount<?= $pending ? ' has-pending' : '' ?>"><?= count($items) ?></span><?php endif; ?>
      </a>
    <?php endfor; ?>
  </div>
  <p class="help">Touchez un jour pour l'ouvrir en vue semaine. 🚗 = vous vous déplacez. En orange : demandes à valider.</p>
<?php endif; ?>
<?php page_footer();
