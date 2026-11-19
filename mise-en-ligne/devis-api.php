<?php
// Relais vers le formulaire devis demande.vip-lift.com.
// Le navigateur appelle ce fichier sur vip-lift.com (même site), et ce fichier transmet
// la demande à demande.vip-lift.com côté serveur : aucun réglage n'est nécessaire sur demande.vip-lift.com.

header('Content-Type: application/json; charset=utf-8');

$routes = ['estimate', 'submit-lead', 'submit-plan'];
$route  = $_GET['r'] ?? '';

if (!in_array($route, $routes, true) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Requête invalide']);
    exit;
}

// N'accepter que les appels venant du site VIP Lift (un navigateur envoie toujours
// l'en-tête Origin sur un POST ; un robot qui appelle ce fichier directement est refusé).
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$okSite  = '#^https?://(www\.)?vip-lift\.com(/|$)#';
if (!preg_match($okSite, $origin) && !preg_match($okSite, $referer)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Origine refusée']);
    exit;
}

// Anti-spam : limite le nombre de demandes par adresse IP et par heure.
$limites = ['estimate' => 60, 'submit-lead' => 5, 'submit-plan' => 5];
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'inconnue';
$fichier = sys_get_temp_dir() . '/viplift_' . md5($ip . '|' . $route);
$maintenant = time();
$appels  = [];
if (is_file($fichier)) {
    $appels = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($fichier))),
        fn($t) => $t > $maintenant - 3600
    );
}
if (count($appels) >= $limites[$route]) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Trop de demandes, réessayez plus tard ou appelez-nous.']);
    exit;
}
$appels[] = $maintenant;
@file_put_contents($fichier, implode(',', $appels), LOCK_EX);

$headers = ['Accept: application/json'];
if (!empty($_SERVER['REMOTE_ADDR'])) {
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
}

// Secret partagé avec demande.vip-lift.com : la limite de calculs s'applique alors à chaque
// visiteur (X-Forwarded-For) et non à l'adresse unique de ce relais. Lu hors du dossier www.
foreach ([dirname(__DIR__) . '/vip-lift-config.php', dirname(__DIR__, 2) . '/vip-lift-config.php'] as $f) {
    if (is_file($f)) {
        $cfg = include $f;
        if (is_array($cfg) && !empty($cfg['relay_secret'])) {
            $headers[] = 'X-VipLift-Relay: ' . preg_replace('/[\r\n]/', '', (string)$cfg['relay_secret']);
        }
        break;
    }
}

if ($route === 'submit-plan') {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Fichier manquant']);
        exit;
    }
    if ($f['size'] > 20 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux']);
        exit;
    }
    $payload = [
        'opportunityId' => (string)($_POST['opportunityId'] ?? ''),
        'file'          => new CURLFile($f['tmp_name'], 'application/pdf', basename($f['name'])),
    ];
} else {
    $payload = file_get_contents('php://input');
    if ($payload === false || strlen($payload) > 50000 || json_decode($payload) === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Données invalides']);
        exit;
    }
    $headers[] = 'Content-Type: application/json';

    // Champ piège (honeypot) : invisible pour un visiteur, rempli par les robots.
    // On répond « OK » au robot sans rien transmettre au CRM.
    if ($route === 'submit-lead') {
        $donnees = json_decode($payload, true);
        if (!empty($donnees['honeypot'])) {
            echo json_encode(['success' => true, 'leadId' => 'ok']);
            exit;
        }
    }
}

// Cache des estimations : le serveur de devis limite le nombre de calculs par adresse IP,
// et tous les visiteurs passent par ce relais (donc par la même adresse). On garde chaque
// résultat quelques heures pour ne pas épuiser cette limite.
$cache = null;
if ($route === 'estimate') {
    $d = json_decode($payload, true);
    $cle = is_array($d) ? json_encode([
        (int)($d['levels'] ?? 0), (string)($d['emplacement'] ?? ''),
        (string)($d['produit'] ?? ''), mb_strtolower(trim((string)($d['wilaya'] ?? '')), 'UTF-8'),
    ]) : $payload;
    $cache = sys_get_temp_dir() . '/viplift_est_' . md5($cle);
    if (is_file($cache) && filemtime($cache) > time() - 6 * 3600) {
        echo file_get_contents($cache);
        exit;
    }
}

$ch = curl_init('https://demande.vip-lift.com/api/' . $route);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 40,
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($cache) {
    $j = json_decode((string)$response, true);
    if ($status === 200 && !empty($j['success'])) {
        @file_put_contents($cache, $response, LOCK_EX);
    } elseif (is_file($cache)) {
        // Le serveur de devis refuse ou ne répond pas : on sert le dernier calcul connu.
        echo file_get_contents($cache);
        exit;
    }
}

if ($response === false || !$status) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Formulaire devis injoignable']);
    exit;
}

http_response_code($status);
echo $response;
