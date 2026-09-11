<?php

declare(strict_types=1);

/**
 * Tracks OpenCart <-> Exact Online sync mappings in a dedicated table
 * (oc_exact_sync_map) so we never touch native OpenCart tables and never
 * create duplicate Exact records for the same local entity.
 */
class SyncStore
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->table = $prefix . 'exact_sync_map';
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id` int NOT NULL AUTO_INCREMENT,
                `entity_type` varchar(20) NOT NULL,
                `local_id` int NOT NULL,
                `exact_id` varchar(64) DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `message` text,
                `synced_at` datetime DEFAULT NULL,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entity_local` (`entity_type`, `local_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function find(string $entityType, int $localId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `{$this->table}` WHERE entity_type = :type AND local_id = :id LIMIT 1");
        $stmt->execute(['type' => $entityType, 'id' => $localId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function isSynced(string $entityType, int $localId): bool
    {
        $row = $this->find($entityType, $localId);
        return $row !== null && $row['status'] === 'ok';
    }

    public function upsert(string $entityType, int $localId, ?string $exactId, string $status, string $message = ''): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO `{$this->table}` (entity_type, local_id, exact_id, status, message, synced_at, updated_at)
            VALUES (:type, :id, :exact_id, :status, :message, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                exact_id = VALUES(exact_id),
                status = VALUES(status),
                message = VALUES(message),
                synced_at = IF(VALUES(status) = 'ok', NOW(), synced_at),
                updated_at = NOW()
        ");
        $stmt->execute([
            'type' => $entityType,
            'id' => $localId,
            'exact_id' => $exactId,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
