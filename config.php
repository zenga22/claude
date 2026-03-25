<?php
/**
 * Database and application configuration.
 *
 * Copy this file to config.local.php and update with your actual credentials.
 * config.local.php is loaded automatically and takes precedence.
 */

return [
    // Database 1 - source database
    'db1' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'db1',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // Database 2 - target database
    'db2' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'db2',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // Query to select the subset of rows from db1.table1
    // Adjust the WHERE clause to match your criteria
    'table1_query' => 'SELECT email_address, name, user_name FROM table1 WHERE active = 1',

    // Default group IDs to assign new users to in table3
    'default_group_ids' => [1, 2],

    // Default column values for table4 insert
    'table4_defaults' => [
        'role'   => 'member',
        'status' => 'active',
    ],
];
