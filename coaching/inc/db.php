<?php
/*
 * Base de donnees SQLite : un simple fichier (data/coaching.sqlite), cree automatiquement
 * au premier lancement. Rien a configurer chez l'hebergeur.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    $file = getenv('COACHING_DB') ?: DATA_DIR . '/coaching.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS services (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            duration    INTEGER NOT NULL DEFAULT 60,
            price       TEXT NOT NULL DEFAULT '',
            active      INTEGER NOT NULL DEFAULT 1,
            sort        INTEGER NOT NULL DEFAULT 0
        );
        -- kind = 'base' : le client vient chez le coach / 'travel' : le coach se deplace
        CREATE TABLE IF NOT EXISTS locations (
            id      INTEGER PRIMARY KEY,
            name    TEXT NOT NULL,
            kind    TEXT NOT NULL DEFAULT 'travel',
            travel  INTEGER NOT NULL DEFAULT 0,
            active  INTEGER NOT NULL DEFAULT 1,
            sort    INTEGER NOT NULL DEFAULT 0
        );
        -- weekday : 1 = lundi ... 7 = dimanche ; heures au format HH:MM
        CREATE TABLE IF NOT EXISTS availability (
            id         INTEGER PRIMARY KEY,
            weekday    INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time   TEXT NOT NULL
        );
        -- Indisponibilites ponctuelles (conges, journee bloquee, quelques heures...)
        CREATE TABLE IF NOT EXISTS blocks (
            id       INTEGER PRIMARY KEY,
            start_at TEXT NOT NULL,
            end_at   TEXT NOT NULL,
            reason   TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS clients (
            id          INTEGER PRIMARY KEY,
            first_name  TEXT NOT NULL,
            last_name   TEXT NOT NULL,
            age         INTEGER,
            email       TEXT NOT NULL UNIQUE COLLATE NOCASE,
            phone       TEXT NOT NULL DEFAULT '',
            objective   TEXT NOT NULL DEFAULT '',
            coach_notes TEXT NOT NULL DEFAULT '',
            created_at  TEXT NOT NULL
        );
        -- start_at/end_at : la seance ; occ_start/occ_end : temps reellement bloque
        -- pour le coach (trajet aller + seance + trajet retour + pause eventuelle)
        CREATE TABLE IF NOT EXISTS bookings (
            id            INTEGER PRIMARY KEY,
            token         TEXT NOT NULL UNIQUE,
            client_id     INTEGER NOT NULL REFERENCES clients(id),
            service_id    INTEGER REFERENCES services(id) ON DELETE SET NULL,
            location_id   INTEGER REFERENCES locations(id) ON DELETE SET NULL,
            service_name  TEXT NOT NULL,
            location_name TEXT NOT NULL,
            location_kind TEXT NOT NULL DEFAULT 'base',
            travel        INTEGER NOT NULL DEFAULT 0,
            start_at      TEXT NOT NULL,
            end_at        TEXT NOT NULL,
            occ_start     TEXT NOT NULL,
            occ_end       TEXT NOT NULL,
            address       TEXT NOT NULL DEFAULT '',
            client_note   TEXT NOT NULL DEFAULT '',
            status        TEXT NOT NULL DEFAULT 'pending',
            cancelled_by  TEXT NOT NULL DEFAULT '',
            reminder_sent INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL,
            updated_at    TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_bookings_occ ON bookings(occ_start, occ_end);
        CREATE TABLE IF NOT EXISTS messages (
            id         INTEGER PRIMARY KEY,
            booking_id INTEGER NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
            sender     TEXT NOT NULL,
            body       TEXT NOT NULL,
            is_read    INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
    ");

    if ((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0) {
        db_seed($pdo);
    }
}

/* Valeurs de depart (toutes modifiables ensuite dans l'espace coach). */
function db_seed(PDO $pdo): void
{
    $defaults = [
        'business_name'     => 'Mon coaching sportif',
        'coach_name'        => '',
        'tagline'           => 'Coaching sportif personnalisé, au studio ou à domicile',
        'about'             => '',
        'logo'              => '',
        'brand_color'       => '#0f766e',
        'coach_email'       => '',
        'coach_phone'       => '',
        'base_city'         => 'Plouha',
        'auto_confirm'      => '0',
        'cancel_hours'      => '48',
        'min_notice_hours'  => '24',
        'horizon_days'      => '60',
        'slot_step'         => '30',
        'buffer_minutes'    => '0',
        'reminder_hours'    => '24',
        'admin_password'    => '',
        'ics_key'           => bin2hex(random_bytes(16)),
        'cron_key'          => bin2hex(random_bytes(16)),
        'site_url'          => '',
    ];
    $st = $pdo->prepare('INSERT INTO settings(key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $st->execute([$k, $v]);
    }

    $st = $pdo->prepare('INSERT INTO services(name, description, duration, price, sort) VALUES (?, ?, ?, ?, ?)');
    $st->execute(['Perte de poids', 'Un programme cardio et renforcement adapté pour perdre du poids durablement.', 60, '', 1]);
    $st->execute(['Prise de masse', 'Gagner du poids et du muscle avec un entraînement et des conseils adaptés.', 60, '', 2]);
    $st->execute(['Musculation', 'Renforcement musculaire, technique et progression encadrée.', 60, '', 3]);
    $st->execute(['Mobilité', 'Souplesse, amplitude articulaire et prévention des douleurs.', 60, '', 4]);

    $st = $pdo->prepare('INSERT INTO locations(name, kind, travel, sort) VALUES (?, ?, ?, ?)');
    $st->execute(['Au studio à Plouha (je me déplace)', 'base', 0, 1]);
    $st->execute(['À domicile — Plouha', 'travel', 10, 2]);
    $st->execute(['À domicile — Pordic', 'travel', 30, 3]);

    $st = $pdo->prepare('INSERT INTO availability(weekday, start_time, end_time) VALUES (?, ?, ?)');
    for ($d = 1; $d <= 5; $d++) {
        $st->execute([$d, '08:00', '12:00']);
        $st->execute([$d, '14:00', '20:00']);
    }
    $st->execute([6, '09:00', '12:00']);
}
