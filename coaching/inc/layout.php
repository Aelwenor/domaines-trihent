<?php
/*
 * En-tete et pied de page HTML communs (cote client et espace coach).
 */

function logo_html(string $class = 'logo'): string
{
    $logo = setting('logo');
    if ($logo !== '' && is_file(UPLOAD_DIR . '/' . $logo)) {
        $v = filemtime(UPLOAD_DIR . '/' . $logo);
        return '<img class="' . $class . '" src="' . e(url('uploads/' . $logo)) . '?v=' . $v . '" alt="' . e(brand_name()) . '">';
    }
    $initials = '';
    foreach (preg_split('/\s+/', brand_name()) as $w) {
        if ($w !== '' && mb_strlen($initials) < 2) {
            $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        }
    }
    return '<span class="' . $class . ' logo-initials">' . e($initials) . '</span>';
}

function page_header(string $title, bool $admin = false): void
{
    $color = setting('brand_color', '#0f766e');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#0f766e';
    }
    $css = url('assets/style.css') . '?v=' . filemtime(APP_ROOT . '/assets/style.css');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> — <?= e(brand_name()) ?></title>
  <meta name="theme-color" content="<?= e($color) ?>">
  <link rel="manifest" href="<?= e(url('manifest.php')) ?>">
  <link rel="stylesheet" href="<?= e($css) ?>">
  <style>:root { --brand: <?= e($color) ?>; }</style>
  <?php if ($admin): ?><meta name="robots" content="noindex"><?php endif; ?>
</head>
<body class="<?= $admin ? 'is-admin' : 'is-public' ?>">
<header class="topbar">
  <div class="wrap topbar-inner">
    <a class="brand" href="<?= e(url($admin ? 'admin/' : '')) ?>">
      <?= logo_html() ?>
      <span class="brand-name"><?= e(brand_name()) ?></span>
    </a>
    <?php if ($admin && is_admin()): ?>
      <?php
        $pending = (int) db()->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
        $unread  = (int) db()->query("SELECT COUNT(*) FROM messages WHERE sender = 'client' AND is_read = 0")->fetchColumn();
      ?>
      <button class="menu-toggle" type="button" onclick="document.body.classList.toggle('menu-open')" aria-label="Menu">☰</button>
      <nav class="nav">
        <a href="<?= e(url('admin/')) ?>">Tableau de bord<?= ($pending + $unread) ? ' <b class="count">' . ($pending + $unread) . '</b>' : '' ?></a>
        <a href="<?= e(url('admin/agenda.php')) ?>">Agenda</a>
        <a href="<?= e(url('admin/nouveau.php')) ?>">+ RDV</a>
        <a href="<?= e(url('admin/disponibilites.php')) ?>">Disponibilités</a>
        <a href="<?= e(url('admin/clients.php')) ?>">Clients</a>
        <a href="<?= e(url('admin/cours.php')) ?>">Cours</a>
        <a href="<?= e(url('admin/lieux.php')) ?>">Lieux &amp; trajets</a>
        <a href="<?= e(url('admin/profil.php')) ?>">Profil</a>
        <a href="<?= e(url('admin/reglages.php')) ?>">Réglages</a>
        <a href="<?= e(url('')) ?>" target="_blank">Voir ma page ↗</a>
        <a href="<?= e(url('admin/logout.php')) ?>">Déconnexion</a>
      </nav>
    <?php elseif (!$admin): ?>
      <nav class="nav nav-public">
        <a href="<?= e(url('')) ?>#cours">Les cours</a>
        <a href="<?= e(url('mes-rdv.php')) ?>">Mes rendez-vous</a>
        <a class="btn btn-small" href="<?= e(url('reserver.php')) ?>">Réserver</a>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?= flashes() ?>
<?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="footer">
  <div class="wrap">
    <?= e(brand_name()) ?><?php if (setting('base_city') !== ''): ?> · <?= e(setting('base_city')) ?> et alentours<?php endif; ?>
    <?php if (!is_admin()): ?> · <a href="<?= e(url('admin/')) ?>">Espace coach</a><?php endif; ?>
  </div>
</footer>
</body>
</html>
<?php
}

/* Petit calendrier mensuel. $days : dates cliquables ; $link : fn(date) -> url */
function month_calendar(int $year, int $month, array $days, callable $link, string $selected = '', ?string $prev = null, ?string $next = null): string
{
    $first = mktime(0, 0, 0, $month, 1, $year);
    $lead = (int) date('N', $first) - 1;
    $n = cal_days($year, $month);
    $h = '<div class="calendar"><div class="cal-head">';
    $h .= $prev ? '<a class="cal-nav" href="' . e($prev) . '">‹</a>' : '<span class="cal-nav disabled">‹</span>';
    $h .= '<strong>' . e(ucfirst(MOIS[$month]) . ' ' . $year) . '</strong>';
    $h .= $next ? '<a class="cal-nav" href="' . e($next) . '">›</a>' : '<span class="cal-nav disabled">›</span>';
    $h .= '</div><div class="cal-grid">';
    foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $d) {
        $h .= '<span class="cal-dow">' . $d . '</span>';
    }
    $h .= str_repeat('<span></span>', $lead);
    for ($d = 1; $d <= $n; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if (isset($days[$date])) {
            $cls = 'cal-day available' . ($date === $selected ? ' selected' : '');
            $h .= '<a class="' . $cls . '" href="' . e($link($date)) . '">' . $d . '</a>';
        } else {
            $h .= '<span class="cal-day">' . $d . '</span>';
        }
    }
    return $h . '</div></div>';
}
