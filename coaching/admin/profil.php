<?php
/* Profil public du coach : nom de l'entreprise, logo, couleur, presentation, contact. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (['business_name', 'coach_name', 'tagline', 'about', 'coach_phone', 'base_city'] as $k) {
        set_setting($k, post($k));
    }
    if (filter_var(post('coach_email'), FILTER_VALIDATE_EMAIL)) {
        set_setting('coach_email', post('coach_email'));
    } else {
        flash("L'email n'est pas valide, il n'a pas été modifié.", 'error');
    }
    if (preg_match('/^#[0-9a-fA-F]{6}$/', post('brand_color'))) {
        set_setting('brand_color', post('brand_color'));
    }

    // Logo : PNG, JPG ou WebP, 3 Mo max
    if (!empty($_FILES['logo']['name'])) {
        $f = $_FILES['logo'];
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
        if (!$info || !isset($types[$info[2]]) || $f['size'] > 3 * 1024 * 1024) {
            flash('Logo refusé : utilisez une image PNG, JPG ou WebP de moins de 3 Mo.', 'error');
        } else {
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0775, true);
            }
            $old = setting('logo');
            $name = 'logo.' . $types[$info[2]];
            if ($old !== '' && $old !== $name && is_file(UPLOAD_DIR . '/' . $old)) {
                unlink(UPLOAD_DIR . '/' . $old);
            }
            move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name);
            set_setting('logo', $name);
        }
    }
    if (post('remove_logo') === '1' && setting('logo') !== '') {
        @unlink(UPLOAD_DIR . '/' . setting('logo'));
        set_setting('logo', '');
    }
    flash('Profil enregistré.');
    redirect('admin/profil.php');
}

page_header('Profil', true);
?>
<h1>Mon profil</h1>
<p class="muted">Ces informations s'affichent sur votre page d'accueil et dans les emails envoyés aux clients.</p>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <div class="grid2">
    <div><label>Nom de l'entreprise</label><input name="business_name" required value="<?= e(setting('business_name')) ?>"></div>
    <div><label>Votre nom (coach)</label><input name="coach_name" value="<?= e(setting('coach_name')) ?>"></div>
  </div>
  <label>Logo</label>
  <div class="actions">
    <?= logo_html('logo-big logo') ?>
    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" style="flex:1">
  </div>
  <?php if (setting('logo') !== ''): ?><label class="check"><input type="checkbox" name="remove_logo" value="1"> Retirer le logo</label><?php endif; ?>
  <label>Couleur principale</label>
  <input type="color" name="brand_color" value="<?= e(setting('brand_color')) ?>">
  <label>Phrase d'accroche</label><input name="tagline" value="<?= e(setting('tagline')) ?>">
  <label>Présentation (parcours, diplômes, approche…)</label><textarea name="about" rows="6"><?= e(setting('about')) ?></textarea>
  <div class="grid3">
    <div><label>Email (reçoit les demandes)</label><input type="email" name="coach_email" required value="<?= e(setting('coach_email')) ?>"></div>
    <div><label>Téléphone WhatsApp</label><input type="tel" name="coach_phone" value="<?= e(setting('coach_phone')) ?>" placeholder="06 12 34 56 78"></div>
    <div><label>Commune de base</label><input name="base_city" value="<?= e(setting('base_city')) ?>"></div>
  </div>
  <p></p>
  <button class="btn" type="submit">Enregistrer</button>
</form>
<style>.logo-big { width: 90px; height: 90px; font-size: 2rem; }</style>
<?php page_footer();
