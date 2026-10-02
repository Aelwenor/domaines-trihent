# Application de prise de rendez-vous pour coach sportif

Un agenda en ligne façon Calendly, pensé pour un coach qui se déplace (Plouha → Pordic…).
Il fonctionne en PHP avec une base SQLite (un simple fichier) : rien à installer chez l'hébergeur.

## Ce que fait l'application

**Côté coach** (`/coaching/admin/`)
- Profil : nom de l'entreprise, logo, couleur, présentation, email, numéro WhatsApp.
- Cours : liste libre (perte de poids, prise de masse, mobilité, musculation…) avec durée et tarif.
  Ils s'affichent sur la page d'accueil et lors de la réservation.
- Lieux et trajets : « au studio » (pas de trajet) ou « je me déplace » avec le temps de trajet aller.
  Exemple : à Pordic, 30 min de trajet + 1 h de séance + 30 min de retour = **2 h bloquées** dans l'agenda.
- Disponibilités : horaires habituels de la semaine, et blocage de journées ou d'heures (congés…).
- Validation **manuelle ou automatique** des demandes (au choix dans Réglages). En mode manuel, le coach reçoit
  un email à chaque demande et valide / refuse / propose un autre horaire en un clic.
- Tableau de bord, agenda de la semaine, fiches clients avec notes privées.
- Ajout de rendez-vous à la main (pour les clients qui passent encore par WhatsApp).
- Messages avec le client, liens WhatsApp en un clic.
- Lien d'abonnement pour voir les rendez-vous (trajet compris) dans l'agenda du téléphone.

**Côté client**
- Choix du cours, du lieu (chez le coach ou à domicile), puis du jour et de l'heure parmi les créneaux libres.
  Seuls les créneaux compatibles avec le trajet sont proposés.
- Profil : nom, prénom, âge, objectif, téléphone, email, adresse si le coach se déplace.
- Lien personnel reçu par email pour suivre, **déplacer ou annuler jusqu'à 48 h avant** (délai réglable).
  En deçà, le client est invité à contacter le coach directement.
- Ajout du rendez-vous à son agenda, messages avec le coach, rappel par email la veille.

## Installation chez Hostinger

1. Téléverser le dossier `coaching/` dans `public_html` (Gestionnaire de fichiers).
   Les dossiers `coaching/data` et `coaching/uploads` doivent être accessibles en écriture (c'est le cas par défaut).
2. Ouvrir `https://votre-site/coaching/admin/` : au premier passage, on crée le mot de passe du coach.
3. Compléter **Profil**, **Cours**, **Lieux & trajets**, **Disponibilités** et **Réglages**.
4. (Facultatif) Rappels automatiques : dans hPanel → Avancé → Tâches Cron, ajouter toutes les heures
   la commande indiquée dans la page Réglages.
5. Partager le lien `https://votre-site/coaching/` aux clients (bouton « Envoyer par WhatsApp » sur le tableau de bord).

Prérequis : PHP 8.1 ou plus récent avec l'extension SQLite (standard chez Hostinger).

## Bon à savoir

- Les données (clients, rendez-vous) sont dans `coaching/data/coaching.sqlite`, protégé par un `.htaccess`.
  Pour une sauvegarde, il suffit de télécharger ce fichier.
- Les emails partent via la fonction `mail()` de l'hébergeur ; un journal est tenu dans `coaching/data/mail.log`.
- Une demande en attente bloque le créneau, pour éviter deux demandes sur le même horaire.
  Un refus ou une annulation libère le créneau.
- Le coach peut déplacer un rendez-vous à tout moment (et forcer un horaire déjà occupé si besoin).
- Lorsque la validation est manuelle, un rendez-vous déplacé par le client repasse « en attente de validation ».
