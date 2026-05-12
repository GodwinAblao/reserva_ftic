<?php
// Run this file directly in browser: http://localhost/reserva_ftic/scripts/maintenance/facilities/fix_facility_table.php

$host = '127.0.0.1';
$dbname = 'reserva_ftic';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if parent_id column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM facility LIKE 'parent_id'");
    if ($stmt->rowCount() === 0) {
        echo "Adding parent_id column...<br>";
        $pdo->exec("ALTER TABLE facility ADD COLUMN parent_id INT NULL AFTER description");
        $pdo->exec("ALTER TABLE facility ADD CONSTRAINT fk_facility_parent FOREIGN KEY (parent_id) REFERENCES facility(id) ON DELETE CASCADE");
        echo "✓ parent_id column added!<br><br>";
    } else {
        echo "✓ parent_id column already exists<br><br>";
    }

    // Check and add new facilities
    $newFacilities = [
        ['3D Printing Lab', 20, '3D Printing Laboratory'],
        ['Lounge 1', 30, 'Lounge Area Section 1'],
        ['Lounge 2', 30, 'Lounge Area Section 2'],
        ['Lounge 3', 30, 'Lounge Area Section 3'],
        ['Lounge 4', 30, 'Lounge Area Section 4'],
    ];

    $added = 0;
    foreach ($newFacilities as [$name, $capacity, $desc]) {
        $stmt = $pdo->prepare("SELECT id FROM facility WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->rowCount() === 0) {
            $pdo->prepare("INSERT INTO facility (name, capacity, description, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")
                ->execute([$name, $capacity, $desc]);
            echo "✓ Added facility: $name<br>";
            $added++;
        } else {
            echo "• Facility already exists: $name<br>";
        }
    }

    // Set up parent-child relationship for Lounge Area
    $stmt = $pdo->query("SELECT id FROM facility WHERE name = 'Lounge Area'");
    $loungeArea = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($loungeArea) {
        $loungeAreaId = $loungeArea['id'];
        $stmt = $pdo->prepare("UPDATE facility SET parent_id = ? WHERE name IN ('Lounge 1', 'Lounge 2', 'Lounge 3', 'Lounge 4') AND (parent_id IS NULL OR parent_id != ?)");
        $stmt->execute([$loungeAreaId, $loungeAreaId]);
        $updated = $stmt->rowCount();
        if ($updated > 0) {
            echo "<br>✓ Set Lounge 1-4 as children of Lounge Area ($updated updated)<br>";
        }
    }

    echo "<br><strong>Done! Added $added new facilities.</strong><br>";
    echo "<a href='/super-admin/calendar'>Go to Calendar</a> | <a href='/facility/management'>Go to Facility Management</a>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
