<?php
/* Page d'accueil publique du coach : presentation + liste des cours. */
require __DIR__ . '/inc/bootstrap.php';

$services  = active_services();
$locations = active_locations();
$phone     = setting('coach_phone');

page_header('Accueil');
?>
<section class="hero">
  <?= logo_html('logo-big logo') ?>
  <div>
    <h1><?= e(brand_name()) ?></h1>
    <?php if (setting('coach_name') !== ''): ?><p class="muted">Votre coach : <strong><?= e(setting('coach_name')) ?></strong></p><?php endif; ?>
    <p><?= e(setting('tagline')) ?></p>
    <div class="actions">
      <a class="btn" href="<?= e(url('reserver.php')) ?>">Réserver une séance</a>
      <?php if ($phone !== ''): ?>
        <a class="btn btn-wa" href="<?= e(whatsapp_link($phone, 'Bonjour, ')) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (setting('about') !== ''): ?>
  <h2>À propos</h2>
  <div class="card"><?= nl2br(e(setting('about'))) ?></div>
<?php endif; ?>

<h2 id="cours">Les cours proposés</h2>
<div class="cards">
  <?php foreach ($services as $s): ?>
    <a class="choice" href="<?= e(url('reserver.php?s=' . $s['id'])) ?>">
      <h3><?= e($s['name']) ?></h3>
      <p><?= nl2br(e($s['description'])) ?></p>
      <p class="meta"><?= e(fr_duration((int) $s['duration'])) ?><?= $s['price'] !== '' ? ' · ' . e($s['price']) : '' ?></p>
      <span class="btn btn-small">Réserver</span>
    </a>
  <?php endforeach; ?>
  <?php if (!$services): ?><p class="muted">Les cours seront bientôt en ligne.</p><?php endif; ?>
</div>

<?php if ($locations): ?>
  <h2>Où ont lieu les séances ?</h2>
  <div class="card">
    <ul>
      <?php foreach ($locations as $l): ?>
        <li><?= e($l['name']) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">Quand le coach se déplace, le temps de trajet est automatiquement pris en compte dans les créneaux proposés.</p>
  </div>
<?php endif; ?>

<h2>Bon à savoir</h2>
<div class="card">
  <ul>
    <li>Choisissez votre cours, votre lieu puis un créneau libre, comme sur un agenda en ligne.</li>
    <li><?= setting('auto_confirm') === '1' ? 'Votre rendez-vous est confirmé immédiatement.' : 'Votre demande est validée par le coach : vous recevez un email de confirmation.' ?></li>
    <li><?= e(cancel_policy_text()) ?> Vous pouvez le faire vous-même depuis le lien reçu par email.</li>
  </ul>
</div>
<?php page_footer();
