<?php
// =============================================================================
// public/api/students.php — Liste des étudiants (authentifié uniquement)
// CORRECTION : ajout de la vérification de session
// =============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/supabase.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// ─── Auth (requis) ────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (SUPABASE_URL === '' || SUPABASE_SERVICE_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase config missing']);
    exit;
}

$limit = max(1, min((int)($_GET['limit'] ?? 50), 200));

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/students?' . http_build_query([
    'select' => 'user_id,email,username,is_enrolled,date_maj',
    'order'  => 'date_maj.desc',
    'limit'  => $limit,
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
echo json_encode([
    'ok'       => true,
    'count'    => is_array($data) ? count($data) : 0,
    'students' => $data,
], JSON_UNESCAPED_UNICODE);
