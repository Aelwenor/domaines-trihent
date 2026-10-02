<?php
/* Les cours proposes (affiches sur la page d'accueil et lors de la reservation). */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) post('id');
    if (post('action') === 'delete') {
        $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
        flash('Cours supprimé (les rendez-vous existants sont conservés).');
    } else {
        $data = [post('name'), post('description'), max(15, (int) post('duration')), post('price'), post('active') === '1' ? 1 : 0, (int) post('sort')];
        if ($data[0] === '') {
            flash('Le nom du cours est obligatoire.', 'error');
            redirect('admin/cours.php' . ($id ? "?id=$id" : '?new=1'));
        }
        if ($id) {
            $pdo->prepare('UPDATE services SET name = ?, description = ?, duration = ?, price = ?, active = ?, sort = ? WHERE id = ?')->execute([...$data, $id]);
        } else {
            $pdo->prepare('INSERT INTO services(name, description, duration, price, active, sort) VALUES (?, ?, ?, ?, ?, ?)')->execute($data);
        }
        flash('Cours enregistré.');
    }
    redirect('admin/cours.php');
}

$edit = get('id') ? find_row('services', (int) get('id')) : (get('new') ? ['id' => 0, 'name' => '', 'description' => '', 'duration' => 60, 'price' => '', 'active' => 1, 'sort' => 0] : null);
$services = $pdo->query('SELECT * FROM services ORDER BY sort, name')->fetchAll();

page_header('Cours', true);
?>
<h1>Mes cours</h1>
<?php if ($edit): ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
    <h2 style="margin-top:0"><?= $edit['id'] ? 'Modifier le cours' : 'Nouveau cours' ?></h2>
    <label>Nom</label><input name="name" required value="<?= e($edit['name']) ?>" placeholder="Ex. Perte de poids, Mobilité, Préparation physique…">
    <label>Description (visible par les clients)</label><textarea name="description"><?= e($edit['description']) ?></textarea>
    <div class="grid3">
      <div><label>Durée de la séance (min)</label><input type="number" name="duration" min="15" step="5" value="<?= (int) $edit['duration'] ?>"></div>
      <div><label>Tarif (facultatif)</label><input name="price" value="<?= e($edit['price']) ?>" placeholder="Ex. 45 €"></div>
      <div><label>Ordre d'affichage</label><input type="number" name="sort" value="<?= (int) $edit['sort'] ?>"></div>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Proposé à la réservation</label>
    <div class="actions">
      <button class="btn" type="submit">Enregistrer</button>
      <a class="btn btn-light" href="<?= e(url('admin/cours.php')) ?>">Annuler</a>
    </div>
  </form>
  <?php if ($edit['id']): ?>
    <form method="post" onsubmit="return confirm('Supprimer ce cours ?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><input type="hidden" name="action" value="delete">
      <button class="btn btn-light btn-small" type="submit">Supprimer ce cours</button>
    </form>
  <?php endif; ?>
<?php else: ?>
  <p class="muted">Ces cours apparaissent sur votre page d'accueil et au moment de la réservation. Le client choisit aussi son objectif parmi cette liste.</p>
  <div class="actions"><a class="btn" href="?new=1">+ Ajouter un cours</a></div>
  <div class="card">
    <?php foreach ($services as $s): ?>
      <div class="list-item">
        <div><strong><?= e($s['name']) ?></strong> <?= $s['active'] ? '' : '<span class="badge badge-cancelled">masqué</span>' ?><br>
          <span class="muted small"><?= e(fr_duration((int) $s['duration'])) ?><?= $s['price'] !== '' ? ' · ' . e($s['price']) : '' ?></span></div>
        <a class="btn btn-light btn-small" href="?id=<?= (int) $s['id'] ?>">Modifier</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
