<?php
/**
 * VIP Lift – inscription à la lettre pro (architectes, bureaux d'études, promoteurs…)
 *
 *   POST pro-api.php  -> {"success":true,"crm":true|false}
 *
 * 1. Crée le contact / lead dans le CRM (demande.vip-lift.com/api/submit-subscriber),
 *    avec le secret partagé du relais (X-VipLift-Relay).
 * 2. Tant que le CRM n'a pas cette route (ou s'il ne répond pas), l'inscription n'est
 *    jamais perdue : elle part par e-mail à hello@vip-lift.com et elle est ajoutée à
 *    un fichier de sauvegarde hors du dossier www.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function reponse($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

/* ---------- Configuration (hors du dossier www) ---------- */
$cfg = []; $cfgDir = dirname(__DIR__);
foreach ([dirname(__DIR__) . '/vip-lift-config.php', dirname(__DIR__, 2) . '/vip-lift-config.php'] as $f) {
    if (is_file($f)) { $cfg = include $f; $cfgDir = dirname($f); break; }
}
$cfg = array_merge([
    'smtp_host' => 'smtp.mail.ovh.net', 'smtp_port' => 465, 'smtp_secure' => 'ssl',
    'smtp_user' => 'hello@vip-lift.com', 'smtp_pass' => '',
    'from' => 'hello@vip-lift.com', 'from_name' => 'Site VIP Lift', 'to' => 'hello@vip-lift.com',
    'relay_secret' => '',
], is_array($cfg) ? $cfg : []);

/* ---------- Appels venant uniquement du site ---------- */
$okSite  = '#^https?://(www\.)?vip-lift\.com(/|$)#';
if (!preg_match($okSite, $_SERVER['HTTP_ORIGIN'] ?? '') && !preg_match($okSite, $_SERVER['HTTP_REFERER'] ?? '')) {
    reponse(403, ['success' => false, 'error' => 'Origine refusée']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reponse(405, ['success' => false, 'error' => 'Méthode non autorisée']);

$d = json_decode(file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($d)) reponse(400, ['success' => false, 'error' => 'Requête invalide']);

// Robots : champ piège rempli, ou formulaire envoyé en moins de 3 secondes
if (!empty($d['site_web']) || (isset($d['t']) && (int)$d['t'] < 3000)) reponse(200, ['success' => true, 'crm' => false]);

/* ---------- Limite : 3 inscriptions par adresse IP et par heure ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
$lf = sys_get_temp_dir() . '/viplift_pro_' . md5($ip);
$now = time(); $t = [];
if (is_file($lf)) $t = array_filter(array_map('intval', explode(',', (string)file_get_contents($lf))), fn($x) => $x > $now - 3600);
if (count($t) >= 3) reponse(429, ['success' => false, 'error' => 'Trop de demandes. Réessayez plus tard ou appelez-nous.']);

/* ---------- Champs ---------- */
function ligne($s, $max) { $s = trim(preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', (string)$s)); return mb_substr($s, 0, $max); }
$PROFILS = ['ARCHITECTE' => 'Architecte', 'BUREAU_ETUDES' => "Bureau d'études", 'PROMOTEUR' => 'Promoteur immobilier',
            'ENTREPRISE' => 'Entreprise du bâtiment', 'DECORATEUR' => "Architecte d'intérieur / décorateur", 'AUTRE' => 'Autre'];
$c = [
    'nom'       => ligne($d['nom'] ?? '', 80),
    'prenom'    => ligne($d['prenom'] ?? '', 80),
    'societe'   => ligne($d['societe'] ?? '', 120),
    'profil'    => strtoupper(ligne($d['profil'] ?? '', 20)),
    'ville'     => ligne($d['ville'] ?? '', 80),
    'telephone' => ligne($d['telephone'] ?? '', 25),
    'email'     => ligne($d['email'] ?? '', 120),
];
$err = [];
if (mb_strlen($c['nom']) < 2) $err['nom'] = 'Indiquez votre nom.';
if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'Adresse e-mail invalide.';
if (!isset($PROFILS[$c['profil']])) $err['profil'] = 'Choisissez votre profil.';
if ($c['telephone'] !== '' && !preg_match('/^\+?[0-9 .()-]{8,20}$/', $c['telephone'])) $err['telephone'] = 'Numéro invalide.';
if (empty($d['consentement'])) $err['consentement'] = 'Merci de cocher la case.';
if ($err) reponse(422, ['success' => false, 'errors' => $err]);
$page = ligne($d['page'] ?? '', 200);

$t[] = $now; @file_put_contents($lf, implode(',', $t), LOCK_EX);

/* ---------- 1. CRM ---------- */
$crm = false;
$payload = json_encode([
    'type' => 'NEWSLETTER_PRO',
    'contact' => $c,
    'consentement' => true,
    'source' => 'site-vip-lift',
    'page' => $page,
], JSON_UNESCAPED_UNICODE);
$h = ['Content-Type: application/json', 'Accept: application/json', 'X-Forwarded-For: ' . $ip];
if ($cfg['relay_secret'] !== '') $h[] = 'X-VipLift-Relay: ' . preg_replace('/[\r\n]/', '', (string)$cfg['relay_secret']);
$ch = curl_init('https://demande.vip-lift.com/api/submit-subscriber');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $h,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
$res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = json_decode((string)$res, true);
if ($code >= 200 && $code < 300 && !empty($j['success'])) $crm = true;

/* ---------- 2. Sauvegarde : fichier hors www + e-mail ---------- */
$profilTxt = $PROFILS[$c['profil']];
$csv = $cfgDir . '/vip-lift-inscrits-pro.csv';
$neuf = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
    if ($neuf) fputcsv($fh, ['date', 'nom', 'prenom', 'societe', 'profil', 'ville', 'telephone', 'email', 'page', 'crm']);
    fputcsv($fh, [date('Y-m-d H:i'), $c['nom'], $c['prenom'], $c['societe'], $profilTxt, $c['ville'], $c['telephone'], $c['email'], $page, $crm ? 'oui' : 'non']);
    fclose($fh); @chmod($csv, 0600);
}

function smtp_envoi($cfg, $replyTo, $replyName, $sujet, $corps) {
    $hote = ($cfg['smtp_secure'] === 'ssl' ? 'ssl://' : '') . $cfg['smtp_host'];
    $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $s = @stream_socket_client($hote . ':' . $cfg['smtp_port'], $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new Exception("Connexion SMTP impossible ($es)");
    stream_set_timeout($s, 20);
    $lire = function () use ($s) { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
    $cmd  = function ($c, $attendu) use ($s, $lire) {
        if ($c !== null) fwrite($s, $c . "\r\n");
        $r = $lire();
        if ((int)substr($r, 0, 3) !== $attendu) throw new Exception('SMTP : ' . trim($r));
        return $r;
    };
    $cmd(null, 220); $cmd('EHLO vip-lift.com', 250); $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($cfg['smtp_user']), 334); $cmd(base64_encode($cfg['smtp_pass']), 235);
    $cmd('MAIL FROM:<' . $cfg['from'] . '>', 250); $cmd('RCPT TO:<' . $cfg['to'] . '>', 250); $cmd('DATA', 354);
    $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $hd = ['Date: ' . date('r'), 'From: ' . $enc($cfg['from_name']) . ' <' . $cfg['from'] . '>', 'To: <' . $cfg['to'] . '>',
           'Reply-To: ' . $enc($replyName) . ' <' . $replyTo . '>', 'Subject: ' . $enc($sujet),
           'Message-ID: <' . bin2hex(random_bytes(12)) . '@vip-lift.com>', 'MIME-Version: 1.0',
           'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
    fwrite($s, implode("\r\n", $hd) . "\r\n\r\n" . chunk_split(base64_encode($corps), 76, "\r\n") . ".\r\n");
    $cmd(null, 250); fwrite($s, "QUIT\r\n"); fclose($s);
}
if ($cfg['smtp_pass'] !== '') {
    $nomComplet = trim($c['prenom'] . ' ' . $c['nom']);
    $corps = "Nouvelle inscription à la lettre pro depuis vip-lift.com\n\n"
           . "Nom : $nomComplet\nSociété / cabinet : " . ($c['societe'] ?: '—') . "\nProfil : $profilTxt\n"
           . "Ville : " . ($c['ville'] ?: '—') . "\nTéléphone : " . ($c['telephone'] ?: '—') . "\nE-mail : {$c['email']}\n"
           . "Page : " . ($page ?: '—') . "\n\nCréé dans le CRM : " . ($crm ? 'oui' : "non — à saisir dans le CRM") . "\n";
    try { smtp_envoi($cfg, $c['email'], $nomComplet, '[Lettre pro] ' . $nomComplet . ' — ' . $profilTxt, $corps); }
    catch (Exception $e) { error_log('VIP Lift lettre pro : ' . $e->getMessage()); }
}

reponse(200, ['success' => true, 'crm' => $crm]);
