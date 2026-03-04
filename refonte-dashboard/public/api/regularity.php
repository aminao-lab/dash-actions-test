<?php
// =============================================================================
// public/api/regularity.php — Badges de régularité du mois
// =============================================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/session_check.php'; // fournit $currentUserId

header('Content-Type: application/json; charset=utf-8');

try {
    $tz         = new DateTimeZone('Europe/Paris');
    $now        = new DateTime('now', $tz);
    $monthStart = $now->format('Y-m-01');

    $supabase = new SupabaseClient();

    // Lire via la vue view_monthly_regularity
    $rows = $supabase->select(
        'view_monthly_regularity',
        'active_days',
        [
            'user_id'     => "eq.{$currentUserId}",
            'month_start' => "eq.{$monthStart}",
        ]
    );

    $activeDays = 0;
    if ($rows && count($rows) > 0) {
        $activeDays = (int)($rows[0]['active_days'] ?? 0);
    }

    // Paliers
    $tiers = [
        'bronze'   => 3,
        'silver'   => 6,
        'gold'     => 12,
        'platinum' => 19,
        'diamond'  => 25,
    ];

    $currentTier = null;
    $nextTier    = null;

    foreach ($tiers as $tier => $minDays) {
        if ($activeDays >= $minDays) {
            $currentTier = $tier;
        } else {
            $nextTier = $tier;
            break;
        }
    }

    if ($currentTier === 'diamond') $nextTier = null;

    $daysToNext = ($nextTier !== null) ? max(0, $tiers[$nextTier] - $activeDays) : null;

    echo json_encode([
        'ok'          => true,
        'month'       => $now->format('Y-m'),
        'active_days' => $activeDays,
        'tier'        => $currentTier,
        'next_tier'   => $nextTier,
        'days_to_next'=> $daysToNext,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
