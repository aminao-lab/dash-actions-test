<?php
// =============================================================================
// sync_progression.php — Synchronise le % de progression par niveau
// CORRECTIONS :
//   • getenv('TOTAL_JOBS') au lieu de $_ENV['JOB_COUNT']
//   • $jobStartTime cohérent en début et fin
//   • $processedCount bien incrémenté
// =============================================================================

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

set_time_limit(1800);
ini_set('memory_limit', '512M');

$jobStartTime = microtime(true);

// ✅ CORRIGÉ : getenv() fonctionne en CLI et GitHub Actions
$jobIndex  = (int)(getenv('JOB_INDEX')  ?: 0);
$totalJobs = (int)(getenv('TOTAL_JOBS') ?: 1);

logMessage("=== DÉBUT SYNC PROGRESSION Job {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw       = new LearnWorlds();

// ─── Récupérer tous les étudiants ──────────────────────────────────────────
$students = $supabase->selectAll('students', 'user_id,email', [], 'user_id.asc');

if (!$students || count($students) === 0) {
    logMessage("❌ Aucun élève trouvé", 'ERROR');
    exit(1);
}

$totalStudents  = count($students);
$studentsPerJob = (int)ceil($totalStudents / $totalJobs);
$startIdx       = $jobIndex * $studentsPerJob;
$endIdx         = min($startIdx + $studentsPerJob, $totalStudents);

logMessage("📊 Total élèves : {$totalStudents}");
logMessage("🔀 Job {$jobIndex}/{$totalJobs} → range [{$startIdx}..{$endIdx}[");

// ─── Traitement de la tranche ───────────────────────────────────────────────
$processedCount = 0;
$errorCount     = 0;

for ($i = $startIdx; $i < $endIdx; $i++) {
    $student = $students[$i];
    $userId  = $student['user_id'];
    $email   = $student['email'] ?? 'N/A';
    $num     = $i + 1;

    try {
        logMessage("⏳ [{$num}/{$totalStudents}] {$email}...");

        $progressData = $lw->getUserProgressionByLevel($userId);

        $data = [
            'user_id'  => $userId,
            '6eme'     => $progressData['6eme']    ?? 0,
            '5eme'     => $progressData['5eme']    ?? 0,
            '4eme'     => $progressData['4eme']    ?? 0,
            '3eme'     => $progressData['3eme']    ?? 0,
            '2nde'     => $progressData['2nde']    ?? 0,
            '1ere'     => $progressData['1ere']    ?? 0,
            'term'     => $progressData['term']    ?? 0,
            'term-pc'  => $progressData['term-pc'] ?? 0,
        ];

        $result = $supabase->upsert('progression', $data);

        if ($result === false) {
            logMessage("⚠️ Erreur upsert {$userId}", 'WARNING');
            $errorCount++;
        } else {
            // ✅ CORRIGÉ : $processedCount bien incrémenté
            $processedCount++;

            // Afficher seulement les niveaux avec une progression
            $summary = [];
            foreach (NIVEAUX as $niveau) {
                if (($progressData[$niveau] ?? 0) > 0) {
                    $summary[] = "{$niveau}:{$progressData[$niveau]}%";
                }
            }
            if (!empty($summary)) {
                logMessage("✅ {$email}: " . implode(', ', $summary));
            }
        }

    } catch (Exception $e) {
        logMessage("❌ Erreur {$userId}: " . $e->getMessage(), 'ERROR');
        $errorCount++;
    }
}

// ✅ CORRIGÉ : utilise $jobStartTime (défini en haut)
$jobElapsed = round(microtime(true) - $jobStartTime, 2);

logMessage("📈 Job {$jobIndex} FIN :");
logMessage("   • Traités  : {$processedCount}");
logMessage("   • Erreurs  : {$errorCount}");
logMessage("   • Durée    : {$jobElapsed}s");
logMessage("=== FIN SYNC PROGRESSION Job {$jobIndex}/{$totalJobs} ===\n");

echo "[INFO] Job {$jobIndex}/{$totalJobs} processed {$processedCount} users in {$jobElapsed}s\n";
