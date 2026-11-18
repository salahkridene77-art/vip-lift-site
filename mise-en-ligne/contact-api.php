<?php
/**
 * VIP Lift – formulaire de contact
 * Envoi en SMTP authentifié OVH (smtp.mail.ovh.net, port 465, SSL/TLS).
 * Expéditeur fixe hello@vip-lift.com, Reply-To = visiteur, objet préfixé [Contact].
 * Le mot de passe n'est PAS dans ce fichier : il est lu dans vip-lift-config.php,
 * placé hors du dossier www (non accessible depuis le web).
 *
 *   GET  contact-api.php?captcha=1  -> {"q":"Combien font 4 + 7 ?","t":"jeton"}
 *   POST contact-api.php            -> {"success":true}
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function reponse($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

/* ---------- Configuration (hors du dossier www) ---------- */
$cfg = [];
foreach ([dirname(__DIR__) . '/vip-lift-config.php', dirname(__DIR__, 2) . '/vip-lift-config.php'] as $f) {
    if (is_file($f)) { $cfg = include $f; break; }
}
$cfg = array_merge([
    'smtp_host'   => 'smtp.mail.ovh.net',
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',               // 'ssl' = SSL/TLS direct (port 465)
    'smtp_user'   => 'hello@vip-lift.com',
    'smtp_pass'   => '',
    'from'        => 'hello@vip-lift.com',
    'from_name'   => 'Site VIP Lift',
    'to'          => 'hello@vip-lift.com',
    'secret'      => '',
], is_array($cfg) ? $cfg : []);
$secret = $cfg['secret'] !== '' ? $cfg['secret'] : hash('sha256', $cfg['smtp_pass'] . __FILE__);

/* ---------- Appels venant uniquement du site ---------- */
$okSite  = '#^https?://(www\.)?vip-lift\.com(/|$)#';
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!preg_match($okSite, $origin) && !preg_match($okSite, $referer) && empty($cfg['allow_any_origin'])) {
    reponse(403, ['success' => false, 'error' => 'Origine refusée']);
}

/* ---------- Limite par adresse IP et par heure ---------- */
function limite($type, $max) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
    $f  = sys_get_temp_dir() . '/viplift_contact_' . md5($ip . '|' . $type);
    $now = time(); $t = [];
    if (is_file($f)) $t = array_filter(array_map('intval', explode(',', (string)file_get_contents($f))), fn($x) => $x > $now - 3600);
    if (count($t) >= $max) reponse(429, ['success' => false, 'error' => 'Trop de messages envoyés. Réessayez plus tard ou appelez-nous.']);
    $t[] = $now; @file_put_contents($f, implode(',', $t), LOCK_EX);
}

/* ---------- Captcha : petite addition signée côté serveur ---------- */
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['captcha'])) {
    limite('captcha', 40);
    $a = random_int(2, 9); $b = random_int(1, 9);
    $exp = time() + 1800; $nonce = bin2hex(random_bytes(6));
    $sig = hash_hmac('sha256', $exp . '|' . $nonce . '|' . ($a + $b), $secret);
    reponse(200, ['q' => "Combien font $a + $b ?", 't' => b64u($exp . '|' . $nonce) . '.' . $sig]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reponse(405, ['success' => false, 'error' => 'Méthode non autorisée']);

/* ---------- Lecture et nettoyage des champs ---------- */
$raw = file_get_contents('php://input', false, null, 0, 20000);
$d = json_decode($raw, true);
if (!is_array($d)) reponse(400, ['success' => false, 'error' => 'Requête invalide']);

// champ piège : un robot le remplit, on répond OK sans rien envoyer
if (!empty($d['site_web'])) reponse(200, ['success' => true]);

function ligne($s, $max) {           // une seule ligne : pas de retour à la ligne (anti-injection d'en-têtes)
    $s = trim(preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', strip_tags((string)$s)));
    return mb_substr($s, 0, $max);
}
$nom   = ligne($d['nom'] ?? '', 80);
$email = ligne($d['email'] ?? '', 120);
$tel   = preg_replace('/[^0-9+ ().-]/', '', ligne($d['telephone'] ?? '', 30));
$msg   = trim(str_replace(["\r\n", "\r"], "\n", strip_tags((string)($d['message'] ?? ''))));
$msg   = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_substr($msg, 0, 3000));

$err = [];
if (mb_strlen($nom) < 2) $err['nom'] = 'Indiquez votre nom.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err['email'] = 'Adresse e-mail invalide.';
if (mb_strlen($msg) < 10) $err['message'] = 'Votre message est trop court.';
if ($err) reponse(422, ['success' => false, 'errors' => $err]);

/* ---------- Vérification du captcha ---------- */
$tok = (string)($d['captcha_t'] ?? ''); $rep = (int)($d['captcha_r'] ?? -1);
$parts = explode('.', $tok, 2);
$ok = false;
if (count($parts) === 2) {
    $plain = base64_decode(strtr($parts[0], '-_', '+/'));
    [$exp, $nonce] = array_pad(explode('|', (string)$plain, 2), 2, '');
    $ok = ctype_digit((string)$exp) && (int)$exp >= time()
       && hash_equals(hash_hmac('sha256', $exp . '|' . $nonce . '|' . $rep, $secret), $parts[1]);
}
if (!$ok) reponse(422, ['success' => false, 'errors' => ['captcha' => 'Réponse incorrecte, réessayez.']]);

limite('envoi', 3);
if ($cfg['smtp_pass'] === '') reponse(503, ['success' => false, 'error' => "L'envoi n'est pas encore configuré."]);

/* ---------- Message ---------- */
$sujet = '[Contact] ' . $nom;
$corps = "Nouveau message depuis le site vip-lift.com\n\n"
       . "Nom : $nom\nE-mail : $email\nTéléphone : " . ($tel !== '' ? $tel : '—') . "\n\n"
       . "Message :\n$msg\n\n— Pour répondre, utilisez simplement « Répondre » : la réponse part vers le visiteur.\n";

/* ---------- Envoi SMTP authentifié (SSL/TLS, port 465) ---------- */
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
    $cmd(null, 220);
    $cmd('EHLO vip-lift.com', 250);
    $cmd('AUTH LOGIN', 334);
    $cmd(base64_encode($cfg['smtp_user']), 334);
    $cmd(base64_encode($cfg['smtp_pass']), 235);
    $cmd('MAIL FROM:<' . $cfg['from'] . '>', 250);
    $cmd('RCPT TO:<' . $cfg['to'] . '>', 250);
    $cmd('DATA', 354);
    $enc = fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $h = [
        'Date: ' . date('r'),
        'From: ' . $enc($cfg['from_name']) . ' <' . $cfg['from'] . '>',
        'To: <' . $cfg['to'] . '>',
        'Reply-To: ' . $enc($replyName) . ' <' . $replyTo . '>',
        'Subject: ' . $enc($sujet),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@vip-lift.com>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    fwrite($s, implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($corps), 76, "\r\n") . ".\r\n");
    $cmd(null, 250);
    fwrite($s, "QUIT\r\n"); fclose($s);
}

try {
    smtp_envoi($cfg, $email, $nom, $sujet, $corps);
    reponse(200, ['success' => true]);
} catch (Exception $e) {
    error_log('VIP Lift contact : ' . $e->getMessage());
    reponse(502, ['success' => false, 'error' => "Le message n'a pas pu partir. Appelez-nous ou écrivez-nous sur WhatsApp."]);
}
