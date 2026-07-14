<?php
/*
 * Traitement du formulaire de contact - Les Domaines de Tri Hent
 * Envoie la demande vers domaines.trihent@gmail.com, puis redirige vers merci.html.
 * Fonctionne sur l'hebergement Hostinger (PHP), sans aucun service tiers.
 */

// On n'accepte que les envois du formulaire (methode POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

// Anti-spam : si le champ piege invisible est rempli, c'est un robot -> on ignore
if (!empty($_POST['_honey'])) {
    header('Location: merci.html');
    exit;
}

// Petite fonction pour recuperer un champ proprement
function champ($cle) {
    return isset($_POST[$cle]) ? trim($_POST[$cle]) : '';
}

$genre     = champ('genre');
$prenom    = champ('prenom');
$nom       = champ('nom');
$email     = champ('email');
$telephone = champ('telephone');
$typeBien  = champ('type-bien');
$message   = champ('message');

// Validation minimale des champs obligatoires
if ($prenom === '' || $nom === '' || $telephone === '' || $message === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: contact.html?erreur=champs');
    exit;
}

// Destinataire de la demande
$destinataire = 'domaines.trihent@gmail.com';

// Sujet (encode pour gerer les accents)
$sujet       = 'Nouvelle demande de contact - Les Domaines de Tri Hent';
$sujetEncode = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

// Corps du message
$corps  = "Nouvelle demande recue via le site.\n";
$corps .= "----------------------------------------\n";
$corps .= "Genre        : " . ($genre !== '' ? $genre : '-') . "\n";
$corps .= "Nom          : $prenom $nom\n";
$corps .= "Email        : $email\n";
$corps .= "Telephone    : $telephone\n";
$corps .= "Type de bien : " . ($typeBien !== '' ? $typeBien : '-') . "\n";
$corps .= "----------------------------------------\n\n";
$corps .= "Message :\n$message\n";

// En-tetes : expediteur sur le domaine du site (bonne delivrabilite),
// et Reply-To = le visiteur, pour que vous puissiez repondre directement.
$domaine = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$expediteur = "no-reply@$domaine";

$entetes  = "From: Les Domaines de Tri Hent <$expediteur>\r\n";
$entetes .= "Reply-To: $prenom $nom <$email>\r\n";
$entetes .= "MIME-Version: 1.0\r\n";
$entetes .= "Content-Type: text/plain; charset=UTF-8\r\n";

// Envoi
$envoye = @mail($destinataire, $sujetEncode, $corps, $entetes);

if ($envoye) {
    header('Location: merci.html');
} else {
    header('Location: contact.html?erreur=envoi');
}
exit;
