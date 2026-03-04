<?php
// =============================================================================
// supabase.php — Client Supabase REST
// Utilise la service_key (accès complet, backend uniquement)
// =============================================================================

require_once __DIR__ . '/config.php';

class SupabaseClient
{
    private string $url;
    private string $key;
    private array  $headers;

    public function __construct()
    {
        $this->url  = rtrim(SUPABASE_URL, '/') . '/rest/v1';
        $this->key  = SUPABASE_SERVICE_KEY;
        $this->headers = [
            'apikey: '        . $this->key,
            'Authorization: Bearer ' . $this->key,
            'Content-Type: application/json',
            'Prefer: return=representation',
        ];
    }

    // ---------------------------------------------------------------
    // Configuration cURL commune
    // ---------------------------------------------------------------
    private function configureCurl($ch): void
    {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    // ---------------------------------------------------------------
    // SELECT — récupérer des lignes
    // ---------------------------------------------------------------
    public function select(string $table, string $select = '*', array $filters = [], array $options = []): array|false
    {
        $url = "{$this->url}/{$table}?select=" . rawurlencode($select);

        foreach ($filters as $key => $value) {
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
        }
        if (isset($options['limit']))  $url .= '&limit='  . (int)$options['limit'];
        if (isset($options['order']))  $url .= '&order='  . rawurlencode($options['order']);
        if (isset($options['offset'])) $url .= '&offset=' . (int)$options['offset'];

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("❌ Supabase select error ($httpCode) on $table: $response");
            return false;
        }

        return json_decode($response, true) ?? [];
    }

    // ---------------------------------------------------------------
    // SELECT ALL — pagination automatique
    // ---------------------------------------------------------------
    public function selectAll(string $table, string $select = '*', array $filters = [], ?string $orderBy = null): array
    {
        $all    = [];
        $offset = 0;
        $limit  = 1000;

        echo "📦 Récupération '{$table}'...\n";

        while (true) {
            $options = ['limit' => $limit, 'offset' => $offset];
            if ($orderBy) $options['order'] = $orderBy;

            $results = $this->select($table, $select, $filters, $options);

            if ($results === false || empty($results)) break;

            $all = array_merge($all, $results);
            echo "   • " . count($all) . " lignes...\r";

            if (count($results) < $limit) break;
            $offset += $limit;
        }

        echo "\n✅ Total : " . count($all) . " lignes de '{$table}'\n\n";
        return $all;
    }

    // ---------------------------------------------------------------
    // UPSERT — insérer ou mettre à jour
    // ---------------------------------------------------------------
    public function upsert(string $table, array $data, ?string $onConflict = null): array|false
    {
        $url = "{$this->url}/{$table}";

        $headers = $this->headers;
        // Retirer le Prefer par défaut et mettre le bon
        $headers = array_filter($headers, fn($h) => strpos($h, 'Prefer:') !== 0);
        $headers[] = 'Prefer: return=representation,resolution=merge-duplicates';

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values($headers));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || ($httpCode >= 400 && $httpCode !== 409)) {
            error_log("❌ Supabase upsert error ($httpCode) on $table: $response");
            return false;
        }

        return json_decode($response, true) ?? [];
    }

    // ---------------------------------------------------------------
    // BATCH UPSERT — insérer/mettre à jour plusieurs lignes
    // ---------------------------------------------------------------
    public function batchUpsert(string $table, array $rows): array|false
    {
        if (empty($rows)) return [];

        $url = "{$this->url}/{$table}";

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt_array($ch, [
            CURLOPT_POST        => true,
            CURLOPT_POSTFIELDS  => json_encode($rows),
            CURLOPT_HTTPHEADER  => [
                "apikey: {$this->key}",
                "Authorization: Bearer {$this->key}",
                "Content-Type: application/json",
                "Prefer: resolution=merge-duplicates",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true) ?? [];
        }

        error_log("❌ Supabase batchUpsert error ($httpCode) on $table: $response");
        return false;
    }

    // ---------------------------------------------------------------
    // UPDATE — mettre à jour avec filtre
    // ---------------------------------------------------------------
    public function update(string $table, array $data, array $filters = []): array|false
    {
        $url = "{$this->url}/{$table}?";
        foreach ($filters as $key => $value) {
            $url .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
        }
        $url = rtrim($url, '&?');

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log("❌ Supabase update error ($httpCode) on $table: $response");
            return false;
        }

        return json_decode($response, true) ?? [];
    }

    // ---------------------------------------------------------------
    // DELETE
    // ---------------------------------------------------------------
    public function delete(string $table, array $filters = []): bool
    {
        $url = "{$this->url}/{$table}?";
        foreach ($filters as $key => $value) {
            $url .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
        }
        $url = rtrim($url, '&?');

        $ch = curl_init($url);
        $this->configureCurl($ch);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return in_array($httpCode, [200, 204]);
    }
}
