<?php
/*
 * Relais de lecture d'annonce pour l'outil d'étude de rentabilité.
 * Le navigateur ne peut pas lire directement le site d'une agence : ce script
 * récupère la page (ou sa photo) depuis le serveur et la renvoie à l'outil.
 *   annonce.php?url=...            -> JSON { ok, url, html }
 *   annonce.php?url=...&type=image -> l'image elle-même
 */
header('X-Robots-Tag: noindex, nofollow');

function repondre($code, $donnees) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Réservé aux pages de ce site
$origine = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$hoteSite = strtolower(preg_replace(['/^www\./', '/:\d+$/'], '', $_SERVER['HTTP_HOST'] ?? ''));
$hoteOrigine = strtolower(preg_replace('/^www\./', '', parse_url($origine, PHP_URL_HOST) ?? ''));
if ($hoteOrigine === '' || $hoteOrigine !== $hoteSite) repondre(403, ['ok' => false, 'erreur' => 'Accès refusé.']);

$image = ($_GET['type'] ?? '') === 'image';

// Adresse publique en http(s) uniquement, jamais vers le réseau interne
function adresseValide($url) {
  $p = parse_url($url);
  if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
  if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return false;
  $ips = gethostbynamel($p['host']);
  if (!$ips) return false;
  foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return true;
}

function telecharger($url, $image) {
  $max = $image ? 8 * 1024 * 1024 : 3 * 1024 * 1024;
  for ($saut = 0; $saut < 4; $saut++) {
    if (!adresseValide($url)) return [null, null, 'Adresse non valide.'];
    $corps = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_ENCODING => '',
      CURLOPT_HTTPHEADER => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Accept: ' . ($image ? 'image/*' : 'text/html,application/xhtml+xml'),
        'Accept-Language: fr-FR,fr;q=0.9'
      ],
      CURLOPT_WRITEFUNCTION => function ($ch, $morceau) use (&$corps, $max) {
        $corps .= $morceau;
        return strlen($corps) > $max ? 0 : strlen($morceau);
      }
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    $suite = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($code >= 300 && $code < 400 && $suite) { $url = $suite; continue; }
    if ($code !== 200 || $corps === '') return [null, null, "Le site de l'agence a répondu $code."];
    return [$corps, $type, null, $url];
  }
  return [null, null, 'Trop de redirections.'];
}

$url = trim($_GET['url'] ?? '');
if ($url === '') repondre(400, ['ok' => false, 'erreur' => 'Adresse manquante.']);
if (!function_exists('curl_init')) repondre(500, ['ok' => false, 'erreur' => 'cURL indisponible sur ce serveur.']);

$r = telecharger($url, $image);
if ($r[0] === null) repondre(502, ['ok' => false, 'erreur' => $r[2]]);

if ($image) {
  if (stripos($r[1], 'image/') !== 0) repondre(415, ['ok' => false, 'erreur' => "Ce n'est pas une image."]);
  header('Content-Type: ' . $r[1]);
  header('Cache-Control: private, max-age=3600');
  echo $r[0];
  exit;
}

$html = $r[0];
if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
repondre(200, ['ok' => true, 'url' => $r[3], 'html' => $html]);
