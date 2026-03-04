<?php
// =============================================================================
// public/api/session_check.php
// Middleware d'authentification — inclus par les autres endpoints
// Expose $currentUserId après vérification
// =============================================================================

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$currentUserId = $_SESSION['user_id'];
