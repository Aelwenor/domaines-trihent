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
    $st = db()->prepare('SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.age, c.objective, c.access_token
                         FROM bookings b JOIN clients c ON c.id = b.client_id WHERE b.token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function find_booking(int $id): ?array
{
    $st = db()->prepare('SELECT b.*, c.first_name, c.last_name, c.email, c.phone, c.age, c.objective, c.coach_notes, c.access_token
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

/* ---------- Espace client ---------- */

function find_client_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM clients WHERE access_token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/* Client actuellement connecte a son espace (lien d'invitation ouvert sur ce telephone) */
function current_client(): ?array
{
    return isset($_SESSION['client_token']) ? find_client_by_token($_SESSION['client_token']) : null;
}

function espace_link(string $token): string
{
    return absolute_url('espace.php?c=' . $token);
}

/* Cree une fiche client (ou retrouve celle qui a le meme email) et renvoie son id. */
function save_client(array $c): int
{
    $pdo = db();
    $id = 0;
    if (($c['email'] ?? '') !== '') {
        $st = $pdo->prepare('SELECT id FROM clients WHERE email = ?');
        $st->execute([$c['email']]);
        $id = (int) $st->fetchColumn();
    }
    $age = ($c['age'] ?? '') !== '' && $c['age'] !== null ? (int) $c['age'] : null;
    if ($id) {
        $pdo->prepare('UPDATE clients SET first_name = ?, last_name = ?, age = COALESCE(?, age), phone = ?, objective = ? WHERE id = ?')
            ->execute([$c['first_name'], $c['last_name'], $age, $c['phone'] ?? '', $c['objective'] ?? '', $id]);
        return $id;
    }
    // Sans email (client ajoute a la main), on garde une adresse unique fictive
    $email = ($c['email'] ?? '') !== '' ? $c['email'] : 'sans-email-' . random_token() . '@invalid';
    $pdo->prepare('INSERT INTO clients(first_name, last_name, age, email, phone, objective, created_at, access_token, address, monthly_hours, coach_notes)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$c['first_name'], $c['last_name'], $age, $email, $c['phone'] ?? '', $c['objective'] ?? '', now_str(), random_token(),
                   $c['address'] ?? '', (float) ($c['monthly_hours'] ?? 0), $c['coach_notes'] ?? '']);
    return (int) $pdo->lastInsertId();
}

/* Heures de coaching d'un client sur un mois (seances confirmees ou en attente), en minutes. */
function client_month_minutes(int $clientId, string $month): int
{
    $st = db()->prepare("SELECT start_at, end_at FROM bookings WHERE client_id = ? AND status IN ('confirmed', 'pending')
                         AND start_at >= ? AND start_at < ?");
    $st->execute([$clientId, "$month-01", date('Y-m-d', strtotime("$month-01 +1 month"))]);
    $min = 0;
    foreach ($st as $b) {
        $min += (strtotime($b['end_at']) - strtotime($b['start_at'])) / 60;
    }
    return (int) $min;
}

/* Petite jauge "forfait du mois" (vide si le client n'a pas de forfait). */
function forfait_html(array $client, string $month = ''): string
{
    $hours = (float) ($client['monthly_hours'] ?? 0);
    if ($hours <= 0) {
        return '';
    }
    $month = $month ?: date('Y-m');
    $used = client_month_minutes((int) $client['id'], $month);
    $total = (int) round($hours * 60);
    $pct = min(100, (int) round($used / max(1, $total) * 100));
    $left = $total - $used;
    $label = ucfirst(MOIS[(int) substr($month, 5, 2)]);
    return '<div class="forfait"><div class="forfait-head"><strong>Forfait ' . e($label) . ' : ' . e(fr_duration($used)) . ' sur ' . e(fr_duration($total)) . '</strong>'
         . '<span class="' . ($left < 0 ? 'travel' : 'muted') . ' small">' . ($left > 0 ? 'reste ' . e(fr_duration($left)) . ' à planifier' : ($left === 0 ? 'complet' : 'dépassé de ' . e(fr_duration(-$left)))) . '</span></div>'
         . '<div class="forfait-bar"><span style="width:' . $pct . '%"></span></div></div>';
}

function has_real_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) && !str_ends_with($email, '@invalid');
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
