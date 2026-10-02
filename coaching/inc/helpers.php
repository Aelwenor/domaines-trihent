<?php
/*
 * Petits outils communs : reglages, securite (CSRF, echappement), dates en francais,
 * liens WhatsApp, adresses du site.
 */

/* ---------- Reglages (table settings) ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null || $key === '__reset') {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = $row['value'];
        }
        if ($key === '__reset') {
            return '';
        }
    }
    return $cache[$key] ?? $default;
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, (string) $default);
    return is_numeric($v) ? (int) $v : $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
    setting('__reset');
}

/* ---------- Securite ---------- */

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expirée : rechargez la page et recommencez.');
    }
}

function random_token(): string
{
    return bin2hex(random_bytes(16));
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('admin/login.php');
    }
    remember_site_url();
}

function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
}

/* ---------- Adresses ---------- */

/* Chemin web du dossier de l'application (ex. "/coaching"), quel que soit le sous-dossier. */
function app_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? APP_ROOT));
    if ($scriptDir !== false && $scriptDir !== realpath(APP_ROOT)) {
        $dir = str_replace('\\', '/', dirname($dir)); // page situee dans admin/
    }
    return $path = rtrim($dir, '/');
}

function url(string $page = ''): string
{
    return app_path() . '/' . ltrim($page, '/');
}

/* Adresse complete (pour les emails). Memorisee pour les taches planifiees (cron). */
function absolute_url(string $page = ''): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . app_path();
    } else {
        $base = rtrim(setting('site_url'), '/');
    }
    return $base . '/' . ltrim($page, '/');
}

function remember_site_url(): void
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $base = rtrim(absolute_url(''), '/');
        if ($base !== setting('site_url')) {
            set_setting('site_url', $base);
        }
    }
}

function redirect(string $page): never
{
    header('Location: ' . url($page));
    exit;
}

/* ---------- Messages flash ---------- */

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="alert alert-' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- Dates en francais ---------- */

const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const MOIS  = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
               'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function dt(string $s): DateTimeImmutable
{
    return new DateTimeImmutable($s);
}

/* "mardi 14 octobre 2026" */
function fr_date(string $s): string
{
    $d = dt($s);
    return JOURS[(int) $d->format('N')] . ' ' . $d->format('j') . ' ' . MOIS[(int) $d->format('n')] . ' ' . $d->format('Y');
}

/* "9h30" */
function fr_time(string $s): string
{
    $d = dt($s);
    return $d->format('G') . 'h' . $d->format('i');
}

/* "mardi 14 octobre 2026 de 9h30 à 10h30" */
function fr_slot(string $start, string $end): string
{
    return fr_date($start) . ' de ' . fr_time($start) . ' à ' . fr_time($end);
}

function fr_duration(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return $m . ' min';
    }
    return $h . ' h' . ($m ? sprintf('%02d', $m) : '');
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

/* Heures restantes avant un rendez-vous (negatif si passe). */
function hours_until(string $start): float
{
    return (dt($start)->getTimestamp() - time()) / 3600;
}

/* ---------- Statuts ---------- */

const STATUTS = [
    'pending'   => 'En attente de validation',
    'confirmed' => 'Confirmé',
    'refused'   => 'Refusé',
    'cancelled' => 'Annulé',
];

function status_badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(STATUTS[$status] ?? $status) . '</span>';
}

/* ---------- WhatsApp & telephone ---------- */

/* 06 12 34 56 78 -> 33612345678 (format attendu par wa.me) */
function phone_intl(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($phone, '+')) {
        return $digits;
    }
    if (str_starts_with($digits, '00')) {
        return substr($digits, 2);
    }
    if (strlen($digits) === 10 && $digits[0] === '0') {
        return '33' . substr($digits, 1);
    }
    return $digits;
}

function whatsapp_link(string $phone, string $text = ''): string
{
    $num = phone_intl($phone);
    if ($num === '') {
        return '';
    }
    return 'https://wa.me/' . $num . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* ---------- Donnees courantes ---------- */

function find_booking_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.age, c.objective
                         FROM bookings b JOIN clients c ON c.id = b.client_id WHERE b.token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function find_booking(int $id): ?array
{
    $st = db()->prepare('SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.age, c.objective, c.coach_notes
                         FROM bookings b JOIN clients c ON c.id = b.client_id WHERE b.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function active_services(): array
{
    return db()->query('SELECT * FROM services WHERE active = 1 ORDER BY sort, name')->fetchAll();
}

function active_locations(): array
{
    return db()->query("SELECT * FROM locations WHERE active = 1 ORDER BY sort, name")->fetchAll();
}

function find_row(string $table, int $id): ?array
{
    if (!in_array($table, ['services', 'locations', 'clients'], true)) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/* Le client peut-il encore annuler / deplacer lui-meme ce rendez-vous ? */
function client_can_change(array $b): bool
{
    return in_array($b['status'], ['pending', 'confirmed'], true)
        && hours_until($b['start_at']) >= setting_int('cancel_hours', 48);
}

function add_message(int $bookingId, string $sender, string $body): void
{
    db()->prepare('INSERT INTO messages(booking_id, sender, body, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$bookingId, $sender, $body, now_str()]);
}

function booking_messages(int $bookingId): array
{
    $st = db()->prepare('SELECT * FROM messages WHERE booking_id = ? ORDER BY id');
    $st->execute([$bookingId]);
    return $st->fetchAll();
}

function mark_messages_read(int $bookingId, string $reader): void
{
    $other = $reader === 'coach' ? 'client' : 'coach';
    db()->prepare('UPDATE messages SET is_read = 1 WHERE booking_id = ? AND sender = ?')
        ->execute([$bookingId, $other]);
}

/* Nom complet du coach / de l'entreprise pour les signatures */
function brand_name(): string
{
    return setting('business_name', 'Coaching');
}
