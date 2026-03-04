<?php
// =============================================================================
// public/api/me.php — Données du user connecté via la vue students_with_progress
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

// ─── Auth ────────────────────────────────────────────────────────────────────
$userId = $_SESSION['user_id'] ?? '';
if ($userId === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

// ─── Vérif config ───────────────────────────────────────────────────────────
if (SUPABASE_URL === '' || SUPABASE_SERVICE_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase config missing']);
    exit;
}

// ─── Requête vers la vue students_with_progress ──────────────────────────────
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/students_with_progress?' . http_build_query([
    'select'  => '*',
    'user_id' => 'eq.' . $userId,
    'limit'   => 1,
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
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Curl failed', 'details' => $curlErr]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase error', 'http' => $httpCode, 'details' => $response]);
    exit;
}

$data = json_decode($response, true);
$me   = (is_array($data) && count($data) > 0) ? $data[0] : null;

if ($me === null) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found or not enrolled']);
    exit;
}

echo json_encode(['ok' => true, 'me' => $me], JSON_UNESCAPED_UNICODE);
