<?php
/**
 * Cross-Database Lookup Tool (PHP 7.4)
 *
 * Reads rows from db1.table1, looks up each email_address in db2.table2.
 *   - Found:     updates the `active` column and displays the record.
 *   - Not found: inserts into table2, table3 (2 group rows), and table4.
 *
 * Usage:
 *   php cross_db_lookup.php              # live mode  - executes SQL
 *   php cross_db_lookup.php --test       # test mode  - prints SQL only
 *   php cross_db_lookup.php --test --verbose
 */

// ── Configuration ───────────────────────────────────────────────────────────

$configFile = file_exists(__DIR__ . '/config.local.php')
    ? __DIR__ . '/config.local.php'
    : __DIR__ . '/config.php';

$config = require $configFile;

// ── CLI flags ───────────────────────────────────────────────────────────────

$options  = getopt('', ['test', 'verbose']);
$testMode = isset($options['test']);
$verbose  = isset($options['verbose']);

if ($testMode) {
    echo "=== TEST MODE — no SQL will be executed ===\n\n";
}

// ── Helper: build a PDO connection ──────────────────────────────────────────

function connectDb(array $cfg)
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

// ── Helper: display a row ───────────────────────────────────────────────────

function displayRow(array $row)
{
    printf(
        "  email_address: %s | name: %s | user_name: %s\n",
        $row['email_address'],
        $row['name'],
        $row['user_name']
    );
}

// ── Helper: format a value for SQL display in test mode ─────────────────────

function sqlQuote($value)
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . addslashes((string) $value) . "'";
}

// ── Main logic ──────────────────────────────────────────────────────────────

try {
    // 1. Connect to databases (skip connections in test mode if desired,
    //    but we still need db1 to read rows unless we mock data).
    if (!$testMode) {
        $db1 = connectDb($config['db1']);
        $db2 = connectDb($config['db2']);
    } else {
        // In test mode we still connect to db1 to read source rows.
        // If you want a fully offline test, replace this block with sample data.
        $db1 = connectDb($config['db1']);
        $db2 = connectDb($config['db2']);
    }

    // 2. Select subset of rows from db1.table1
    $query    = $config['table1_query'];
    $stmtSrc  = $db1->query($query);
    $srcRows  = $stmtSrc->fetchAll();

    $totalRows = count($srcRows);
    echo "Fetched {$totalRows} row(s) from db1.table1\n\n";

    $updatedCount  = 0;
    $insertedCount = 0;

    foreach ($srcRows as $row) {
        $email    = $row['email_address'];
        $name     = $row['name'];
        $userName = $row['user_name'];

        // 3. Look up email_address in db2.table2
        $lookupSql = "SELECT * FROM table2 WHERE email_address = ?";
        $stmtLookup = $db2->prepare($lookupSql);
        $stmtLookup->execute([$email]);
        $existing = $stmtLookup->fetch();

        if ($existing) {
            // ── FOUND: update `active` column ───────────────────────────
            $updateSql = "UPDATE table2 SET active = 1 WHERE email_address = ?";

            if ($testMode) {
                $displaySql = "UPDATE table2 SET active = 1 WHERE email_address = " . sqlQuote($email);
                echo "[TEST] {$displaySql};\n";
            } else {
                $stmtUpdate = $db2->prepare($updateSql);
                $stmtUpdate->execute([$email]);
            }

            echo "[UPDATED] ";
            displayRow($row);
            $updatedCount++;

        } else {
            // ── NOT FOUND: insert into table2, table3, table4 ───────────

            // -- table2 insert --
            $insertTable2Sql = "INSERT INTO table2 (email_address, name, user_name) VALUES (?, ?, ?)";

            if ($testMode) {
                $displaySql = sprintf(
                    "INSERT INTO table2 (email_address, name, user_name) VALUES (%s, %s, %s)",
                    sqlQuote($email),
                    sqlQuote($name),
                    sqlQuote($userName)
                );
                echo "[TEST] {$displaySql};\n";

                // Use a placeholder ID for subsequent test-mode statements
                $newUserId = '<LAST_INSERT_ID>';
            } else {
                $stmtIns2 = $db2->prepare($insertTable2Sql);
                $stmtIns2->execute([$email, $name, $userName]);
                $newUserId = $db2->lastInsertId();
            }

            // -- table3 inserts (2 rows — one per group) --
            $groupIds = $config['default_group_ids'];
            foreach ($groupIds as $groupId) {
                $insertTable3Sql = "INSERT INTO table3 (userID, groupID) VALUES (?, ?)";

                if ($testMode) {
                    $displaySql = sprintf(
                        "INSERT INTO table3 (userID, groupID) VALUES (%s, %s)",
                        sqlQuote($newUserId),
                        sqlQuote($groupId)
                    );
                    echo "[TEST] {$displaySql};\n";
                } else {
                    $stmtIns3 = $db2->prepare($insertTable3Sql);
                    $stmtIns3->execute([$newUserId, $groupId]);
                }
            }

            // -- table4 insert --
            $t4Defaults = $config['table4_defaults'];
            $t4Columns  = array_merge(['userID'], array_keys($t4Defaults));
            $t4Values   = array_merge([$newUserId], array_values($t4Defaults));
            $t4Placeholders = implode(', ', array_fill(0, count($t4Values), '?'));
            $t4ColumnList   = implode(', ', $t4Columns);

            $insertTable4Sql = "INSERT INTO table4 ({$t4ColumnList}) VALUES ({$t4Placeholders})";

            if ($testMode) {
                $displayParts = [];
                foreach ($t4Values as $v) {
                    $displayParts[] = sqlQuote($v);
                }
                $displaySql = sprintf(
                    "INSERT INTO table4 (%s) VALUES (%s)",
                    $t4ColumnList,
                    implode(', ', $displayParts)
                );
                echo "[TEST] {$displaySql};\n";
            } else {
                $stmtIns4 = $db2->prepare($insertTable4Sql);
                $stmtIns4->execute($t4Values);
            }

            echo "[INSERTED] ";
            displayRow($row);
            $insertedCount++;
        }

        echo "\n";
    }

    // ── Summary ─────────────────────────────────────────────────────────────
    echo "--- Summary ---\n";
    echo "Total rows processed : {$totalRows}\n";
    echo "Updated in table2    : {$updatedCount}\n";
    echo "Inserted (new users) : {$insertedCount}\n";

    if ($testMode) {
        echo "\n=== TEST MODE complete — no changes were made ===\n";
    }

} catch (PDOException $e) {
    fprintf(STDERR, "Database error: %s\n", $e->getMessage());
    exit(1);
} catch (Exception $e) {
    fprintf(STDERR, "Error: %s\n", $e->getMessage());
    exit(1);
}
