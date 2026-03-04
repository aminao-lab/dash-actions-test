<?php
// =============================================================================
// public/api/session_end.php — Déconnexion
// =============================================================================

require_once __DIR__ . '/../../config/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => true, 'status' => 'already_logged_out']);
    exit;
}

// Vider la session
$_SESSION = [];

// Supprimer le cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/', '', true, true);
}

session_destroy();

echo json_encode(['ok' => true, 'status' => 'logged_out']);
