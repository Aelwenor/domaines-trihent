<?php
/* "Retrouver mes rendez-vous" : le client recoit par email les liens vers ses rendez-vous a venir. */
require __DIR__ . '/inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && post('website') === '') {
        $st = db()->prepare("SELECT b.*, c.first_name FROM bookings b JOIN clients c ON c.id = b.client_id
                             WHERE c.email = ? AND b.status IN ('pending', 'confirmed') AND b.end_at >= ? ORDER BY b.start_at");
        $st->execute([$email, date('Y-m-d H:i')]);
        $rows = $st->fetchAll();
        if ($rows) {
            $body = "Bonjour {$rows[0]['first_name']},\n\nVoici vos rendez-vous à venir :\n\n";
            foreach ($rows as $b) {
                $body .= '- ' . ucfirst(fr_slot($b['start_at'], $b['end_at'])) . " — {$b['service_name']} (" . STATUTS[$b['status']] . ")\n  " . client_link($b) . "\n";
            }
            send_mail($email, 'Vos rendez-vous', $body, setting('coach_email'));
        }
    }
    flash("Si des rendez-vous à venir sont associés à cette adresse, vous allez recevoir un email avec les liens pour les consulter.", 'info');
    redirect('mes-rdv.php');
}

page_header('Mes rendez-vous');
?>
<h1>Retrouver mes rendez-vous</h1>
<form method="post" class="card" style="max-width:520px">
  <?= csrf_field() ?>
  <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
  <p>Indiquez l'email utilisé lors de la réservation : vous recevrez les liens pour consulter, déplacer ou annuler vos rendez-vous.</p>
  <label for="email">Email</label>
  <input id="email" type="email" name="email" required autocomplete="email">
  <p></p>
  <button class="btn" type="submit">Recevoir mes liens</button>
</form>
<?php page_footer();
