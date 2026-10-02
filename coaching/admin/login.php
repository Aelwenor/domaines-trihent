<?php
/* Connexion a l'espace coach. Au tout premier passage : creation du mot de passe. */
require dirname(__DIR__) . '/inc/bootstrap.php';

$firstRun = setting('admin_password') === '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($firstRun) {
        if (strlen(post('password')) < 8) {
            $error = 'Le mot de passe doit faire au moins 8 caractères.';
        } elseif (post('password') !== post('password2')) {
            $error = 'Les deux mots de passe ne correspondent pas.';
        } elseif (!filter_var(post('email'), FILTER_VALIDATE_EMAIL)) {
            $error = "L'email n'est pas valide.";
        } else {
            set_setting('admin_password', password_hash(post('password'), PASSWORD_DEFAULT));
            set_setting('coach_email', post('email'));
            if (post('business_name') !== '') {
                set_setting('business_name', post('business_name'));
            }
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            flash('Bienvenue ! Commencez par compléter votre profil (logo, téléphone…).');
            redirect('admin/profil.php');
        }
    } elseif (password_verify(post('password'), setting('admin_password'))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect('admin/');
    } else {
        sleep(1); // freine les tentatives en serie
        $error = 'Mot de passe incorrect.';
    }
}

page_header('Espace coach', true);
?>
<div class="card" style="max-width:440px;margin:30px auto">
  <h1><?= $firstRun ? 'Création de votre espace coach' : 'Espace coach' ?></h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <?php if ($firstRun): ?>
      <label for="business_name">Nom de votre entreprise</label>
      <input id="business_name" name="business_name" value="<?= e(post('business_name')) ?>">
      <label for="email">Votre email (pour recevoir les demandes)</label>
      <input id="email" name="email" type="email" required value="<?= e(post('email')) ?>">
    <?php endif; ?>
    <label for="password">Mot de passe</label>
    <input id="password" name="password" type="password" required autocomplete="<?= $firstRun ? 'new-password' : 'current-password' ?>">
    <?php if ($firstRun): ?>
      <label for="password2">Confirmez le mot de passe</label>
      <input id="password2" name="password2" type="password" required autocomplete="new-password">
    <?php endif; ?>
    <p></p>
    <button class="btn btn-block" type="submit"><?= $firstRun ? 'Créer mon espace' : 'Se connecter' ?></button>
  </form>
</div>
<?php page_footer();
