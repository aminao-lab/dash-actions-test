<?php
// =============================================================================
// functions.php — Fonctions utilitaires partagées
// =============================================================================

/**
 * Logger avec timestamp → stdout + fichier log
 */
function logMessage(string $message, string $level = 'INFO'): void
{
    $timestamp  = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

    echo $logMessage;

    $logFile = __DIR__ . '/../logs/sync_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Formater un timestamp Unix → date SQL
 */
function formatTimestamp(?int $timestamp): ?string
{
    if (!$timestamp) return null;
    return date('Y-m-d H:i:s', $timestamp);
}

/**
 * Obtenir la semaine ISO (format "2025-W01")
 */
function getISOWeek(?DateTime $date = null): string
{
    $date = $date ?? new DateTime();
    return $date->format('o-\WW');
}

/**
 * Obtenir le lundi et dimanche d'une semaine ISO
 */
function getWeekRange(string $isoWeek): array
{
    [$year, $week] = explode('-W', $isoWeek);

    $dto = new DateTime();
    $dto->setISODate((int)$year, (int)$week);
    $monday = $dto->format('Y-m-d');

    $dto->modify('+6 days');
    $sunday = $dto->format('Y-m-d');

    return ['monday' => $monday, 'sunday' => $sunday];
}

/**
 * Formater des secondes en format humain
 */
function formatSeconds(int $seconds): string
{
    if ($seconds < 60)   return "{$seconds}s";
    if ($seconds < 3600) return floor($seconds / 60) . 'min';

    $h   = floor($seconds / 3600);
    $min = floor(($seconds % 3600) / 60);
    return $min > 0 ? "{$h}h{$min}" : "{$h}h";
}

/**
 * Lire la progression du batch (fichier texte)
 */
function getBatchProgress(string $key): int
{
    $file = __DIR__ . '/../logs/batch_' . $key . '.txt';
    return file_exists($file) ? (int)file_get_contents($file) : 0;
}

function setBatchProgress(string $key, int $value): void
{
    file_put_contents(__DIR__ . '/../logs/batch_' . $key . '.txt', $value);
}

function clearBatchProgress(string $key): void
{
    $file = __DIR__ . '/../logs/batch_' . $key . '.txt';
    if (file_exists($file)) unlink($file);
}
