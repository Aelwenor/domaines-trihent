<?php
/*
 * Lieux de seance et temps de trajet.
 * "Chez le coach" : pas de trajet. "Le coach se deplace" : trajet aller + retour ajoute au temps bloque.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post('id');
    if (post('action') === 'delete') {
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$id]);
        flash('Lieu supprimé (les rendez-vous existants sont conservés).');
    } else {
        $kind = post('kind') === 'base' ? 'base' : 'travel';
        $data = [post('name'), $kind, $kind === 'base' ? 0 : max(0, (int) post('travel')), post('active') === '1' ? 1 : 0, (int) post('sort')];
        if ($data[0] === '') {
            flash('Le nom du lieu est obligatoire.', 'error');
            redirect('admin/lieux.php' . ($id ? "?id=$id" : '?new=1'));
        }
        if ($id) {
            $pdo->prepare('UPDATE locations SET name = ?, kind = ?, travel = ?, active = ?, sort = ? WHERE id = ?')->execute([...$data, $id]);
        } else {
            $pdo->prepare('INSERT INTO locations(name, kind, travel, active, sort) VALUES (?, ?, ?, ?, ?)')->execute($data);
        }
        flash('Lieu enregistré.');
    }
    redirect('admin/lieux.php');
}

$edit = get('id') ? find_row('locations', (int) get('id')) : (get('new') ? ['id' => 0, 'name' => 'À domicile — ', 'kind' => 'travel', 'travel' => 20, 'active' => 1, 'sort' => 0] : null);
$locations = $pdo->query('SELECT * FROM locations ORDER BY sort, name')->fetchAll();

page_header('Lieux & trajets', true);
?>
<h1>Lieux &amp; temps de trajet</h1>
<?php if ($edit): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <h2 style="margin-top:0"><?= $edit['id'] ? 'Modifier le lieu' : 'Nouveau lieu' ?></h2>
    <label>Nom affiché au client</label><input name="name" required value="<?= e($edit['name']) ?>">
    <label>Type</label>
    <label class="check"><input type="radio" name="kind" value="base" <?= $edit['kind'] === 'base' ? 'checked' : '' ?>> Le client vient chez moi (studio, salle…) — pas de trajet</label>
    <label class="check"><input type="radio" name="kind" value="travel" <?= $edit['kind'] === 'travel' ? 'checked' : '' ?>> Je me déplace (domicile du client, plage, parc…)</label>
    <div class="grid2">
      <div><label>Temps de trajet aller (min)</label><input type="number" name="travel" min="0" step="5" value="<?= (int) $edit['travel'] ?>">
        <p class="help">Compté à l'aller ET au retour. Ex. 30 min + séance 1 h + 30 min = 2 h bloquées.</p></div>
      <div><label>Ordre d'affichage</label><input type="number" name="sort" value="<?= (int) $edit['sort'] ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Proposé à la réservation</label>
    <div class="actions">
      <button class="btn" type="submit">Enregistrer</button>
      <a class="btn btn-light" href="<?= e(url('admin/lieux.php')) ?>">Annuler</a>
    </div>
  </form>
  <?php if ($edit['id']): ?>
    <form method="post" onsubmit="return confirm('Supprimer ce lieu ?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><input type="hidden" name="action" value="delete">
      <button class="btn btn-light btn-small" type="submit">Supprimer ce lieu</button>
    </form>
  <?php endif; ?>
<?php else: ?>
  <p class="muted">Le client choisit où se passe la séance. Quand vous vous déplacez, le trajet aller-retour est bloqué automatiquement dans votre agenda : impossible d'enchaîner deux rendez-vous sans le temps de route.</p>
  <div class="actions"><a class="btn" href="?new=1">+ Ajouter une commune / un lieu</a></div>
  <div class="card">
    <?php foreach ($locations as $l): ?>
      <div class="list-item">
        <div><strong><?= e($l['name']) ?></strong> <?= $l['active'] ? '' : '<span class="badge badge-cancelled">masqué</span>' ?><br>
          <span class="muted small"><?= $l['kind'] === 'base' ? 'Le client vient — pas de trajet' : '🚗 ' . (int) $l['travel'] . ' min aller + ' . (int) $l['travel'] . ' min retour' ?></span></div>
        <a class="btn btn-light btn-small" href="?id=<?= (int) $l['id'] ?>">Modifier</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
