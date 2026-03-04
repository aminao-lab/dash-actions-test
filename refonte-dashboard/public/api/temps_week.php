<?php
// =============================================================================
// public/api/temps_week.php — Temps hebdomadaire de l'utilisateur connecté
// =============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/supabase.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$userId = $_SESSION['user_id'] ?? '';
if ($userId === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// ─── Requête Supabase ────────────────────────────────────────────────────────
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/temps_week?' . http_build_query([
    'select'  => '*',
    'user_id' => 'eq.' . $userId,
    'order'   => 'semaine.asc',
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => [
        'apikey: '          . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 400 || $response === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch temps_week']);
    exit;
}

echo json_encode([
    'ok'   => true,
    'rows' => json_decode($response, true) ?? [],
], JSON_UNESCAPED_UNICODE);
