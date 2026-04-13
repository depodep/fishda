<?php
require_once __DIR__ . '/dbcon.php';

function out(string $message): void {
    echo $message . PHP_EOL;
}

try {
    $dbh->beginTransaction();

    out('Seeding canonical device models...');
    $seedCanonical = $dbh->prepare(
        "INSERT IGNORE INTO tbl_prototypes (model_name, given_code, description, owner_name, status)
         VALUES (:model_name, :given_code, :description, :owner_name, :status)"
    );
    foreach ([
        ['Fishda', 'FD2026', 'Temporary prototype model for testing', 'Temporary Seed', 1],
        ['Aquadry', 'AQ2026', 'Temporary solar drying model', 'Temporary Seed', 1],
        ['HeatBot', 'HB2026', 'Temporary heat chamber model', 'Temporary Seed', 1],
    ] as [$modelName, $givenCode, $description, $ownerName, $status]) {
        $seedCanonical->execute([
            ':model_name' => $modelName,
            ':given_code' => $givenCode,
            ':description' => $description,
            ':owner_name' => $ownerName,
            ':status' => $status,
        ]);
    }

    out('Seeding legacy models from historical batch labels...');
    $seedLegacy = $dbh->prepare(
        "INSERT IGNORE INTO tbl_prototypes (model_name, given_code, description, owner_name, status)
         SELECT DISTINCT
                dr.batch_id AS model_name,
                CONCAT('LEGACY-', LPAD(ABS(MOD(CRC32(dr.batch_id), 999999)), 6, '0')) AS given_code,
                'Imported from historical drying record label' AS description,
                'Legacy Seed' AS owner_name,
                1 AS status
         FROM drying_records dr
         WHERE dr.batch_id IS NOT NULL
           AND dr.batch_id <> ''
           AND dr.batch_id NOT REGEXP '^[0-9]+$'
           AND dr.batch_id <> 'Manual Batch'"
    );
    $seedLegacy->execute();

    out('Backfilling session proto_id values...');
    $updateSessions = $dbh->prepare(
        "UPDATE drying_sessions ds
         JOIN (
           SELECT dr.session_id, MIN(p.id) AS proto_id
           FROM drying_records dr
           JOIN tbl_prototypes p ON p.model_name COLLATE utf8mb4_general_ci = dr.batch_id COLLATE utf8mb4_general_ci
           WHERE dr.session_id IS NOT NULL
             AND dr.batch_id IS NOT NULL
             AND dr.batch_id <> ''
           GROUP BY dr.session_id
         ) map ON map.session_id = ds.session_id
         SET ds.proto_id = map.proto_id
         WHERE ds.proto_id IS NULL OR ds.proto_id = 0"
    );
    $updateSessions->execute();

    out('Converting drying_record batch labels to prototype IDs...');
    $updateRecords = $dbh->prepare(
        "UPDATE drying_records dr
         JOIN drying_sessions ds ON ds.session_id = dr.session_id
         SET dr.batch_id = CAST(ds.proto_id AS CHAR)
         WHERE ds.proto_id IS NOT NULL AND ds.proto_id > 0"
    );
    $updateRecords->execute();

    $dbh->commit();
    out('Backfill complete.');
    out('Sessions updated: ' . $updateSessions->rowCount());
    out('Records updated: ' . $updateRecords->rowCount());
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    out('Backfill failed: ' . $e->getMessage());
    exit(1);
}
