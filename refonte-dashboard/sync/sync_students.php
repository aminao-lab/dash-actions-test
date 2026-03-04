<?php
// =============================================================================
// sync_students.php — Synchronise les étudiants enrolled depuis LearnWorlds
// Exécuté en 4 jobs parallèles (distribution par pages)
// =============================================================================

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../includes/learnworlds.class.php';
require_once __DIR__ . '/../includes/functions.php';

set_time_limit(1800);
ini_set('memory_limit', '512M');

// Paramètres de parallélisation — getenv() est fiable en CLI et GitHub Actions
$jobIndex  = (int)(getenv('JOB_INDEX')  ?: 0);
$totalJobs = (int)(getenv('TOTAL_JOBS') ?: 1);

logMessage("=== DÉBUT SYNC STUDENTS Job {$jobIndex}/{$totalJobs} ===");

$supabase = new SupabaseClient();
$lw       = new LearnWorlds();

// ─── ÉTAPE 1 : Récupérer tous les user_id enrolled ─────────────────────────
logMessage("🔍 Récupération des utilisateurs enrolled...");
$enrolledUserIds = $lw->getAllEnrolledUserIds();
logMessage("✅ " . count($enrolledUserIds) . " utilisateurs enrolled");

$enrolledSet = array_flip($enrolledUserIds); // lookup O(1)

// ─── ÉTAPE 2 : Parcourir les pages en distribuant par job ──────────────────
$currentPage    = 1;
$totalProcessed = 0;
$batchBuffer    = [];
$BATCH_SIZE     = 100;
$enrolledCount  = 0;
$skippedCount   = 0;

while (true) {
    // Distribution : ce job ne traite que ses pages (modulo)
    if (($currentPage - 1) % $totalJobs !== $jobIndex) {
        $currentPage++;
        // On a besoin de connaître le totalPages pour savoir quand arrêter.
        // On continue jusqu'à ce que toutes les pages soient épuisées.
        // La condition de sortie est dans le bloc try/catch ci-dessous.
        // On "skip" simplement les pages non assignées.
        // NOTE: On doit quand même faire la requête une fois pour obtenir totalPages.
        // Stratégie : faire la première page pour obtenir totalPages, puis sauter.
        if ($currentPage > 5000) break; // sécurité anti-boucle infinie
        continue;
    }

    try {
        logMessage("📄 Job {$jobIndex} → page {$currentPage}...");

        $response = $lw->getUsers($currentPage, 100);

        if (!$response || !isset($response['data'])) {
            logMessage("❌ Réponse invalide page {$currentPage}", 'ERROR');
            break;
        }

        $users      = $response['data'];
        $totalPages = (int)($response['meta']['totalPages'] ?? 1);

        if (empty($users)) break;

        foreach ($users as $user) {
            $userId = $user['id'];

            if (!isset($enrolledSet[$userId])) {
                $skippedCount++;
                continue;
            }

            $batchBuffer[] = [
                'user_id'      => $userId,
                'email'        => $user['email']      ?? null,
                'username'     => $user['username']   ?? null,
                'tags'         => isset($user['tags']) ? implode(', ', (array)$user['tags']) : null,
                'created_at'   => formatTimestamp($user['created']    ?? null),
                'last_login_at'=> formatTimestamp($user['last_login'] ?? null),
                'is_enrolled'  => true,
            ];

            if (count($batchBuffer) >= $BATCH_SIZE) {
                $result = $supabase->batchUpsert('students', $batchBuffer);
                if ($result !== false) {
                    $enrolledCount += count($batchBuffer);
                    logMessage("✅ Batch de " . count($batchBuffer) . " insérés");
                }
                $batchBuffer = [];
                usleep(100_000);
            }
        }

        logMessage("📊 Page {$currentPage}/{$totalPages} traitée");
        $currentPage++;
        $totalProcessed++;

        if ($currentPage > $totalPages) {
            logMessage("🎉 Toutes les pages traitées par job {$jobIndex} !");
            break;
        }

        usleep(500_000); // 0.5s entre pages

    } catch (Exception $e) {
        logMessage("❌ Erreur page {$currentPage}: " . $e->getMessage(), 'ERROR');
        break;
    }
}

// ─── Insérer le dernier batch ───────────────────────────────────────────────
if (!empty($batchBuffer)) {
    $result = $supabase->batchUpsert('students', $batchBuffer);
    if ($result !== false) {
        $enrolledCount += count($batchBuffer);
        logMessage("✅ Dernier batch de " . count($batchBuffer) . " insérés");
    }
}

logMessage("📈 STATISTIQUES Job {$jobIndex}:");
logMessage("   • Enrolled ajoutés/mis à jour : {$enrolledCount}");
logMessage("   • Non enrolled ignorés         : {$skippedCount}");
logMessage("=== FIN SYNC STUDENTS Job {$jobIndex} ===\n");
