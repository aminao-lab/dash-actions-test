<?php
// =============================================================================
// sync_daily_activity.php — Calcule l'activité quotidienne + snapshots
// CORRECTION : getenv('TOTAL_JOBS') au lieu de $_ENV['JOB_COUNT']
// =============================================================================

require_once __DIR__ . '/../config/config.php';

if (!getenv('SUPABASE_URL') || !getenv('SUPABASE_SERVICE_KEY')) {
    die("❌ Variables SUPABASE_URL / SUPABASE_SERVICE_KEY manquantes\n");
}

date_default_timezone_set('Europe/Paris');

$SUPABASE_URL         = getenv('SUPABASE_URL');
$SUPABASE_SERVICE_KEY = getenv('SUPABASE_SERVICE_KEY');

// ✅ CORRIGÉ : getenv() au lieu de $_ENV
$jobIndex  = (int)(getenv('JOB_INDEX')  ?: 0);
$totalJobs = (int)(getenv('TOTAL_JOBS') ?: 1);

// Date cible = J-1 (stable pendant tout le run)
$targetDate     = (new DateTime('yesterday', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
$firstDayOfMonth = (new DateTime($targetDate))->modify('first day of this month')->format('Y-m-d');
$daysInMonth    = (int)(new DateTime($targetDate))->format('t');

// ─── Helpers HTTP Supabase ──────────────────────────────────────────────────
function sb_headers(array $extra = []): array {
    global $SUPABASE_SERVICE_KEY;
    return array_merge([
        "apikey: $SUPABASE_SERVICE_KEY",
        "Authorization: Bearer $SUPABASE_SERVICE_KEY",
        "Accept: application/json",
        "Content-Type: application/json",
    ], $extra);
}

function sb_get(string $path): array {
    global $SUPABASE_URL;
    $ch = curl_init(rtrim($SUPABASE_URL, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => sb_headers(),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) throw new Exception("GET curl error: $err");
    if ($http >= 400)   throw new Exception("GET error $http: $res");

    return json_decode($res, true) ?? [];
}

function sb_get_all(string $path): array {
    $all    = [];
    $offset = 0;
    $limit  = 1000;

    while (true) {
        $sep   = str_contains($path, '?') ? '&' : '?';
        $batch = sb_get($path . $sep . "limit=$limit&offset=$offset");

        if (empty($batch)) break;
        $all = array_merge($all, $batch);
        if (count($batch) < $limit) break;
        $offset += $limit;
    }
    return $all;
}

function sb_upsert(string $table, array $rows): void {
    global $SUPABASE_URL;
    $ch = curl_init(rtrim($SUPABASE_URL, '/') . "/rest/v1/$table");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($rows),
        CURLOPT_HTTPHEADER     => sb_headers(["Prefer: resolution=merge-duplicates"]),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) throw new Exception("UPSERT curl error: $err");
    if ($http >= 400)   throw new Exception("UPSERT error $http: $res");
}

function toInt($v): int { return is_numeric($v) ? (int)$v : 0; }

// ─── DÉBUT ──────────────────────────────────────────────────────────────────
echo "=== START sync_daily_activity (date={$targetDate}) Job {$jobIndex}/{$totalJobs} ===\n";

// 1) Lire temps_niveau (tous les niveaux, somme = cumul total)
$temps = sb_get_all('/rest/v1/temps_niveau?select=*');

echo "[INFO] temps_niveau rows=" . count($temps) . "\n";
if (count($temps) === 0) {
    echo "[WARN] temps_niveau vide → rien à faire\n";
    exit(0);
}

// 2) Charger les snapshots précédents (pour calculer le delta)
$allPrevSnapshots = sb_get_all(
    '/rest/v1/daily_cumul_snapshot'
    . '?select=user_id,total_cumul_seconds,snapshot_date'
    . '&snapshot_date=lt.'    . rawurlencode($targetDate)
    . '&snapshot_date=gte.'   . rawurlencode((new DateTime($targetDate))->modify('-32 days')->format('Y-m-d'))
    . '&order=snapshot_date.desc'
);

// Garder uniquement le snapshot le plus récent par user
$prevByUser = [];
foreach ($allPrevSnapshots as $row) {
    $uid = $row['user_id'];
    if (!isset($prevByUser[$uid])) {
        $prevByUser[$uid] = (int)$row['total_cumul_seconds'];
    }
}

// 3) Charger les jours actifs déjà enregistrés ce mois
$allActiveDays = sb_get_all(
    '/rest/v1/daily_activity'
    . '?select=user_id,activity_date'
    . '&activity_date=gte.' . rawurlencode($firstDayOfMonth)
    . '&activity_date=lte.' . rawurlencode($targetDate)
);

$activeDaysByUser = [];
foreach ($allActiveDays as $row) {
    $activeDaysByUser[$row['user_id']] = ($activeDaysByUser[$row['user_id']] ?? 0) + 1;
}

// 4) Traitement par tranche de job
$snapshotRows   = [];
$activityRows   = [];
$processedCount = 0;

foreach ($temps as $index => $r) {
    if ($index % $totalJobs !== $jobIndex) continue;

    $userId = $r['user_id'] ?? null;
    if (!$userId) continue;

    $processedCount++;

    // Somme de tous les niveaux
    $total = toInt($r['6eme']   ?? 0)
           + toInt($r['5eme']   ?? 0)
           + toInt($r['4eme']   ?? 0)
           + toInt($r['3eme']   ?? 0)
           + toInt($r['2nde']   ?? 0)
           + toInt($r['1ere']   ?? 0)
           + toInt($r['term']   ?? 0)
           + toInt($r['term-pc'] ?? 0);

    $streakJours   = $activeDaysByUser[$userId] ?? 0;
    $streakMoisPct = $daysInMonth > 0 ? (int)round(($streakJours / $daysInMonth) * 100) : 0;

    // Snapshot du jour
    $snapshotRows[] = [
        'user_id'             => $userId,
        'snapshot_date'       => $targetDate,
        'total_cumul_seconds' => $total,
        'streak_jours'        => $streakJours,
        'streak_mois_pct'     => $streakMoisPct,
    ];

    // Delta depuis le dernier snapshot
    $prevTotal = $prevByUser[$userId] ?? null;
    $delta     = ($prevTotal === null) ? 0 : max(0, $total - $prevTotal);

    // On enregistre la journée uniquement si delta ≥ seuil (10 min)
    if ($delta >= ACTIVE_THRESHOLD_SECONDS) {
        $activityRows[] = [
            'user_id'       => $userId,
            'activity_date' => $targetDate,
            'seconds_spent' => $delta,
        ];
    }
}

echo "[INFO] Job {$jobIndex}/{$totalJobs} traité {$processedCount} users\n";

// 5) Upsert par paquets
$chunkSize = 250;

echo "[INFO] Upsert daily_cumul_snapshot rows=" . count($snapshotRows) . "\n";
for ($i = 0; $i < count($snapshotRows); $i += $chunkSize) {
    sb_upsert('daily_cumul_snapshot', array_slice($snapshotRows, $i, $chunkSize));
}

echo "[INFO] Upsert daily_activity rows=" . count($activityRows) . "\n";
for ($i = 0; $i < count($activityRows); $i += $chunkSize) {
    sb_upsert('daily_activity', array_slice($activityRows, $i, $chunkSize));
}

echo "=== END sync_daily_activity Job {$jobIndex}/{$totalJobs} ===\n";
