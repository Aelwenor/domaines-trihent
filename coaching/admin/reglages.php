<?php
/* Regles de reservation, validation auto/manuelle, synchronisation agenda, mot de passe. */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'password') {
        if (!password_verify(post('current'), setting('admin_password'))) {
            flash('Mot de passe actuel incorrect.', 'error');
        } elseif (strlen(post('new')) < 8) {
            flash('Le nouveau mot de passe doit faire au moins 8 caractères.', 'error');
        } else {
            set_setting('admin_password', password_hash(post('new'), PASSWORD_DEFAULT));
            flash('Mot de passe modifié.');
        }
    } elseif (post('action') === 'regen_ics') {
        set_setting('ics_key', random_token());
        flash("Nouveau lien d'agenda créé : l'ancien ne fonctionne plus.");
    } else {
        set_setting('auto_confirm', post('auto_confirm') === '1' ? '1' : '0');
        $ints = ['cancel_hours' => [0, 720], 'min_notice_hours' => [0, 720], 'horizon_days' => [1, 365],
                 'slot_step' => [5, 120], 'buffer_minutes' => [0, 120]];
        foreach ($ints as $k => [$min, $max]) {
            set_setting($k, (string) min($max, max($min, (int) post($k))));
        }
        flash('Réglages enregistrés.');
    }
    redirect('admin/reglages.php');
}

$ics = absolute_url('calendar.php?k=' . setting('ics_key'));

page_header('Réglages', true);
?>
<h1>Réglages</h1>
<form method="post" class="card">
  <?= csrf_field() ?>
  <h2 style="margin-top:0">Validation des demandes</h2>
  <label class="check"><input type="radio" name="auto_confirm" value="0" <?= setting('auto_confirm') !== '1' ? 'checked' : '' ?>>
    <span><strong>Manuelle</strong> — je reçois un email et je valide (ou refuse) chaque demande. Le créneau est réservé en attendant.</span></label>
  <label class="check"><input type="radio" name="auto_confirm" value="1" <?= setting('auto_confirm') === '1' ? 'checked' : '' ?>>
    <span><strong>Automatique</strong> — le rendez-vous est confirmé tout de suite ; je reçois quand même un email.</span></label>

  <h2>Règles de réservation</h2>
  <div class="grid3">
    <div><label>Prévenir au moins (h) pour annuler/déplacer</label><input type="number" name="cancel_hours" value="<?= setting_int('cancel_hours') ?>">
      <p class="help">En deçà, le client doit vous contacter directement.</p></div>
    <div><label>Délai minimum avant une séance (h)</label><input type="number" name="min_notice_hours" value="<?= setting_int('min_notice_hours') ?>">
      <p class="help">Évite les réservations de dernière minute.</p></div>
    <div><label>Réservation possible jusqu'à (jours)</label><input type="number" name="horizon_days" value="<?= setting_int('horizon_days') ?>"></div>
    <div><label>Créneaux proposés toutes les (min)</label><input type="number" name="slot_step" step="5" value="<?= setting_int('slot_step') ?>"></div>
    <div><label>Pause après chaque séance (min)</label><input type="number" name="buffer_minutes" step="5" value="<?= setting_int('buffer_minutes') ?>">
      <p class="help">Temps de souffle / rangement, en plus du trajet.</p></div>
  </div>
  <p></p>
  <button class="btn" type="submit">Enregistrer</button>
</form>

<div class="card">
  <h2 style="margin-top:0">Voir mes rendez-vous dans l'agenda de mon téléphone</h2>
  <p>Abonnez votre agenda (iPhone, Google Agenda, Outlook) à ce lien secret : les rendez-vous y apparaissent automatiquement, <strong>trajet compris</strong>.</p>
  <input readonly value="<?= e($ics) ?>" onclick="this.select()">
  <p class="help">iPhone : Réglages → Calendrier → Comptes → Ajouter un compte → Autre → Ajouter un calendrier avec abonnement.<br>
    Google Agenda (sur ordinateur) : « Autres agendas » → + → « À partir de l'URL ».</p>
  <form method="post" onsubmit="return confirm('Créer un nouveau lien ? L\'ancien ne marchera plus.')">
    <?= csrf_field() ?><input type="hidden" name="action" value="regen_ics">
    <button class="btn btn-light btn-small" type="submit">Générer un nouveau lien</button>
  </form>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="password">
  <h2 style="margin-top:0">Mot de passe</h2>
  <div class="grid2">
    <div><label>Actuel</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div><label>Nouveau (8 caractères min.)</label><input type="password" name="new" required autocomplete="new-password"></div>
  </div>
  <p></p><button class="btn" type="submit">Changer le mot de passe</button>
</form>
<?php page_footer();
