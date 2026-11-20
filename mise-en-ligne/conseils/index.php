<?php
/**
 * Conseils VIP Lift — articles écrits et publiés dans NextUp (espace « VIP Lift »,
 * Marketing → Articles de blog), affichés ici dans le design du site.
 *
 *   /conseils/                  liste des articles
 *   /conseils/<adresse>/        un article (rendu côté serveur, lisible par Google)
 *   /conseils/?format=json      3 derniers articles (section « Conseils » de l'accueil)
 *
 * En-tête et pied de page : repris en direct de la page « Adapter sa maison de 30 ans »,
 * pour rester identiques au reste du site sans copie à maintenir.
 * Aucune clé : l'API NextUp utilisée est publique (articles publiés uniquement).
 */
declare(strict_types=1);

define('VL_API', getenv('VL_API') ?: 'https://nextup-v1-production.up.railway.app/api/public/agencies/2c7118cc-23bb-4eee-8568-2aec9e1b6f9f/blog-posts');
define('VL_CLIENT', getenv('VL_CLIENT') ?: 'b4bd60c0-e1ef-480d-8573-ca0c3b6be850');
const VL_CANONICAL = 'https://vip-lift.com';
const VL_TTL = 300;

// Racine du site : « / » en production, « /nouveau/ » sur la version de test.
$ROOT = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/conseils/index.php'))), '/') . '/';
$IS_TEST = strpos($ROOT, '/nouveau/') !== false;

function vl_h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vl_fetch(string $path): array
{
    $dir = __DIR__ . '/cache';
    $file = $dir . '/' . (preg_replace('/[^a-z0-9-]+/', '_', strtolower($path)) ?: 'liste') . '.json';
    $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (is_array($cached) && time() - (int)@filemtime($file) < VL_TTL) return [200, $cached];
    $url = VL_API . $path . '?client=' . rawurlencode(VL_CLIENT);
    $code = 0;
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if ($code === 200 && is_array($data)) {
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return [200, $data];
    }
    if ($code === 404) {
        @unlink($file);
        return [404, null];
    }
    if (is_array($cached)) return [200, $cached]; // NextUp indisponible : dernière version connue
    return [503, null];
}

function vl_date(?string $iso): string
{
    $ts = $iso ? strtotime($iso) : false;
    if (!$ts) return '';
    $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int)gmdate('j', $ts) . ' ' . $m[(int)gmdate('n', $ts) - 1] . ' ' . gmdate('Y', $ts);
}

function vl_minutes(string $body): int
{
    return max(1, (int)round(str_word_count(strip_tags($body)) / 200));
}

/** Texte NextUp → HTML : paragraphes, intertitres « 1. … » / « ## … », images de section. */
function vl_body(string $body, array $images = []): array
{
    $blocks = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', trim($body)) ?: []), 'strlen'));
    $isHeading = static fn(string $b): bool => strpos($b, "\n") === false && mb_strlen($b) <= 120 && preg_match('/^(\d+[.)]|#{1,3})\s+\S/u', $b);
    $chapo = '';
    if ($blocks && !$isHeading($blocks[0])) $chapo = array_shift($blocks);
    $out = '';
    $section = -1;
    foreach ($blocks as $b) {
        if ($isHeading($b)) {
            $section++;
            $out .= '<h2>' . vl_h(preg_replace('/^#{1,3}\s+/u', '', $b)) . "</h2>\n";
            $img = $images[$section] ?? null;
            if (is_string($img) && preg_match('#^https://#i', $img)) {
                $out .= '<figure class="art-hero"><img src="' . vl_h($img) . '" alt="" loading="lazy" decoding="async"></figure>' . "\n";
            }
            continue;
        }
        $out .= '<p>' . nl2br(vl_h($b), false) . "</p>\n";
    }
    return [$chapo, $out];
}

/** En-tête et pied du site, repris de la page article de référence, liens recalés sur la racine. */
function vl_chrome(string $root): array
{
    $src = (string)@file_get_contents(dirname(__DIR__) . '/adapter-maison-30-ans-home-lift.html');
    $pick = static function (string $re) use ($src): string {
        return preg_match($re, $src, $m) ? $m[0] : '';
    };
    $fix = static function (string $html) use ($root): string {
        return preg_replace_callback('/\b(href|src)="([^"]*)"/', static function ($m) use ($root) {
            $v = $m[2];
            if ($v === '' || preg_match('#^(https?:|mailto:|tel:|\#|/)#i', $v)) return $m[0];
            $v = preg_replace('#^\./#', '', $v);
            return $m[1] . '="' . $root . $v . '"';
        }, $html);
    };
    return [
        $fix($pick('#<header class="nav">.*?</header>#s')),
        $fix($pick('#<footer[^>]*>.*?</footer>#s')),
    ];
}

function vl_render(int $status, string $canonical, string $title, string $desc, string $main, string $head = ''): void
{
    global $ROOT, $IS_TEST;
    [$header, $footer] = vl_chrome($ROOT);
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    $robots = ($status !== 200 || $IS_TEST) ? '<meta name="robots" content="noindex, follow">' : '';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . vl_h($title) . '</title><meta name="description" content="' . vl_h($desc) . '">'
        . '<link rel="canonical" href="' . vl_h($canonical) . '">' . $robots
        . '<meta name="theme-color" content="#0b6391"><meta property="og:locale" content="fr_DZ"><meta property="og:site_name" content="VIP Lift Technologie">'
        . '<meta property="og:title" content="' . vl_h($title) . '"><meta property="og:description" content="' . vl_h($desc) . '"><meta property="og:url" content="' . vl_h($canonical) . '">'
        . '<link rel="icon" href="' . $ROOT . 'img/logo-h.png">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="' . $ROOT . 'style.css?v=36">' . $head
        . '<style>.cs-meta{color:var(--mut);font-size:13px;margin-top:10px}.posts .post p{color:var(--mut);font-size:14px;margin-top:6px;line-height:1.5}.cs-empty{color:var(--mut);margin-top:24px}</style>'
        . "</head>\n<body class=\"art-page\">\n" . $header . "\n<main>\n" . $main . "\n</main>\n" . $footer
        . "\n<script src=\"" . $ROOT . "liens.js?v=8\" defer></script>\n</body>\n</html>";
}

$slug = isset($_GET['slug']) ? (string)$_GET['slug'] : '';

// ----- Flux JSON pour la page d'accueil -----
if (($_GET['format'] ?? '') === 'json') {
    [$code, $posts] = vl_fetch('');
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    $items = [];
    foreach (array_slice($code === 200 ? $posts : [], 0, 3) as $p) {
        $items[] = [
            'url' => $ROOT . 'conseils/' . rawurlencode((string)$p['slug']) . '/',
            'title' => (string)$p['title'],
            'excerpt' => (string)($p['excerpt'] ?? ''),
            'image' => preg_match('#^https://#i', (string)($p['cover_image_url'] ?? '')) ? $p['cover_image_url'] : null,
        ];
    }
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ----- Liste -----
if ($slug === '') {
    [$code, $posts] = vl_fetch('');
    $canonical = VL_CANONICAL . '/conseils/';
    $cards = '';
    foreach ($code === 200 ? $posts : [] as $p) {
        $img = preg_match('#^https://#i', (string)($p['cover_image_url'] ?? ''))
            ? '<img src="' . vl_h($p['cover_image_url']) . '" alt="' . vl_h((string)$p['title']) . '" loading="lazy" decoding="async" onerror="this.onerror=null;this.src=\'' . $ROOT . 'img/hero.webp\'">'
            : '<img src="' . $ROOT . 'img/hero.webp" alt="" loading="lazy" decoding="async">';
        $cards .= '<a class="post" href="' . $ROOT . 'conseils/' . rawurlencode((string)$p['slug']) . '/">' . $img
            . '<h3>' . vl_h((string)$p['title']) . '</h3>'
            . ($p['excerpt'] ?? '' ? '<p>' . vl_h((string)$p['excerpt']) . '</p>' : '')
            . '<span class="cs-meta">' . vl_h(vl_date($p['published_at'] ?? null)) . '</span></a>';
    }
    $main = '<section class="panel art" style="max-width:none">'
        . '<nav class="crumb" aria-label="Fil d\'Ariane"><a href="' . $ROOT . '">Accueil</a> › <span>Conseils</span></nav>'
        . '<span class="pill">Conseils</span><h1>Conseils et actualités VIP Lift</h1>'
        . '<p class="meta">Home Lift, rénovation, conception de villa : nos réponses d\'experts.</p>'
        . ($cards !== '' ? '<div class="posts">' . $cards . '</div>'
            : '<p class="cs-empty">' . ($code === 200 ? 'Les prochains articles arrivent très bientôt.' : 'Les articles sont momentanément indisponibles. Merci de réessayer dans quelques minutes.') . '</p>')
        . '</section>';
    vl_render($code === 200 ? 200 : 503, $canonical, 'Conseils Home Lift et ascenseur de maison | VIP Lift',
        'Conseils, guides et actualités VIP Lift : installer un Home Lift en villa, en construction neuve comme en rénovation.', $main);
    exit;
}

// ----- Article -----
if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) $slug = '';
[$code, $post] = $slug === '' ? [404, null] : vl_fetch('/' . $slug);
$canonical = VL_CANONICAL . '/conseils/' . rawurlencode($slug) . '/';
if ($code !== 200) {
    $msg = $code === 404 ? 'Cet article n\'existe pas ou n\'est plus publié.' : 'Les articles sont momentanément indisponibles. Merci de réessayer dans quelques minutes.';
    vl_render($code === 404 ? 404 : 503, $canonical, 'Article introuvable | VIP Lift', $msg,
        '<article class="panel art"><nav class="crumb"><a href="' . $ROOT . '">Accueil</a> › <a href="' . $ROOT . 'conseils/">Conseils</a></nav><h1>Article introuvable</h1><p>' . vl_h($msg) . '</p></article>');
    exit;
}
$title = (string)$post['title'];
$desc = (string)(($post['seo_description'] ?? '') ?: ($post['excerpt'] ?? ''));
[$chapo, $html] = vl_body((string)$post['body'], is_array($post['section_images'] ?? null) ? $post['section_images'] : []);
$cover = preg_match('#^https://#i', (string)($post['cover_image_url'] ?? '')) ? (string)$post['cover_image_url'] : null;
$date = vl_date($post['published_at'] ?? null);
$ld = [[
    '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'description' => $desc,
    'datePublished' => substr((string)($post['published_at'] ?? ''), 0, 10), 'dateModified' => substr((string)($post['updated_at'] ?? ''), 0, 10),
    'inLanguage' => 'fr', 'mainEntityOfPage' => $canonical,
    'author' => ['@type' => 'Organization', 'name' => 'VIP Lift Technologie', 'url' => VL_CANONICAL . '/'],
    'publisher' => ['@id' => VL_CANONICAL . '/#entreprise', '@type' => 'Organization', 'name' => 'VIP Lift Technologie', 'logo' => ['@type' => 'ImageObject', 'url' => VL_CANONICAL . '/img/logo-h.png']],
] + ($cover ? ['image' => $cover] : []), [
    '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => VL_CANONICAL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Conseils', 'item' => VL_CANONICAL . '/conseils/'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => $canonical],
    ],
]];
$head = '<meta property="og:type" content="article">' . ($cover ? '<meta property="og:image" content="' . vl_h($cover) . '"><meta name="twitter:card" content="summary_large_image">' : '')
    . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
$main = '<article class="panel art">'
    . '<nav class="crumb" aria-label="Fil d\'Ariane"><a href="' . $ROOT . '">Accueil</a> › <a href="' . $ROOT . 'conseils/">Conseils</a> › <span>' . vl_h($title) . '</span></nav>'
    . '<span class="pill">Conseils</span><h1>' . vl_h($title) . '</h1>'
    . '<p class="meta">Par l\'équipe VIP Lift' . ($date ? ' · ' . vl_h($date) : '') . ' · ' . vl_minutes((string)$post['body']) . ' min de lecture</p>'
    . ($cover ? '<figure class="art-hero"><img src="' . vl_h($cover) . '" alt="' . vl_h($title) . '" fetchpriority="high" decoding="async" onerror="this.closest(\'figure\').remove()"></figure>' : '')
    . ($chapo !== '' ? '<p class="chapo">' . nl2br(vl_h($chapo), false) . '</p>' : '')
    . $html
    . '<p style="margin-top:36px"><a class="btn or" href="' . $ROOT . '#simulateur">Demande de devis</a> <a class="btn ghost" href="tel:+213541850090">+213 541 85 00 90</a></p>'
    . '</article>';
vl_render(200, $canonical, $title . ' | VIP Lift', $desc, $main, $head);
