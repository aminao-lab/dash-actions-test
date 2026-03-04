<?php
// =============================================================================
// sync_temps_week.php — Synchronise le temps hebdomadaire par niveau
// =============================================================================

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

set_time_limit(1800);
ini_set('memory_limit', '512M');

$jobStartTime = microtime(true);
$jobIndex     = (int)(getenv('JOB_INDEX')  ?: 0);
$totalJobs    = (int)(getenv('TOTAL_JOBS') ?: 1);

logMessage("=== DÉBUT SYNC TEMPS_WEEK Job {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw       = new LearnWorlds();

// ─── Semaine cible : la semaine dernière ────────────────────────────────────
$now = new DateTime('now', new DateTimeZone('Europe/Paris'));
$now->modify('last monday');      // lundi de la semaine en cours
$now->modify('-7 days');          // recule d'une semaine → semaine passée

$isoWeek  = getISOWeek($now);
$weekRange = getWeekRange($isoWeek);

logMessage("📅 Semaine : {$isoWeek}");
logMessage("📅 Période : {$weekRange['monday']} → {$weekRange['sunday']}");

// ─── Récupérer tous les étudiants ──────────────────────────────────────────
$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents = count($students);
logMessage("📊 Total élèves : {$totalStudents}");

// ─── Traitement par tranche ─────────────────────────────────────────────────
$batchBuffer    = [];
$BATCH_SIZE     = 50;
$processedCount = 0;
$errorCount     = 0;

for ($i = 0; $i < $totalStudents; $i++) {
    if ($i % $totalJobs !== $jobIndex) continue;

    $student = $students[$i];
    $userId  = $student['user_id'];
    $email   = $student['email'] ?? 'N/A';
    $num     = $i + 1;

    try {
        logMessage("⏳ [{$num}/{$totalStudents}] {$email}...");

        $timeData = $lw->getUserTimeByLevel($userId);

        $batchBuffer[] = [
            'user_id'  => $userId,
            'semaine'  => $isoWeek,
            '6eme'     => $timeData['6eme']    ?? 0,
            '5eme'     => $timeData['5eme']    ?? 0,
            '4eme'     => $timeData['4eme']    ?? 0,
            '3eme'     => $timeData['3eme']    ?? 0,
            '2nde'     => $timeData['2nde']    ?? 0,
            '1ere'     => $timeData['1ere']    ?? 0,
            'term'     => $timeData['term']    ?? 0,
            'term-pc'  => $timeData['term-pc'] ?? 0,
            'debute_le' => $weekRange['monday'],
            'finit_le'  => $weekRange['sunday'],
        ];

        $processedCount++;

        if (count($batchBuffer) >= $BATCH_SIZE) {
            $result = $supabase->batchUpsert('temps_week', $batchBuffer);
            if ($result !== false) {
                logMessage("✅ Batch de " . count($batchBuffer) . " insérés");
            } else {
                logMessage("⚠️ Erreur batch", 'WARNING');
                $errorCount++;
            }
            $batchBuffer = [];
            usleep(200_000);
        }

    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
        $errorCount++;
    }
}

// ─── Dernier batch ──────────────────────────────────────────────────────────
if (!empty($batchBuffer)) {
    $result = $supabase->batchUpsert('temps_week', $batchBuffer);
    if ($result !== false) {
        logMessage("✅ Dernier batch de " . count($batchBuffer) . " insérés");
    }
}

$elapsed = round(microtime(true) - $jobStartTime, 2);

logMessage("📈 Job {$jobIndex} FIN :");
logMessage("   • Traités : {$processedCount}");
logMessage("   • Erreurs : {$errorCount}");
logMessage("   • Durée   : {$elapsed}s");
logMessage("=== FIN SYNC TEMPS_WEEK Job {$jobIndex}/{$totalJobs} ===\n");
