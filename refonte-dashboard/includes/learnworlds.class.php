<?php
// =============================================================================
// learnworlds.class.php — Client API LearnWorlds
// =============================================================================

require_once __DIR__ . '/../config/config.php';

class LearnWorlds
{
    private string $base_url;
    private string $api_token;
    private string $client_id;
    private int    $request_count    = 0;
    private int    $rate_limit       = 100;  // requêtes / 60s
    private int    $rate_window_start;

    public function __construct()
    {
        $this->base_url          = rtrim(getenv('LW_BASE_URL'), '/');
        $this->api_token         = getenv('LW_API_TOKEN');
        $this->client_id         = getenv('LW_CLIENT_ID');
        $this->rate_window_start = time();
    }

    // ---------------------------------------------------------------
    // Headers HTTP
    // ---------------------------------------------------------------
    private function getHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->api_token,
            'Lw-Client: '            . $this->client_id,
            'Accept: application/json',
            'Content-Type: application/json',
        ];
    }

    // ---------------------------------------------------------------
    // Configuration cURL
    // ---------------------------------------------------------------
    private function configureCurl($ch): void
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    // ---------------------------------------------------------------
    // Gestion rate limit (100 req / min)
    // ---------------------------------------------------------------
    private function checkRateLimit(): void
    {
        $now = time();

        if ($now - $this->rate_window_start >= 60) {
            $this->request_count     = 0;
            $this->rate_window_start = $now;
        }

        if ($this->request_count >= $this->rate_limit) {
            $sleep = 60 - ($now - $this->rate_window_start);
            if ($sleep > 0) {
                echo "⏳ Rate limit atteint, pause de {$sleep}s...\n";
                sleep($sleep);
                $this->request_count     = 0;
                $this->rate_window_start = time();
            }
        }

        $this->request_count++;
    }

    // ---------------------------------------------------------------
    // Requête générique
    // ---------------------------------------------------------------
    private function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null): array|false
    {
        $this->checkRateLimit();

        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);
        $this->configureCurl($ch);

        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST,       true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? []));
        } elseif ($method === 'PATCH' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS,    json_encode($data ?? []));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("❌ LW curl error on $endpoint: $curlErr");
            return false;
        }

        if ($httpCode === 429) {
            // Retry after 60s si rate limited par LearnWorlds
            echo "⏳ 429 Rate limited, pause 60s...\n";
            sleep(60);
            return $this->makeRequest($endpoint, $method, $data);
        }

        if ($httpCode >= 400) {
            error_log("❌ LW HTTP {$httpCode} on $endpoint: $response");
            return false;
        }

        return json_decode($response, true) ?? [];
    }

    // ---------------------------------------------------------------
    // Récupérer la liste des utilisateurs (paginée)
    // ---------------------------------------------------------------
    public function getUsers(int $page = 1, int $perPage = 100): array|false
    {
        return $this->makeRequest("/v2/users?page={$page}&itemsPerPage={$perPage}");
    }

    // ---------------------------------------------------------------
    // Récupérer les cours disponibles (paginés)
    // ---------------------------------------------------------------
    public function getCourses(int $page = 1, int $perPage = 100): array|false
    {
        return $this->makeRequest("/v2/courses?page={$page}&itemsPerPage={$perPage}");
    }

    // ---------------------------------------------------------------
    // Récupérer les utilisateurs inscrits à un cours
    // ---------------------------------------------------------------
    public function getCourseEnrollments(string $courseId, int $page = 1, int $perPage = 100): array|false
    {
        return $this->makeRequest("/v2/courses/{$courseId}/users?page={$page}&itemsPerPage={$perPage}");
    }

    // ---------------------------------------------------------------
    // Récupérer TOUS les user_id inscrits à au moins un cours
    // ---------------------------------------------------------------
    public function getAllEnrolledUserIds(): array
    {
        $enrolledIds = [];
        $coursePage  = 1;

        while (true) {
            $courses = $this->getCourses($coursePage, 100);

            if (empty($courses['data'])) break;

            logMessage("📚 " . count($courses['data']) . " cours (page {$coursePage})");

            foreach ($courses['data'] as $course) {
                $courseId = $course['id'];
                $userPage = 1;

                while (true) {
                    try {
                        $enrolled = $this->getCourseEnrollments($courseId, $userPage, 100);
                        if (empty($enrolled['data'])) break;

                        foreach ($enrolled['data'] as $enrollment) {
                            $userId = $enrollment['user_id'] ?? $enrollment['id'] ?? null;
                            if ($userId) $enrolledIds[] = $userId;
                        }

                        $userPage++;
                        if ($userPage > ($enrolled['meta']['totalPages'] ?? 1)) break;

                        usleep(100_000); // 0.1s entre pages
                    } catch (Exception $e) {
                        logMessage("⚠️ Erreur cours {$courseId}: " . $e->getMessage(), 'WARNING');
                        break;
                    }
                }
            }

            $coursePage++;
            if ($coursePage > ($courses['meta']['totalPages'] ?? 1)) break;

            usleep(200_000); // 0.2s entre pages de cours
        }

        $unique = array_unique($enrolledIds);
        logMessage("✅ " . count($unique) . " utilisateurs enrolled uniques");
        return $unique;
    }

    // ---------------------------------------------------------------
    // Récupérer la progression d'un cours pour un user
    // ---------------------------------------------------------------
    public function getUserCourseProgress(string $userId, string $courseId): array|false
    {
        return $this->makeRequest("/v2/users/{$userId}/courses/{$courseId}/progress");
    }

    // ---------------------------------------------------------------
    // Récupérer les cours d'un user avec le temps passé
    // ---------------------------------------------------------------
    public function getUserCourses(string $userId): array|false
    {
        return $this->makeRequest("/v2/users/{$userId}/courses");
    }

    // ---------------------------------------------------------------
    // Calculer le temps total par niveau pour un user
    // (somme des secondes par cours, mappé sur les niveaux)
    // ---------------------------------------------------------------
    public function getUserTimeByLevel(string $userId): array
    {
        $result   = array_fill_keys(NIVEAUX, 0);
        $response = $this->getUserCourses($userId);

        logMessage("RAW courses pour {$userId}:" .json_encode($response));

        if (!$response || empty($response['data'])) return $result;

        foreach ($response['data'] as $course) {
            $courseId = $course['id'] ?? '';
            $time     = (int)($course['time_spent'] ?? $course['total_seconds'] ?? 0);

            if ($time <= 0) continue;

            // Mapping du cours vers le niveau
            foreach (COURSE_MAPPING as $pattern => $niveau) {
                if (strpos($courseId, $pattern) !== false) {
                    $result[$niveau] += $time;
                    break;
                }
            }
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Calculer le % de progression par niveau pour un user
    // ---------------------------------------------------------------
    public function getUserProgressionByLevel(string $userId): array
    {
        $result = array_fill_keys(NIVEAUX, 0);

        foreach (NIVEAUX as $niveau) {
            // Trouver le cours principal pour ce niveau
            $mainCourseId = null;
            foreach (COURSE_MAPPING as $courseId => $mappedNiveau) {
                if ($mappedNiveau === $niveau && strpos($courseId, 'maths-') === 0) {
                    $mainCourseId = $courseId;
                    break;
                }
            }

            if (!$mainCourseId) continue;

            try {
                $progress = $this->getUserCourseProgress($userId, $mainCourseId);

                if (!$progress) continue;

                if (isset($progress['progress_rate'])) {
                    $result[$niveau] = (int)round($progress['progress_rate']);
                } elseif (isset($progress['completed_units'], $progress['total_units'])
                          && $progress['total_units'] > 0) {
                    $result[$niveau] = (int)round(
                        ($progress['completed_units'] / $progress['total_units']) * 100
                    );
                }
            } catch (Exception $e) {
                // Cours non inscrit, on laisse à 0
            }
        }

        return $result;
    }
}
