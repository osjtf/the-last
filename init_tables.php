<?php
/**
 * سكريبت متكامل لإعادة تهيئة جميع الجداول من الصفر:
 * - يحذف كل الجداول الموجودة ديناميكياً
 * - يعيد إنشاء الجداول المطلوبة (patients, doctors, admins, sick_leaves, leave_queries)
 * - يضيف مشرفاً افتراضياً
 */

$dbConfigs = [
    [
        'host' => 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'user' => 'smrg7ak77778emkb',
        'pass' => 'fw69cuijof4ahuhb',
        'name' => 'ygscnjzq8ueid5yz',
        'port' => 3306,
    ],
    [
        'host' => 'c9cujduvu830eexs.cbetxkdyhwsb.us-east-1.rds.amazonaws.com',
        'user' => 'q2xjpqcepsmd4v12',
        'pass' => 'v8lcs6awp4vj9u28',
        'name' => 'cdidptf4q81rafg8',
        'port' => 3306,
    ],
];

// بيانات المشرف الافتراضي
$defaultAdminUsername      = '0s1_m1';
$defaultAdminPasswordPlain = '0s1_m1_2023';

function resetAndCreateTables(array $config, string $adminUsername, string $adminPasswordPlain)
{
    $dbName = $config['name'];
    echo "<h3>► معالجة قاعدة البيانات: <code>{$dbName}</code></h3>";

    // إنشاء الاتصال
    $mysqli = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $dbName,
        $config['port']
    );
    if ($mysqli->connect_errno) {
        echo "<p style='color:red;'>خطأ اتصال بقاعدة <strong>{$dbName}</strong>: " . htmlspecialchars($mysqli->connect_error) . "</p><hr>";
        return;
    }
    $mysqli->set_charset('utf8mb4');

    // 1. حذف كل الجداول الموجودة ديناميكياً
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
    $res = $mysqli->query("SHOW TABLES");
    while ($row = $res->fetch_array()) {
        $tbl = $row[0];
        $mysqli->query("DROP TABLE IF EXISTS `{$tbl}`");
    }
    $mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

    // 2. إنشاء جدول المرضى
    $mysqli->query(<<<'SQL'
CREATE TABLE `patients` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(100) NOT NULL,
  `identity_number` VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    // 3. إنشاء جدول الأطباء
    $mysqli->query(<<<'SQL'
CREATE TABLE `doctors` (
  `id`    INT AUTO_INCREMENT PRIMARY KEY,
  `name`  VARCHAR(100) NOT NULL,
  `title` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    // 4. إنشاء جدول المشرفين (admins)
    $mysqli->query(<<<'SQL'
CREATE TABLE `admins` (
  `id`       INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    // 5. إنشاء جدول الإجازات المرضية
    $mysqli->query(<<<'SQL'
CREATE TABLE `sick_leaves` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `service_code`   VARCHAR(30) NOT NULL UNIQUE,
  `patient_id`     INT NOT NULL,
  `doctor_id`      INT NOT NULL,
  `issue_date`     DATE NOT NULL,
  `start_date`     DATE NOT NULL,
  `end_date`       DATE NOT NULL,
  `days_count`     INT NOT NULL,
  `is_companion`   TINYINT(1) NOT NULL DEFAULT 0,
  `companion_name` VARCHAR(100) DEFAULT NULL,
  `companion_relation` VARCHAR(100) DEFAULT NULL,
  `is_deleted`     TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_at`     DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`doctor_id`)  REFERENCES `doctors`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    // 6. إنشاء سجل الاستعلامات
    $mysqli->query(<<<'SQL'
CREATE TABLE `leave_queries` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `leave_id`   INT NOT NULL,
  `queried_at` DATETIME NOT NULL,
  `source`     VARCHAR(20) NOT NULL DEFAULT 'external',
  INDEX (`leave_id`),
  CONSTRAINT `fk_leave_queries_leave`
    FOREIGN KEY (`leave_id`) REFERENCES `sick_leaves`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    // 7. إضافة المشرف الافتراضي
    $hashed = password_hash($adminPasswordPlain, PASSWORD_DEFAULT);
    $stmt   = $mysqli->prepare("INSERT INTO `admins` (`username`,`password`) VALUES (?,?)");
    $stmt->bind_param("ss", $adminUsername, $hashed);
    $stmt->execute();
    $stmt->close();

    echo "<p style='color:green;'>✔ تم إعادة إنشاء جميع الجداول وإضافة المشرف <strong>{$adminUsername}</strong> بنجاح.</p><hr>";
    $mysqli->close();
}

// تنفيذ على جميع الإعدادات
foreach ($dbConfigs as $cfg) {
    resetAndCreateTables($cfg, $defaultAdminUsername, $defaultAdminPasswordPlain);
}

echo "<h2> ي سيلاام انتهى: جميع قواعد البيانات جاهزة ومتوافقة مع موقعك.</h2>";
