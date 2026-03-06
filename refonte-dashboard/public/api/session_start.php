<?php
require_once __DIR__ . '/../../config/config.php';

$secret = APP_SESSION_SECRET ?? null;
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!is_string($secret) || $secret === '') {
  http_response_code(500);
  echo json_encode(['error' => 'Server not configured (app_session_secret)']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed']);
  exit;
}

$userId = isset($_GET['user_id']) ? trim($_GET['user_id']) : '';
$exp    = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
$sig    = isset($_GET['sig']) ? trim($_GET['sig']) : '';
if ($userId === '' || $exp === 0 || $sig === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Missing parameters (user_id, exp, sig)']);
  exit;
}

if (time() > $exp) {
  http_response_code(401);
  echo json_encode(['error' => 'Link expired']);
  exit;
}

$expected = hash_hmac('sha256', $userId . '|' . $exp, $secret);

if (!hash_equals($expected, $sig)) {
  http_response_code(401);
  echo json_encode(['error' => 'Invalid signature']);
  exit;
}

$_SESSION['user_id'] = $userId;
echo json_encode(['ok' => true]);
