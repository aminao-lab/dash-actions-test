<?php
// =============================================================================
// public/api/session_start.php
// Valide le lien signé (user_id + exp + HMAC-SHA256) et ouvre la session PHP
// CORRECTION : utilise getenv/constante au lieu de secrets.php inexistant
// =============================================================================

require_once __DIR__ . '/../../config/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// ─── Récupérer le secret ────────────────────────────────────────────────────
$secret = APP_SESSION_SECRET;

if (!is_string($secret) || $secret === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured (APP_SESSION_SECRET manquant)']);
    exit;
}

// ─── Méthode ────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─── Paramètres ─────────────────────────────────────────────────────────────
$userId = trim($_GET['user_id'] ?? '');
$exp    = (int)($_GET['exp']    ?? 0);
$sig    = trim($_GET['sig']     ?? '');

if ($userId === '' || $exp === 0 || $sig === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters (user_id, exp, sig)']);
    exit;
}

// ─── Expiration ──────────────────────────────────────────────────────────────
if (time() > $exp) {
    http_response_code(401);
    echo json_encode(['error' => 'Link expired — please request a new link']);
    exit;
}

// ─── Vérification HMAC ──────────────────────────────────────────────────────
$expected = hash_hmac('sha256', $userId . '|' . $exp, $secret);

if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// ─── Ouvrir la session ───────────────────────────────────────────────────────
$_SESSION['user_id']    = $userId;
$_SESSION['auth_time']  = time();

// Cookie sécurisé (HTTPS en prod)
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 86400,        // 24h
    'path'     => '/',
    'secure'   => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

echo json_encode(['ok' => true]);
