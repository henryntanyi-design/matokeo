<?php
// backup.php - Run from project root: php backup.php
require __DIR__ . '/config.php';

$src = __DIR__;
$dest = 'D:/backup_matokeo_' . date('Ymd_His');
if (!is_dir($dest)) mkdir($dest, 0777, true);

// Recursive copy
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($it as $item) {
    $target = $dest . '/' . $it->getSubPathName();
    if ($item->isDir()) {
        if (!is_dir($target)) mkdir($target, 0777, true);
    } else {
        $dir = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        copy($item->getPathname(), $target);
    }
}

// DB dump
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sql = "-- DB backup " . DB_NAME . " " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_ASSOC);
        $sql .= "\nDROP TABLE IF EXISTS `$t`;\n" . $create['Create Table'] . ";\n";
        $rows = $pdo->query('SELECT * FROM `' . $t . '`');
        $cols = [];
        for ($i = 0; $i < $rows->columnCount(); $i++) {
            $meta = $rows->getColumnMeta($i);
            $cols[] = '`' . $meta['name'] . '`';
        }
        $colStr = implode(',', $cols);
        while ($r = $rows->fetch(PDO::FETCH_NUM)) {
            $vals = [];
            foreach ($r as $v) {
                if ($v === null) $vals[] = 'NULL';
                else $vals[] = $pdo->quote($v);
            }
            $sql .= "INSERT INTO `$t` ($colStr) VALUES (" . implode(',', $vals) . ");\n";
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($dest . '/db_dump.sql', $sql);
    echo "Backup created: $dest\n";
} catch (Throwable $e) {
    echo "File backup done, DB dump failed: " . $e->getMessage() . "\n";
}
