<?php
/*
 * Envoi des rappels programmes (voir la page Rappels de l'espace coach).
 * A lancer toutes les 15 minutes via une tache planifiee (Cron) :
 *     php /chemin/vers/coaching/cron.php
 * ou par adresse web : https://votre-site/coaching/cron.php?k=CLE (cle visible dans la page Rappels).
 * Sans tache planifiee, les rappels partent quand meme lors des visites sur l'application.
 */
require __DIR__ . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli' && !hash_equals(setting('cron_key'), get('k'))) {
    http_response_code(403);
    exit('Accès refusé');
}
echo 'Rappels envoyés : ' . run_reminders() . "\n";
