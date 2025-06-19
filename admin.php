<?php

// =========================================
//  ملف: admin_dashboard.php (الملف الرئيسي للوحة التحكم)
//  المهام: لوحة تحكم كاملة لإدارة الأطباء، المرضى، الإجازات، وسجل الاستعلامات
//  - إضافة/تعديل/حذف إجازات نشطة وأرشيف
//  - تنسيق الطابع الزمني بتوقيت مكة (12 ساعة)
//  - فرز متقدم، بحث، فلترة حسب التاريخ بدون إعادة تحميل الصفحة (معالجة JS)
//  - تصميم مُحسّن وأكثر عصرية (معالجة CSS/JS)
//  - حل لمشكلات bind_param أثناء التعديل (تم الحل)
//  - تحسينات الأمان وفصل الاهتمامات
// =========================================

// ==== تفعيل تسجيل الأخطاء للتحقق من المشاكل ====
ini_set('display_errors', 0); // لا تعرض الأخطاء للمستخدم مباشرة
ini_set('log_errors', 1);     // قم بتسجيل الأخطاء
ini_set('error_log', __DIR__ . '/php_errors.log'); // حدد ملف لتسجيل الأخطاء فيه
error_reporting(E_ALL); // قم بالإبلاغ عن جميع أنواع الأخطاء
// ===============================================

// تحديد التوقيت الزمني للمشروع بالكامل
date_default_timezone_set('Asia/Riyadh');

// ==== 1. إعدادات الجلسة والأمان (مراجعة) ====
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 86400); // 24 ساعة
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), // استخدام HTTPS في الإنتاج
    'samesite' => 'Lax' // حماية ضد CSRF جزئياً
]);
session_start();

// التحقق من تسجيل دخول المسؤول
if (empty($_SESSION['admin_id'])) {
    header("Location: login.php"); // توجيه إلى صفحة تسجيل الدخول
    exit;
}

// تجديد معرف الجلسة بانتظام لزيادة الأمان
if (!isset($_SESSION['last_regen']) || $_SESSION['last_regen'] < time() - 300) { // كل 5 دقائق
    session_regenerate_id(true);
    $_SESSION['last_regen'] = time();
}

// ==== 2. حمايات CSRF (مراجعة) ====
// توليد توكن CSRF إذا لم يكن موجوداً
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // 32 بايت لتكن أقوى
}

// دالة لإرجاع حقل CSRF مخفي للنماذج
function csrf_input()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
}

// دالة للتحقق من توكن CSRF في طلبات POST
function check_csrf()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // التحقق من وجود التوكن ومطابقته
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            header('Content-Type: application/json'); // الرد بتنسيق JSON لأنها طلبات AJAX
            echo json_encode(['success' => false, 'message' => 'فشل التحقق الأمني (CSRF).']);
            exit;
        }
    }
}
check_csrf(); // استدعاء دالة التحقق عند كل طلب POST

// ==== 3. اتصال MySQL (قاعدة البيانات الرئيسية) (مراجعة) ====
// يفضل وضع هذه المتغيرات في ملف إعدادات منفصل (مثل config/db.php) لأسباب أمنية وتنظيمية
$db_host = 'ocvwlym0zv3tcn68.cbetxkdyhwsb.us-east-1.rds.amazonaws.com';
$db_user = 'smrg7ak77778emkb';
$db_pass = 'fw69cuijof4ahuhb';
$db_name = 'ygscnjzq8ueid5yz';
$db_port = 3306;

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset('utf8mb4');

if ($conn->connect_errno) {
    // تسجيل الخطأ بدلاً من عرضه مباشرة للمستخدم
    error_log("Failed to connect to MySQL: (" . $conn->connect_errno . ") " . $conn->connect_error);
    die("<div style='text-align:center;margin-top:50px;color:#c00;'>فشل الاتصال بقاعدة البيانات. يرجى المحاولة لاحقاً.</div>");
}

// ==== 4. إنشاء الجداول إذا لم تكن موجودة (مراجعة) ====
// يفضل نقل هذا إلى ملف تثبيت أو هجرة قاعدة بيانات يُنفّذ مرة واحدة
$conn->query("
    CREATE TABLE IF NOT EXISTS patients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        identity_number VARCHAR(20) NOT NULL UNIQUE
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS doctors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        title VARCHAR(100) NOT NULL,
        note VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS sick_leaves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_code VARCHAR(30) NOT NULL UNIQUE,
        patient_id INT NOT NULL,
        doctor_id INT NOT NULL,
        issue_date DATE NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        days_count INT NOT NULL,
        is_companion TINYINT(1) NOT NULL DEFAULT 0,
        companion_name VARCHAR(100) DEFAULT NULL,
        companion_relation VARCHAR(100) DEFAULT NULL,
        is_paid TINYINT(1) NOT NULL DEFAULT 0,
        payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        FOREIGN KEY(patient_id) REFERENCES patients(id) ON DELETE CASCADE,
        FOREIGN KEY(doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS leave_queries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        leave_id INT NOT NULL,
        queried_at DATETIME NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'external',
        FOREIGN KEY(leave_id) REFERENCES sick_leaves(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4
");

$conn->query("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('query','payment') NOT NULL,
        leave_id INT DEFAULT NULL,
        message VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        remind_at DATETIME DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        FOREIGN KEY(leave_id) REFERENCES sick_leaves(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4
");


// ==== 5. دوال مساعدة لإدارة المرضى والأطباء (مراجعة: التحقق من المدخلات) ====
function get_or_add_patient($conn, $name, $ident)
{
    // تطهير المدخلات قبل استخدامها في الاستعلام
    $name = trim($name);
    $ident = trim($ident);

    if (empty($name) || empty($ident)) {
        return false; // يجب أن تكون البيانات غير فارغة
    }

    $pid = null;
    $stmt = $conn->prepare("SELECT id FROM patients WHERE identity_number = ?");
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return false;
    }
    $stmt->bind_param("s", $ident);
    $stmt->execute();
    $stmt->bind_result($pid);

    if ($stmt->fetch()) {
        $stmt->close();
        return $pid;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO patients (name, identity_number) VALUES (?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return false;
    }
    $stmt->bind_param("ss", $name, $ident);
    $stmt->execute();
    $pid = $stmt->insert_id;
    $stmt->close();
    return $pid;
}

function get_or_add_doctor($conn, $name, $title, $note = null)
{
    // تطهير المدخلات
    $name = trim($name);
    $title = trim($title);
    $note = trim($note ?? '');

    if (empty($name) || empty($title)) {
        return false; // يجب أن تكون البيانات غير فارغة
    }

    $did = null;
    $stmt = $conn->prepare("SELECT id FROM doctors WHERE name = ? AND title = ?");
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return false;
    }
    $stmt->bind_param("ss", $name, $title);
    $stmt->execute();
    $stmt->bind_result($did);

    if ($stmt->fetch()) {
        $stmt->close();
        // تحديث الملاحظة إذا كانت موجودة ومختلفة
        if ($note !== null) {
            $u = $conn->prepare("UPDATE doctors SET note=? WHERE id=?");
            if (!$u) {
                error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
                return false;
            }
            $u->bind_param("si", $note, $did);
            $u->execute();
            $u->close();
        }
        return $did;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO doctors (name, title, note) VALUES (?, ?, ?)");
    if (!$stmt) {
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
        return false;
    }
    $stmt->bind_param("sss", $name, $title, $note);
    $stmt->execute();
    $did = $stmt->insert_id;
    $stmt->close();
    return $did;
}

// دالة لجلب تفاصيل إجازة معينة
function fetch_leave_details($conn, $leave_id)
{
    $stmt = $conn->prepare("
        SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
               sl.is_companion, sl.companion_name, sl.companion_relation,
               sl.is_paid, sl.payment_amount,
               DATE_FORMAT(sl.created_at, '%Y-%m-%d %r') AS created_at,
               DATE_FORMAT(sl.updated_at, '%Y-%m-%d %r') AS updated_at,
               DATE_FORMAT(sl.deleted_at, '%Y-%m-%d %r') AS deleted_at,
               p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
               (SELECT COUNT(*) FROM leave_queries WHERE leave_id = sl.id) AS queries_count
        FROM sick_leaves sl
        JOIN patients p ON sl.patient_id = p.id
        JOIN doctors d ON sl.doctor_id = d.id
        WHERE sl.id = ?
    ");
    if (!$stmt) {
        error_log("Prepare failed (fetch_leave_details): (" . $conn->errno . ") " . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $leave_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $leave = $result->fetch_assoc();
    $stmt->close();
    return $leave;
}

// دالة لجلب الإحصائيات العامة
function get_dashboard_stats($conn)
{
    $stats = [
        'total' => 0,
        'active' => 0,
        'archived' => 0,
        'doctors' => 0,
        'patients' => 0,
        'paid' => 0,
        'unpaid' => 0,
        'paid_amount' => 0,
        'unpaid_amount' => 0
    ];

    $res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0");
    if ($res) $stats['active'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=1");
    if ($res) $stats['archived'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0 AND is_paid=1");
    if ($res) $stats['paid'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves WHERE is_deleted=0 AND is_paid=0");
    if ($res) $stats['unpaid'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT IFNULL(SUM(payment_amount),0) AS amt FROM sick_leaves WHERE is_deleted=0 AND is_paid=1");
    if ($res) $stats['paid_amount'] = $res->fetch_assoc()['amt'];
    $res = $conn->query("SELECT IFNULL(SUM(payment_amount),0) AS amt FROM sick_leaves WHERE is_deleted=0 AND is_paid=0");
    if ($res) $stats['unpaid_amount'] = $res->fetch_assoc()['amt'];
    $res = $conn->query("SELECT COUNT(*) as c FROM sick_leaves");
    if ($res) $stats['total'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) as c FROM doctors");
    if ($res) $stats['doctors'] = $res->fetch_assoc()['c'];
    $res = $conn->query("SELECT COUNT(*) as c FROM patients");
    if ($res) $stats['patients'] = $res->fetch_assoc()['c'];

    return $stats;
}

// ==== 6. معالجة طلبات AJAX (مراجعة: فصل المنطق، التحقق من المدخلات) ====
// يفضل وضع هذا الجزء في ملف منفصل مثل ajax_handler.php
// والتأكد من أنه لا يرجع إلا JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header("Content-Type: application/json"); // دائمًا أرجع JSON
    header("Cache-Control: no-cache, must-revalidate"); // منع التخزين المؤقت لطلبات AJAX

    $action = $_POST['action'];

    switch ($action) {
        case 'add_doctor':
            $dname = filter_input(INPUT_POST, 'doctor_name', FILTER_SANITIZE_STRING);
            $dtitle = filter_input(INPUT_POST, 'doctor_title', FILTER_SANITIZE_STRING);
            $dnote = filter_input(INPUT_POST, 'doctor_note', FILTER_SANITIZE_STRING);

            if (empty($dname) || empty($dtitle)) {
                echo json_encode(['success' => false, 'message' => 'أدخل اسم الطبيب والمسمى الوظيفي.']);
                exit;
            }
            $did = get_or_add_doctor($conn, $dname, $dtitle, $dnote);
            if ($did) {
                $row = $conn->query("SELECT id, name, title, note FROM doctors WHERE id=$did")->fetch_assoc();
                echo json_encode(['success' => true, 'doctor' => $row, 'stats' => get_dashboard_stats($conn)]);
            } else {
                echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء إضافة/جلب الطبيب.']);
            }
            break;

        case 'edit_doctor':
            $did = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
            $dname = filter_input(INPUT_POST, 'doctor_name', FILTER_SANITIZE_STRING);
            $dtitle = filter_input(INPUT_POST, 'doctor_title', FILTER_SANITIZE_STRING);
            $dnote = filter_input(INPUT_POST, 'doctor_note', FILTER_SANITIZE_STRING);

            if (!$did || empty($dname) || empty($dtitle)) {
                echo json_encode(['success' => false, 'message' => 'بيانات الطبيب غير صحيحة.']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE doctors SET name=?, title=?, note=? WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (edit_doctor): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("sssi", $dname, $dtitle, $dnote, $did);
            $stmt->execute();
            $stmt->close();
            $row = $conn->query("SELECT id, name, title, note FROM doctors WHERE id=$did")->fetch_assoc();
            echo json_encode(['success' => true, 'doctor' => $row, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_doctor':
            $did = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
            if (!$did) {
                echo json_encode(['success' => false, 'message' => 'معرف الطبيب غير صحيح.']);
                exit;
            }
            // فحص إذا كان الطبيب مرتبطًا بإجازات
            $check_leaves = $conn->query("SELECT COUNT(*) FROM sick_leaves WHERE doctor_id = $did")->fetch_row()[0];
            if ($check_leaves > 0) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن حذف الطبيب لوجود إجازات مرتبطة به.']);
                exit;
            }
            $conn->query("DELETE FROM doctors WHERE id=$did");
            echo json_encode(['success' => true, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_all_doctors': // إجراء جديد لجلب قائمة الأطباء
            $doctors_list = [];
            $res = $conn->query("SELECT id, name, title, note FROM doctors ORDER BY name ASC");
            while ($row = $res->fetch_assoc()) {
                $doctors_list[] = $row;
            }
            echo json_encode(['success' => true, 'doctors' => $doctors_list, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'add_patient':
            $pname = filter_input(INPUT_POST, 'patient_name', FILTER_SANITIZE_STRING);
            $pident = filter_input(INPUT_POST, 'identity_number', FILTER_SANITIZE_STRING);

            if (empty($pname) || empty($pident)) {
                echo json_encode(['success' => false, 'message' => 'أدخل اسم المريض ورقم الهوية.']);
                exit;
            }
            $pid = get_or_add_patient($conn, $pname, $pident);
            if ($pid) {
                $row = $conn->query("SELECT id, name, identity_number FROM patients WHERE id=$pid")->fetch_assoc();
                echo json_encode(['success' => true, 'patient' => $row, 'stats' => get_dashboard_stats($conn)]);
            } else {
                echo json_encode(['success' => false, 'message' => 'حدث خطأ أثناء إضافة/جلب المريض.']);
            }
            break;

        case 'edit_patient':
            $pid = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
            $pname = filter_input(INPUT_POST, 'patient_name', FILTER_SANITIZE_STRING);
            $pident = filter_input(INPUT_POST, 'identity_number', FILTER_SANITIZE_STRING);

            if (!$pid || empty($pname) || empty($pident)) {
                echo json_encode(['success' => false, 'message' => 'بيانات المريض غير صحيحة.']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE patients SET name=?, identity_number=? WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (edit_patient): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("ssi", $pname, $pident, $pid);
            $stmt->execute();
            $stmt->close();
            $row = $conn->query("SELECT id, name, identity_number FROM patients WHERE id=$pid")->fetch_assoc();
            echo json_encode(['success' => true, 'patient' => $row, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_patient':
            $pid = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
            if (!$pid) {
                echo json_encode(['success' => false, 'message' => 'معرف المريض غير صحيح.']);
                exit;
            }
            // فحص إذا كان المريض مرتبطًا بإجازات
            $check_leaves = $conn->query("SELECT COUNT(*) FROM sick_leaves WHERE patient_id = $pid")->fetch_row()[0];
            if ($check_leaves > 0) {
                echo json_encode(['success' => false, 'message' => 'لا يمكن حذف المريض لوجود إجازات مرتبطة به.']);
                exit;
            }
            $conn->query("DELETE FROM patients WHERE id=$pid");
            echo json_encode(['success' => true, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_all_patients': // إجراء جديد لجلب قائمة المرضى
            $patients_list = [];
            $res = $conn->query("SELECT id, name, identity_number FROM patients ORDER BY name ASC");
            while ($row = $res->fetch_assoc()) {
                $patients_list[] = $row;
            }
            echo json_encode(['success' => true, 'patients' => $patients_list, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'add_leave':
            // التحقق من المدخلات بشكل صارم
            $patient_select_type = $_POST['patient_select'] ?? '';
            $doctor_select_type = $_POST['doctor_select'] ?? '';

            $pid = null;
            if ($patient_select_type === 'manual') {
                $pm_name = filter_input(INPUT_POST, 'patient_manual_name', FILTER_SANITIZE_STRING);
                $pm_id = filter_input(INPUT_POST, 'patient_manual_id', FILTER_SANITIZE_STRING);
                if (empty($pm_name) || empty($pm_id)) {
                    echo json_encode(['success' => false, 'message' => 'يجب إدخال اسم المريض ورقم هويته يدوياً.']);
                    exit;
                }
                $pid = get_or_add_patient($conn, $pm_name, $pm_id);
            } else {
                $pid = filter_input(INPUT_POST, 'patient_select', FILTER_VALIDATE_INT);
            }
            if (!$pid) {
                echo json_encode(['success' => false, 'message' => 'اختر مريضًا أو أدخله يدويًا.']);
                exit;
            }

            $did = null;
            if ($doctor_select_type === 'manual') {
                $dm_name = filter_input(INPUT_POST, 'doctor_manual_name', FILTER_SANITIZE_STRING);
                $dm_title = filter_input(INPUT_POST, 'doctor_manual_title', FILTER_SANITIZE_STRING);
                $dm_note = filter_input(INPUT_POST, 'doctor_manual_note', FILTER_SANITIZE_STRING);
                if (empty($dm_name) || empty($dm_title)) {
                    echo json_encode(['success' => false, 'message' => 'يجب إدخال اسم الطبيب ومسمّاه الوظيفي يدوياً.']);
                    exit;
                }
                $did = get_or_add_doctor($conn, $dm_name, $dm_title, $dm_note);
            } else {
                $did = filter_input(INPUT_POST, 'doctor_select', FILTER_VALIDATE_INT);
            }
            if (!$did) {
                echo json_encode(['success' => false, 'message' => 'اختر طبيبًا أو ادخله يدويًا.']);
                exit;
            }

            $issue_date_raw = filter_input(INPUT_POST, 'issue_date', FILTER_SANITIZE_STRING);
            $start = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
            $end = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);

            if (!strtotime($issue_date_raw) || !strtotime($start) || !strtotime($end) || strtotime($end) < strtotime($start)) {
                echo json_encode(['success' => false, 'message' => 'تأكد من صحة التواريخ المدخلة.']);
                exit;
            }
            $issue_date_formatted = date('Y-m-d', strtotime($issue_date_raw));
            $start_formatted = date('Y-m-d', strtotime($start));
            $end_formatted = date('Y-m-d', strtotime($end));

            $days_count = filter_input(INPUT_POST, 'days_count', FILTER_VALIDATE_INT);
            $days_manual = filter_input(INPUT_POST, 'days_manual', FILTER_SANITIZE_STRING);

            if ($days_manual === '1') {
                $days_count = max(1, $days_count);
            } else {
                $days_count = floor((strtotime($end_formatted) - strtotime($start_formatted)) / 86400) + 1;
            }
            if ($days_count < 1) { // التأكد من أن عدد الأيام لا يقل عن 1
                echo json_encode(['success' => false, 'message' => 'عدد الأيام يجب أن يكون 1 على الأقل.']);
                exit;
            }


            $is_comp = filter_input(INPUT_POST, 'is_companion', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
            $comp_name = $is_comp ? filter_input(INPUT_POST, 'companion_name', FILTER_SANITIZE_STRING) : null;
            $comp_rel = $is_comp ? filter_input(INPUT_POST, 'companion_relation', FILTER_SANITIZE_STRING) : null;

            if ($is_comp && (empty($comp_name) || empty($comp_rel))) {
                echo json_encode(['success' => false, 'message' => 'يجب إدخال اسم المرافق وصلة القرابة إذا كانت إجازة مرافق.']);
                exit;
            }

            $is_paid = filter_input(INPUT_POST, 'is_paid', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
            $payment_amount = filter_input(INPUT_POST, 'payment_amount', FILTER_VALIDATE_FLOAT);
            $payment_amount = $payment_amount !== false ? abs($payment_amount) : 0; // تأكيد رقم موجب

            $created_at = date('Y-m-d H:i:s');

            // منطق توليد رمز الخدمة (مراجعة: يمكن تحسين هذا المنطق في بيئات عالية التزامن)
            $service_code = '';
            $service_code_manual = trim(filter_input(INPUT_POST, 'service_code_manual', FILTER_SANITIZE_STRING));
            $prefix = trim(filter_input(INPUT_POST, 'service_prefix', FILTER_SANITIZE_STRING));
            $prefix_whitelist = ['GSL', 'PSL'];

            if (!empty($service_code_manual)) {
                $service_code = strtoupper($service_code_manual);
                if (!preg_match('/^(GSL|PSL)\d{6}\d{5}$/', $service_code)) {
                    echo json_encode(['success' => false, 'message' => 'صيغة رمز الخدمة اليدوي غير صحيحة.']);
                    exit;
                }
                $chk = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code = ?");
                if (!$chk) {
                    error_log("Prepare failed (add_leave service_code_manual): (" . $conn->errno . ") " . $conn->error);
                    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء التحقق من الرمز اليدوي.']);
                    exit;
                }
                $chk->bind_param("s", $service_code);
                $chk->execute();
                $chk->bind_result($cnt);
                $chk->fetch();
                $chk->close();
                if ($cnt > 0) {
                    echo json_encode(['success' => false, 'message' => 'رمز الخدمة اليدوي مستخدم مسبقًا، اختر رمزًا آخر.']);
                    exit;
                }
            } else {
                if (!in_array($prefix, $prefix_whitelist)) {
                    echo json_encode(['success' => false, 'message' => 'اختر بادئة صحيحة (GSL أو PSL) أو أدخل الرمز يدويًا.']);
                    exit;
                }
                $issue_ymd = date('ymd', strtotime($issue_date_raw));
                $like_pattern = $prefix . '%';
                $stmt = $conn->prepare("
                    SELECT service_code
                    FROM sick_leaves
                    WHERE service_code LIKE ?
                    ORDER BY service_code DESC
                    LIMIT 1
                ");
                if (!$stmt) {
                    error_log("Prepare failed (add_leave auto_code): (" . $conn->errno . ") " . $conn->error);
                    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء توليد الرمز.']);
                    exit;
                }
                $stmt->bind_param("s", $like_pattern);
                $stmt->execute();
                $stmt->bind_result($last_code);
                $stmt->fetch();
                $stmt->close();

                $new_suffix = 1;
                if ($last_code) {
                    $last_suffix = (int) substr($last_code, -5);
                    $new_suffix = $last_suffix + 1;
                    if ($new_suffix > 99999) {
                        $new_suffix = 1; // إعادة الضبط إذا تجاوز الحد
                    }
                }
                $service_code = $prefix . $issue_ymd . str_pad($new_suffix, 5, '0', STR_PAD_LEFT);

                // فحص أخير لعدم التكرار (احتياطًا للتزامن)
                $chk2 = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code=?");
                if (!$chk2) {
                    error_log("Prepare failed (add_leave auto_code_check): (" . $conn->errno . ") " . $conn->error);
                    echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء التحقق النهائي من الرمز.']);
                    exit;
                }
                $chk2->bind_param("s", $service_code);
                $chk2->execute();
                $chk2->bind_result($cnt2);
                $chk2->fetch();
                $chk2->close();
                if ($cnt2 > 0) {
                    echo json_encode(['success' => false, 'message' => 'حدث تضارب في توليد الرمز، حاول مرة أخرى.']);
                    exit;
                }
            }

            // إدخال الإجازة في قاعدة البيانات
            $stmt = $conn->prepare("INSERT INTO sick_leaves
                (service_code, patient_id, doctor_id, issue_date, start_date, end_date, days_count, is_companion, companion_name, companion_relation, is_paid, payment_amount, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt) {
                error_log("Prepare failed (add_leave insert): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء إضافة الإجازة.']);
                exit;
            }

            $stmt->bind_param(
                "siisssiissids",
                $service_code,
                $pid,
                $did,
                $issue_date_formatted,
                $start_formatted,
                $end_formatted,
                $days_count,
                $is_comp,
                $comp_name,
                $comp_rel,
                $is_paid,
                $payment_amount,
                $created_at
            );
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();

            $lv = fetch_leave_details($conn, $new_id); // جلب تفاصيل الإجازة المضافة

            echo json_encode(['success' => true, 'message' => 'تمت إضافة الإجازة بنجاح.', 'leave' => $lv, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'edit_leave':
            $lid = filter_input(INPUT_POST, 'leave_id_edit', FILTER_VALIDATE_INT);
            $service_code = strtoupper(trim(filter_input(INPUT_POST, 'service_code_edit', FILTER_SANITIZE_STRING)));
            $issue_date_raw = filter_input(INPUT_POST, 'issue_date_edit', FILTER_SANITIZE_STRING);
            $start = filter_input(INPUT_POST, 'start_date_edit', FILTER_SANITIZE_STRING);
            $end = filter_input(INPUT_POST, 'end_date_edit', FILTER_SANITIZE_STRING);

            if (!$lid || empty($service_code) || !strtotime($issue_date_raw) || !strtotime($start) || !strtotime($end) || strtotime($end) < strtotime($start)) {
                echo json_encode(['success' => false, 'message' => 'بيانات الإجازة غير صحيحة.']);
                exit;
            }

            $issue_date_formatted = date('Y-m-d', strtotime($issue_date_raw));
            $start_formatted = date('Y-m-d', strtotime($start));
            $end_formatted = date('Y-m-d', strtotime($end));

            $days_count = filter_input(INPUT_POST, 'days_count_edit', FILTER_VALIDATE_INT);
            $days_manual = filter_input(INPUT_POST, 'days_manual_edit', FILTER_SANITIZE_STRING);

            if ($days_manual === '1') {
                $days_count = max(1, $days_count);
            } else {
                $days_count = floor((strtotime($end_formatted) - strtotime($start_formatted)) / 86400) + 1;
            }
            if ($days_count < 1) {
                echo json_encode(['success' => false, 'message' => 'عدد الأيام يجب أن يكون 1 على الأقل.']);
                exit;
            }

            $is_comp = filter_input(INPUT_POST, 'is_companion_edit', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
            $comp_name = $is_comp ? filter_input(INPUT_POST, 'companion_name_edit', FILTER_SANITIZE_STRING) : null;
            $comp_rel = $is_comp ? filter_input(INPUT_POST, 'companion_relation_edit', FILTER_SANITIZE_STRING) : null;

            if ($is_comp && (empty($comp_name) || empty($comp_rel))) {
                echo json_encode(['success' => false, 'message' => 'يجب إدخال اسم المرافق وصلة القرابة إذا كانت إجازة مرافق.']);
                exit;
            }

            $is_paid = filter_input(INPUT_POST, 'is_paid_edit', FILTER_VALIDATE_INT) === 1 ? 1 : 0;
            $payment_amount = filter_input(INPUT_POST, 'payment_amount_edit', FILTER_VALIDATE_FLOAT);
            $payment_amount = $payment_amount !== false ? abs($payment_amount) : 0;

            $updated_at = date('Y-m-d H:i:s');

            // منع تكرار رمز الخدمة على إجازة أخرى
            $chk2 = $conn->prepare("SELECT COUNT(*) FROM sick_leaves WHERE service_code=? AND id<>?");
            if (!$chk2) {
                error_log("Prepare failed (edit_leave service_code_check): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء التحقق من رمز الخدمة.']);
                exit;
            }
            $chk2->bind_param("si", $service_code, $lid);
            $chk2->execute();
            $chk2->bind_result($cnt2);
            $chk2->fetch();
            $chk2->close();
            if ($cnt2 > 0) {
                echo json_encode(['success' => false, 'message' => 'رمز الخدمة مستخدم مسبقًا لإجازة أخرى.']);
                exit;
            }

            $stmt = $conn->prepare("UPDATE sick_leaves SET
                service_code=?, issue_date=?, start_date=?, end_date=?, days_count=?, is_companion=?, companion_name=?, companion_relation=?, is_paid=?, payment_amount=?, updated_at=?
                WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (edit_leave update): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء تحديث الإجازة.']);
                exit;
            }

            $stmt->bind_param(
                "ssssiissidsi",
                $service_code,
                $issue_date_formatted,
                $start_formatted,
                $end_formatted,
                $days_count,
                $is_comp,
                $comp_name,
                $comp_rel,
                $is_paid,
                $payment_amount,
                $updated_at,
                $lid
            );
            $stmt->execute();
            $stmt->close();

            $lv = fetch_leave_details($conn, $lid); // جلب تفاصيل الإجازة المحدثة

            echo json_encode(['success' => true, 'message' => 'تم تعديل الإجازة بنجاح.', 'leave' => $lv, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_leave':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $deleted_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE sick_leaves SET is_deleted=1, deleted_at=? WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (delete_leave): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء الأرشفة.']);
                exit;
            }
            $stmt->bind_param("si", $deleted_at, $lid);
            $stmt->execute();
            $stmt->close();

            $deleted_formatted = date('Y-m-d h:i A', strtotime($deleted_at));
            echo json_encode(['success' => true, 'message' => 'تم نقل الإجازة إلى الأرشيف.', 'deleted_at' => $deleted_formatted, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'restore_leave':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE sick_leaves SET is_deleted=0, deleted_at=NULL WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (restore_leave): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء الاستعادة.']);
                exit;
            }
            $stmt->bind_param("i", $lid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تمت استعادة الإجازة من الأرشيف.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'force_delete_leave':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM sick_leaves WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (force_delete_leave): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء الحذف النهائي.']);
                exit;
            }
            $stmt->bind_param("i", $lid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تم الحذف النهائي للإجازة.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'force_delete_all_archived':
            $conn->query("DELETE FROM sick_leaves WHERE is_deleted=1");
            echo json_encode(['success' => true, 'message' => 'تم حذف جميع الإجازات المؤرشفة نهائيًا.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'add_query':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO leave_queries (leave_id, queried_at) VALUES (?, ?)");
            if (!$stmt) {
                error_log("Prepare failed (add_query): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء تسجيل الاستعلام.']);
                exit;
            }
            $stmt->bind_param("is", $lid, $now);
            $stmt->execute();
            $stmt->close();
            $cntRes = $conn->query("SELECT COUNT(*) AS c FROM leave_queries WHERE leave_id=$lid");
            $newCount = $cntRes->fetch_assoc()['c'];
            echo json_encode(['success' => true, 'message' => 'تم تسجيل الاستعلام.', 'new_count' => $newCount, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_query':
            $qid = filter_input(INPUT_POST, 'query_id', FILTER_VALIDATE_INT);
            if (!$qid) {
                echo json_encode(['success' => false, 'message' => 'معرف الاستعلام غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM leave_queries WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (delete_query): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء حذف سجل الاستعلام.']);
                exit;
            }
            $stmt->bind_param("i", $qid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تم حذف سجل الاستعلام.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_all_queries': // تحذير: هذا يحذف جميع الاستعلامات بلا فلترة
            $conn->query("DELETE FROM leave_queries");
            echo json_encode(['success' => true, 'message' => 'تم حذف جميع سجلات الاستعلام نهائيًا.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_all_queries_for_leave': // **الإجراء الجديد: حذف جميع الاستعلامات لإجازة معينة**
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM leave_queries WHERE leave_id = ?");
            if (!$stmt) {
                error_log("Prepare failed (delete_all_queries_for_leave): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات أثناء حذف سجلات الاستعلام.']);
                exit;
            }
            $stmt->bind_param("i", $lid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تم حذف جميع سجلات الاستعلام لهذه الإجازة.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_queries':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("SELECT id, DATE_FORMAT(queried_at, '%Y-%m-%d %r') AS queried_at FROM leave_queries WHERE leave_id=? ORDER BY queried_at DESC");
            if (!$stmt) {
                error_log("Prepare failed (fetch_queries): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("i", $lid);
            $stmt->execute();
            $res = $stmt->get_result();
            $arr = [];
            while ($r = $res->fetch_assoc()) {
                $arr[] = ['id' => $r['id'], 'queried_at' => $r['queried_at']];
            }
            $stmt->close();
            echo json_encode(['success' => true, 'queries' => $arr, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_notification':
            $nid = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
            if (!$nid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإشعار غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM notifications WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (delete_notification): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("i", $nid);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تم حذف الإشعار.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'delete_all_notifications': // تحذير: هذا يحذف جميع الإشعارات من هذا النوع
            $type = filter_input(INPUT_POST, 'n_type', FILTER_SANITIZE_STRING);
            if (!in_array($type, ['query', 'payment'])) {
                echo json_encode(['success' => false, 'message' => 'نوع إشعار غير صالح.']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM notifications WHERE type=?");
            if (!$stmt) {
                error_log("Prepare failed (delete_all_notifications): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("s", $type);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'تم حذف جميع إشعارات المدفوعات.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'mark_leave_paid':
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $amount = $amount !== false ? abs($amount) : 0;

            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $stmt = $conn->prepare("UPDATE sick_leaves SET is_paid=1, payment_amount=? WHERE id=?");
            if (!$stmt) {
                error_log("Prepare failed (mark_leave_paid): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("di", $amount, $lid);
            $stmt->execute();
            $stmt->close();

            $stmt_del_notif = $conn->prepare("DELETE FROM notifications WHERE type='payment' AND leave_id=?");
            if (!$stmt_del_notif) {
                error_log("Prepare failed (mark_leave_paid del notif): (" . $conn->errno . ") " . $conn->error);
                // لا نوقف التنفيذ هنا، فقط نسجل الخطأ
            } else {
                $stmt_del_notif->bind_param("i", $lid);
                $stmt_del_notif->execute();
                $stmt_del_notif->close();
            }

            echo json_encode(['success' => true, 'message' => 'تم تحديد الإجازة كمدفوعة.', 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_notifications': // إجراء جديد لجلب إشعارات المدفوعات
            $arr = [];
            $res = $conn->query("SELECT n.id, n.message, n.created_at, n.leave_id, sl.payment_amount FROM notifications n LEFT JOIN sick_leaves sl ON n.leave_id=sl.id WHERE n.type='payment' ORDER BY n.created_at DESC");
            while ($row = $res->fetch_assoc()) {
                $arr[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $arr, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_stats': // إجراء جديد لجلب الإحصائيات فقط
            echo json_encode(['success' => true, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_leave_details': // **الإجراء الجديد: جلب تفاصيل إجازة واحدة**
            $lid = filter_input(INPUT_POST, 'leave_id', FILTER_VALIDATE_INT);
            if (!$lid) {
                echo json_encode(['success' => false, 'message' => 'معرف الإجازة غير صحيح.']);
                exit;
            }
            $leave = fetch_leave_details($conn, $lid);
            if ($leave) {
                echo json_encode(['success' => true, 'leave' => $leave, 'stats' => get_dashboard_stats($conn)]);
            } else {
                echo json_encode(['success' => false, 'message' => 'لم يتم العثور على الإجازة.', 'stats' => get_dashboard_stats($conn)]);
            }
            break;

        case 'fetch_leaves_by_patient': // **الإجراء الجديد: جلب إجازات لمريض معين**
            $pid = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
            if (!$pid) {
                echo json_encode(['success' => false, 'message' => 'معرف المريض غير صحيح.']);
                exit;
            }
            $leaves_for_patient = [];
            // تأكد من جلب فقط الأعمدة التي تحتاجها في الواجهة
            $stmt = $conn->prepare("
                SELECT sl.id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                       sl.is_companion, sl.companion_name, sl.companion_relation,
                       sl.is_paid, sl.payment_amount,
                       d.name AS doctor_name, d.title AS doctor_title
                FROM sick_leaves sl
                JOIN doctors d ON sl.doctor_id = d.id
                WHERE sl.patient_id = ? AND sl.is_deleted = 0
                ORDER BY sl.created_at DESC
            ");
            if (!$stmt) {
                error_log("Prepare failed (fetch_leaves_by_patient): (" . $conn->errno . ") " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'خطأ في قاعدة البيانات.']);
                exit;
            }
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $leaves_for_patient[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'leaves' => $leaves_for_patient, 'stats' => get_dashboard_stats($conn)]);
            break;

        case 'fetch_all_leaves': // **الإجراء الجديد: جلب جميع بيانات الجداول في طلب واحد**
            $all_leaves = [];
            $res_leaves = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                                 sl.is_companion, sl.companion_name, sl.companion_relation,
                                 sl.is_paid, sl.payment_amount,
                                 DATE_FORMAT(sl.created_at, '%Y-%m-%d %r') AS created_at,
                                 p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                                 (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                          FROM sick_leaves sl
                          JOIN patients p ON sl.patient_id=p.id
                          JOIN doctors d ON sl.doctor_id=d.id
                          WHERE sl.is_deleted=0
                          ORDER BY sl.created_at DESC");
            if ($res_leaves) {
                while ($row = $res_leaves->fetch_assoc()) {
                    $all_leaves[] = $row;
                }
            } else {
                error_log("Query failed for active leaves (fetch_all_leaves): " . $conn->error);
            }

            $all_archived = [];
            $res_archived = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                                 sl.is_companion, sl.companion_name, sl.companion_relation,
                                 sl.is_paid, sl.payment_amount,
                                 DATE_FORMAT(sl.deleted_at, '%Y-%m-%d %r') AS deleted_at,
                                 p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                                 (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                          FROM sick_leaves sl
                          JOIN patients p ON sl.patient_id=p.id
                          JOIN doctors d ON sl.doctor_id=d.id
                          WHERE sl.is_deleted=1
                          ORDER BY sl.deleted_at DESC");
            if ($res_archived) {
                while ($row = $res_archived->fetch_assoc()) {
                    $all_archived[] = $row;
                }
            } else {
                error_log("Query failed for archived leaves (fetch_all_leaves): " . $conn->error);
            }

            $all_queries = [];
            $res_queries = $conn->query("SELECT lq.id AS qid, lq.leave_id, sl.service_code, p.name AS patient_name, p.identity_number,
                                 DATE_FORMAT(lq.queried_at, '%Y-%m-%d %r') AS queried_at
                          FROM leave_queries lq
                          JOIN sick_leaves sl ON lq.leave_id=sl.id AND sl.is_deleted=0
                          JOIN patients p ON sl.patient_id=p.id
                          ORDER BY lq.queried_at DESC");
            if ($res_queries) {
                while ($row = $res_queries->fetch_assoc()) {
                    $all_queries[] = $row;
                }
            } else {
                error_log("Query failed for queries (fetch_all_leaves): " . $conn->error);
            }

            $notifications_payment = [];
            $res_notif = $conn->query("SELECT n.id, n.message, n.created_at, n.leave_id, sl.payment_amount
                                  FROM notifications n
                                  LEFT JOIN sick_leaves sl ON n.leave_id=sl.id
                                  WHERE n.type='payment'
                                  ORDER BY n.created_at DESC");
            if ($res_notif) {
                while ($row = $res_notif->fetch_assoc()) {
                    $notifications_payment[] = $row;
                }
            } else {
                error_log("Query failed for notifications (fetch_all_leaves): " . $conn->error);
            }

            echo json_encode([
                'success' => true,
                'leaves' => $all_leaves,
                'archived' => $all_archived,
                'queries' => $all_queries,
                'notifications_payment' => $notifications_payment,
                'stats' => get_dashboard_stats($conn)
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'إجراء غير معروف.']);
            break;
    }
    exit; // إنهاء التنفيذ بعد معالجة طلب AJAX
}

// ==== 7. جلب الإحصائيات العامة ====
$stats = get_dashboard_stats($conn);

// ==== 8. جلب قوائم المرضى والأطباء لعرضها في <select> ====
// هذه البيانات يتم جلبها عند تحميل الصفحة لإعداد الـ <select>s الأولية
$patients = [];
$res = $conn->query("SELECT id, name, identity_number FROM patients ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $patients[] = $row;
    }
} else {
    error_log("Query failed for patients in initial load: " . $conn->error);
}


$doctors = [];
$res = $conn->query("SELECT id, name, title, note FROM doctors ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $doctors[] = $row;
    }
} else {
    error_log("Query failed for doctors in initial load: " . $conn->error);
}

// ==== 8.1 إحصائيات المدفوعات لكل مريض ====
$payments = [];
$res = $conn->query("SELECT p.id, p.name,
        COUNT(sl.id) AS total,
        SUM(CASE WHEN sl.is_paid=1 THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN sl.is_paid=0 THEN 1 ELSE 0 END) AS unpaid_count,
        IFNULL(SUM(CASE WHEN sl.is_paid=1 THEN sl.payment_amount ELSE 0 END),0) AS paid_amount,
        IFNULL(SUM(CASE WHEN sl.is_paid=0 THEN sl.payment_amount ELSE 0 END),0) AS unpaid_amount
    FROM patients p
    LEFT JOIN sick_leaves sl ON sl.patient_id=p.id AND sl.is_deleted=0
    GROUP BY p.id ORDER BY p.name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
} else {
    error_log("Query failed for payments in initial load: " . $conn->error);
}

// ==== 9. جلب بيانات الإجازات النشطة (للجدول الرئيسي) ====
$leaves = [];
$res = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                             sl.is_companion, sl.companion_name, sl.companion_relation,
                             sl.is_paid, sl.payment_amount,
                             DATE_FORMAT(sl.created_at, '%Y-%m-%d %r') AS created_at,
                             p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                             (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                      FROM sick_leaves sl
                      JOIN patients p ON sl.patient_id=p.id
                      JOIN doctors d ON sl.doctor_id=d.id
                      WHERE sl.is_deleted=0
                      ORDER BY sl.created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $leaves[] = $row;
    }
} else {
    error_log("Query failed for active leaves in initial load: " . $conn->error);
}


// ==== 10. جلب بيانات الأرشيف (الإجازات المحذوفة) ====
$archived = [];
$res = $conn->query("SELECT sl.id, sl.patient_id, sl.service_code, sl.issue_date, sl.start_date, sl.end_date, sl.days_count,
                             sl.is_companion, sl.companion_name, sl.companion_relation,
                             sl.is_paid, sl.payment_amount,
                             DATE_FORMAT(sl.deleted_at, '%Y-%m-%d %r') AS deleted_at,
                             p.name AS patient_name, p.identity_number, d.name AS doctor_name, d.title AS doctor_title, d.note AS doctor_note,
                             (SELECT COUNT(*) FROM leave_queries WHERE leave_id=sl.id) AS queries_count
                      FROM sick_leaves sl
                      JOIN patients p ON sl.patient_id=p.id
                      JOIN doctors d ON sl.doctor_id=d.id
                      WHERE sl.is_deleted=1
                      ORDER BY sl.deleted_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $archived[] = $row;
    }
} else {
    error_log("Query failed for archived leaves in initial load: " . $conn->error);
}

// ==== 11. جلب سجلات الاستعلامات للإجازات النشطة (للجدول الفرعي) ====
$queries = [];
$res = $conn->query("SELECT lq.id AS qid, lq.leave_id, sl.service_code, p.name AS patient_name, p.identity_number,
                             DATE_FORMAT(lq.queried_at, '%Y-%m-%d %r') AS queried_at
                      FROM leave_queries lq
                      JOIN sick_leaves sl ON lq.leave_id=sl.id AND sl.is_deleted=0
                      JOIN patients p ON sl.patient_id=p.id
                      ORDER BY lq.queried_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $queries[] = $row;
    }
} else {
    error_log("Query failed for queries in initial load: " . $conn->error);
}

// ==== 12. فحص الإجازات غير المدفوعة وإنشاء إشعار عند الحاجة ====
// هذا الجزء يمكن نقله إلى Cron Job أو يُنفّذ بشكل أقل تكراراً لتحسين الأداء
$res = $conn->query("SELECT id, service_code, created_at FROM sick_leaves WHERE is_paid=0 AND is_deleted=0");
if ($res) {
    $now = time();
    while ($r = $res->fetch_assoc()) {
        // إذا مر 5 دقائق على إنشاء الإجازة ولم تُدفع
        if (strtotime($r['created_at']) < $now - 300) {
            $svc = $conn->real_escape_string($r['service_code']);
            $lid = (int) $r['id'];
            $msg = 'إجازة غير مدفوعة ' . $svc;
            // إضافة الإشعار فقط إذا لم يكن موجوداً
            $conn->query("INSERT INTO notifications (type, leave_id, message, created_at)
                          SELECT 'payment', $lid, '$msg', NOW()
                          FROM DUAL
                          WHERE NOT EXISTS (SELECT 1 FROM notifications WHERE type='payment' AND leave_id=$lid)");
        }
    }
} else {
    error_log("Query failed for unpaid leaves check: " . $conn->error);
}


// ==== 13. جلب إشعارات المدفوعات ====
$notifications_payment = [];
$res = $conn->query("SELECT n.id, n.message, n.created_at, n.leave_id, sl.payment_amount
                      FROM notifications n
                      LEFT JOIN sick_leaves sl ON n.leave_id=sl.id
                      WHERE n.type='payment'
                      ORDER BY n.created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $notifications_payment[] = $row;
    }
} else {
    error_log("Query failed for payment notifications in initial load: " . $conn->error);
}

// إغلاق الاتصال بقاعدة البيانات في النهاية
$conn->close();

// الآن يتبع هذا الجزء HTML و CSS و JavaScript
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لوحة تحكم الإجازات – موقع صحتي</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<style>
    /*
 * ملف: style.css
 * المهام: تنسيقات CSS مخصصة للوحة تحكم الإجازات.
 * - دعم الوضع الفاتح والداكن.
 * - تحسينات جمالية للعناصر المختلفة.
 * - تصميم متجاوب لضمان عرض مناسب على جميع الأجهزة.
*/

    /* ======================== متغيرات Light & Dark ======================== */
    :root {
        /* الألوان الأساسية */
        --primary-color: #0288d1;
        /* أزرق */
        --secondary-color: #64b5f6;
        /* أزرق فاتح */
        --accent-color: #ff5722;
        /* لون مميز (برتقالي)، للاستخدام في الأيقونات البارزة أو الروابط الهامة */

        /* ألوان الخلفيات والنصوص للوضع الفاتح */
        --bg-color: #f8f9fa;
        /* خلفية فاتحة جدًا */
        --card-bg: #ffffff;
        /* خلفية البطاقات بيضاء */
        --text-color: #212529;
        /* لون النص الرئيسي (داكن) */
        --text-secondary-color: #6c757d;
        /* لون النص الثانوي (رمادي) */
        --border-color: #e9ecef;
        /* لون الحدود الخفيف */
        --shadow-color: rgba(0, 0, 0, 0.1);
        /* ظل خفيف للبطاقات والعناصر */

        /* ألوان الحالات (مطابقة لألوان Bootstrap القياسية للحالات) */
        --info-color: #17a2b8;
        --success-color: #28a745;
        --warning-color: #ffc107;
        --danger-color: #dc3545;

        /* إعدادات عامة */
        --border-radius: 12px;
        /* نصف قطر الحواف لجميع البطاقات والعناصر */
        --transition-speed: 0.3s;
        /* سرعة التحولات للانتقالات السلسة */
        --font-main: 'Cairo', sans-serif;
        /* الخط الرئيسي */
        --font-size-base: 1rem;
        /* حجم الخط الأساسي (16px) */
        --font-size-sm: 0.875rem;
        /* حجم خط أصغر (14px) */
        --table-header-bg: #e0f2f7;
        /* خلفية رأس الجدول للوضع الفاتح */
    }

    /* الوضع الداكن */
    .dark-mode {
        /* ألوان الخلفيات والنصوص للوضع الداكن */
        --bg-color: #1a1a1a;
        /* خلفية داكنة جداً */
        --card-bg: #2b2b2b;
        /* خلفية البطاقات داكنة */
        --text-color: #f8f9fa;
        /* لون النص الرئيسي (فاتح) */
        --text-secondary-color: #ced4da;
        /* لون النص الثانوي (رمادي فاتح) */
        --border-color: #495057;
        /* لون الحدود (داكن) */
        --shadow-color: rgba(0, 0, 0, 0.4);
        /* ظل أغمق */

        /* تعديل الألوان الأساسية لتكون أفتح وأكثر وضوحاً في الوضع الداكن */
        --primary-color: #90caf9;
        --secondary-color: #42a5f5;

        /* ألوان الحالات في الوضع الداكن */
        --info-color: #4dd0e1;
        --success-color: #81c784;
        --warning-color: #ffd54f;
        --danger-color: #ef9a9a;

        /* ألوان التوست (الإشعارات المنبثقة) */
        --toast-bg: rgba(255, 255, 255, 0.15);
        /* خلفية شفافة قليلاً */
        --toast-text: #ffffff;
        --table-header-bg: #343a40;
        /* خلفية رأس الجدول للوضع الداكن */
    }

    /* ======================== التنسيقات العامة ======================== */
    body {
        background: var(--bg-color);
        color: var(--text-color);
        font-family: var(--font-main);
        font-size: var(--font-size-base);
        min-height: 100vh;
        transition: background var(--transition-speed), color var(--transition-speed);
        direction: rtl;
        /* التأكد من أن الاتجاه من اليمين لليسار */
    }

    h5 {
        font-weight: 700;
        /* جعل العناوين أكثر سمكًا */
        color: var(--primary-color);
        border-bottom: 2px solid var(--secondary-color);
        padding-bottom: 8px;
        /* زيادة التباعد أسفل العنوان */
        margin-bottom: 1.5rem;
        /* زيادة الهامش أسفل العنوان */
        animation: fadeIn 0.5s ease-in-out;
    }

    label {
        font-weight: 600;
        /* جعل الليبلات أكثر سمكًا */
        color: var(--primary-color);
        margin-bottom: 0.25rem;
        /* تقليل المسافة بين الليبل والحقل */
        display: block;
        /* لجعل الليبل يأخذ سطرًا كاملاً */
    }

    .form-control,
    .form-select,
    .input-group-text {
        border-radius: 8px;
        /* حواف مستديرة أكثر لحقول الإدخال */
        border-color: var(--border-color);
        /* لون حدود يتناسق مع الوضع الفاتح/الداكن */
        background-color: var(--card-bg);
        /* خلفية الحقول تتناسق مع خلفية البطاقات */
        color: var(--text-color);
        /* لون النص داخل الحقول */
        transition: border-color var(--transition-speed), background-color var(--transition-speed), color var(--transition-speed);
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
        /* ظل تركيز بلون Primary */
        background-color: var(--card-bg);
        /* منع تغيير الخلفية عند التركيز */
        color: var(--text-color);
    }

    /* بطاقات المحتوى */
    .card-custom {
        background: var(--card-bg);
        border-radius: var(--border-radius);
        box-shadow: 0 6px 20px var(--shadow-color);
        /* ظل أكبر وأكثر وضوحًا */
        margin-bottom: 2rem;
        transition: background var(--transition-speed), color var(--transition-speed), box-shadow var(--transition-speed);
        animation: fadeIn 0.5s ease-in-out;
        border: none;
        /* إزالة الحدود الافتراضية للبطاقات */
    }

    .card-header {
        padding: 1rem 1.5rem;
        /* زيادة Padding في رأس البطاقة */
        border-bottom: 1px solid var(--border-color);
        /* فصل خفيف بين الرأس والجسم */
    }

    /* ======================== تنسيقات الأزرار ======================== */
    .btn {
        font-weight: 600;
        /* جعل نص الأزرار أكثر سمكًا */
        transition: all 0.2s ease-in-out;
        /* انتقال سلس لجميع خصائص الزر */
        display: inline-flex;
        /* لجعل الأيقونة والنص في سطر واحد */
        align-items: center;
        /* لمحاذاة عمودية للأيقونة والنص */
        justify-content: center;
        /* للمحاذاة الأفقية */
        gap: 0.5rem;
        /* مسافة بين الأيقونة والنص */
    }

    .btn-gradient {
        background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
        color: #fff;
        border-radius: 50px;
        /* جعلها مستديرة بالكامل */
        border: none;
        box-shadow: 0 4px 12px var(--shadow-color);
        /* ظل أكثر بروزًا */
        padding: 0.75rem 1.5rem;
        /* تباعد أكبر للأزرار الرئيسية */
        font-size: 1.1rem;
        /* حجم خط أكبر قليلاً */
    }

    .btn-gradient:hover {
        background: linear-gradient(90deg, var(--primary-color), #01579b);
        transform: translateY(-2px);
        /* حركة خفيفة للأعلى عند التمرير */
        box-shadow: 0 6px 16px var(--shadow-color);
        /* ظل أكبر عند التمرير */
        color: #fff;
        /* التأكد من بقاء النص أبيض */
    }

    /* الأزرار الدلالية (التحكم) */
    .btn-danger-custom {
        background-color: var(--danger-color);
        color: #fff;
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-danger-custom:hover {
        background-color: #c62828;
        /* لون أغمق قليلاً عند التمرير */
        transform: translateY(-1px);
        box-shadow: 0 4px 10px var(--shadow-color);
        color: #fff;
    }

    .btn-warning-custom {
        background-color: var(--warning-color);
        color: var(--text-color);
        /* نص داكن ليتناسب مع الخلفية الفاتحة */
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-warning-custom:hover {
        background-color: #f57f17;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px var(--shadow-color);
        color: var(--text-color);
    }

    .btn-success-custom {
        background-color: var(--success-color);
        color: #fff;
        border: none;
        border-radius: 8px;
        box-shadow: 0 2px 6px var(--shadow-color);
    }

    .btn-success-custom:hover {
        background-color: #2e7d32;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px var(--shadow-color);
        color: #fff;
    }

    /* أزرار الإجراءات داخل الجداول */
    .action-btn {
        font-size: 0.85rem;
        /* حجم خط أصغر قليلاً لأزرار التحكم */
        padding: 6px 10px;
        /* تباعد مناسب */
        border-radius: 6px;
        margin: 0 3px;
        /* مسافة بسيطة بين الأزرار */
        min-width: 80px;
        /* تحديد عرض أدنى للحفاظ على التناسق */
    }

    /* ======================== تنسيقات الجداول ======================== */
    .table-responsive {
        border-radius: var(--border-radius);
        box-shadow: 0 4px 15px var(--shadow-color);
        /* ظل أكثر وضوحًا */
        background: var(--card-bg);
        max-height: 500px;
        /* زيادة الارتفاع الأقصى للجداول */
        overflow-y: auto;
        /* تمكين التمرير العمودي */
        transition: background var(--transition-speed);
        animation: fadeIn 0.5s ease-in-out;
        border: 1px solid var(--border-color);
        /* حدود خفيفة حول الجدول */
    }

    .table {
        margin-bottom: 0;
        /* إزالة الهامش السفلي للجدول داخل الـ table-responsive */
        color: var(--text-color);
        /* لون النص داخل الجدول */
    }

    .table th,
    .table td {
        vertical-align: middle;
        padding: 0.75rem;
        /* زيادة Padding للخلايا */
        border-color: var(--border-color);
        /* لون حدود الخلايا */
    }

    .table thead th {
        background-color: var(--table-header-bg);
        /* خلفية رأس الجدول */
        color: var(--text-color);
        /* لون نص رأس الجدول */
        font-weight: 700;
        /* نص رأس الجدول أكثر سمكًا */
        position: sticky;
        /* جعل رأس الجدول ثابتًا عند التمرير */
        top: 0;
        z-index: 2;
        /* ليبقى فوق محتوى الجدول */
        border-bottom: 2px solid var(--primary-color);
        /* خط سفلي مميز لرأس الجدول */
        transition: background-color var(--transition-speed), color var(--transition-speed);
    }

    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(var(--secondary-color-rgb), 0.05);
        /* شريط أفتح قليلاً */
    }

    .table-hover tbody tr:hover {
        background-color: rgba(var(--primary-color-rgb), 0.15);
        /* تأثير تمرير أكثر وضوحًا */
        cursor: pointer;
    }

    /* تخصيص للبادج (Badges) */
    .badge {
        padding: 0.4em 0.7em;
        font-size: 0.8em;
        font-weight: 600;
        border-radius: 0.5rem;
        /* حواف مستديرة للبادج */
    }

    /* صفوف "لا نتائج" */
    .no-results {
        text-align: center;
        font-style: italic;
        color: var(--text-secondary-color);
        /* لون نص ثانوي */
        padding: 1rem 0;
        background-color: var(--card-bg);
        /* خلفية متناسقة */
    }

    /* ======================== تنسيقات الإحصائيات (Stats Box) ======================== */
    .stats-box {
        background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
        color: #fff;
        border-radius: var(--border-radius);
        padding: 15px 10px;
        /* تباعد أكبر */
        text-align: center;
        font-size: 1.1rem;
        /* حجم خط أكبر قليلاً */
        font-weight: 700;
        margin-bottom: 8px;
        /* زيادة الهامش السفلي */
        transition: background var(--transition-speed), opacity var(--transition-speed), transform 0.2s;
        animation: fadeIn 0.5s ease-in-out;
        box-shadow: 0 4px 15px var(--shadow-color);
        flex: 1 1 auto;
        /* لجعل الصناديق تتمدد وتتقلص بمرونة */
    }

    .stats-box:hover {
        transform: translateY(-3px);
        /* تأثير خفيف عند التمرير */
        box-shadow: 0 8px 20px var(--shadow-color);
    }

    /* ======================== المودال (Modals) ======================== */
    .modal-content {
        border-radius: var(--border-radius);
        background: var(--card-bg);
        color: var(--text-color);
        transition: background var(--transition-speed), color var(--transition-speed);
        animation: fadeIn 0.3s ease-in-out;
        border: none;
        /* إزالة حدود المودال الافتراضية */
        box-shadow: 0 8px 30px var(--shadow-color);
        /* ظل أكبر للمودال */
    }

    .modal-header {
        border-bottom-color: var(--border-color);
        /* لون حدود رأس المودال */
    }

    .modal-footer {
        border-top-color: var(--border-color);
        /* لون حدود ذيل المودال */
    }

    /* ======================== الإشعارات (Toasts) ======================== */
    #alert-container {
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 2500;
        width: 350px;
        /* عرض أكبر قليلاً للإشعارات */
        max-width: 90%;
        /* لضمان التجاوب على الشاشات الصغيرة */
        transition: right var(--transition-speed);
    }

    #alert-container .toast {
        background: var(--toast-bg);
        color: var(--toast-text);
        border-radius: var(--border-radius);
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        /* ظل أوضح للإشعارات */
        margin-bottom: 0.75rem;
        /* مسافة أكبر بين الإشعارات */
        animation: slideInRight 0.5s ease-out;
        /* حركة دخول من اليمين */
        border: none;
        /* إزالة الحدود الافتراضية للتوست */
    }

    /* زر الإغلاق في التوست */
    #alert-container .toast .btn-close {
        filter: invert(1) grayscale(100%) brightness(200%);
        /* لجعل زر الإغلاق أبيض في الخلفية الداكنة */
    }

    /* ======================== الوضع الداكن/الفاتح Toggle ======================== */
    #darkModeToggle {
        position: fixed;
        bottom: 1rem;
        left: 1rem;
        z-index: 3000;
        background-color: var(--primary-color);
        color: #fff;
        border: none;
        padding: 0.6rem 1.2rem;
        border-radius: 30px;
        font-size: 1rem;
        box-shadow: 0 4px 12px var(--shadow-color);
        transition: background-color var(--transition-speed), color var(--transition-speed), transform 0.2s;
    }

    #darkModeToggle:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px var(--shadow-color);
        background-color: #01579b;
        /* لون أغمق عند التمرير */
        color: #fff;
    }

    /* ======================== مؤشر التحميل (Loading Overlay) ======================== */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        /* خلفية شبه شفافة */
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 4000;
        opacity: 0;
        /* مخفية في البداية */
        visibility: hidden;
        /* مخفية تمامًا */
        transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
    }

    .loading-overlay.show {
        opacity: 1;
        visibility: visible;
    }

    .spinner-border {
        color: #fff !important;
        /* التأكد من أن لون الدوار أبيض */
    }

    /* ======================== حقول إضافية مخفية (Hidden Fields) ======================== */
    .hidden-field {
        display: none !important;
        /* إخفاء باستخدام !important لضمان التجاوز */
    }

    /* ======================== الحركات (Animations) ======================== */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(15px);
            /* حركة دخول من الأسفل */
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
            /* دخول من اليمين */
        }

        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    /* ======================== تنسيقات الشاشات الصغيرة (Media Queries) ======================== */
    @media (max-width: 768px) {
        .stats-box {
            font-size: 0.9rem;
            /* تصغير حجم الخط للإحصائيات */
            padding: 10px 5px;
        }

        .card-custom {
            margin-bottom: 1rem;
            /* تقليل الهامش السفلي للبطاقات */
        }

        .modal-dialog {
            max-width: 95vw !important;
            /* عرض أكبر للمودال على الشاشات الصغيرة */
        }

        .action-btn {
            font-size: 0.75rem;
            /* تصغير حجم خط أزرار التحكم */
            padding: 4px 8px;
            min-width: unset;
            /* إزالة الحد الأدنى للعرض */
        }

        /* لجعل أزرار الفلترة والفرز في الجداول تلتف بشكل أفضل */
        .card-header .d-flex.gap-2 {
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 0.5rem;
        }

        .table-responsive {
            max-height: 300px;
            /* تقليل ارتفاع الجداول على الموبايل */
        }
    }

    @media (max-width: 576px) {
        .stats-box {
            flex-basis: 48%;
            /* جعل كل صندوقين في سطر واحد على الشاشات الصغيرة جداً */
        }

        #alert-container {
            width: 95%;
            /* عرض الإشعارات يغطي معظم الشاشة */
            right: 2.5%;
            left: 2.5%;
            /* توسيط الإشعارات */
        }
    }

    /*
  ملاحظة هامة:
  بالنسبة لـ `rgba(var(--primary-color-rgb), 0.15);`، ستحتاج إلى تعريف متغيرات CSS إضافية لألوان RGB.
  على سبيل المثال، إذا كان `--primary-color: #0288d1;` (وهو R:2, G:136, B:209)،
  فستحتاج إلى إضافة:
  `--primary-color-rgb: 2, 136, 209;`
  `--secondary-color-rgb: 100, 181, 246;` (R:100, G:181, B:246)
  إلى `:root` و `.dark-mode` لكي تعمل خاصية `rgba` بشكل صحيح مع المتغيرات.
*/
    :root {
        /* ... الألوان والمتغيرات الأخرى ... */
        --primary-color-rgb: 2, 136, 209;
        --secondary-color-rgb: 100, 181, 246;
    }

    .dark-mode {
        /* ... الألوان والمتغيرات الأخرى ... */
        --primary-color-rgb: 144, 202, 249;
        /* R:144, G:202, B:249 */
        --secondary-color-rgb: 66, 165, 245;
        /* R:66, G:165, B:245 */
    }
</style>

<body>
    <button id="darkModeToggle" class="btn"><i class="bi bi-moon-fill"></i> داكن</button>

    <div id="alert-container"></div>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border text-light" role="status" style="width:4rem; height:4rem;">
            <span class="visually-hidden">جاري التحميل...</span>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تأكيد الإجراء</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <p id="confirmMessage">هل أنت متأكد؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-danger-custom" id="confirmYesBtn">تأكيد</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="viewQueriesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">تفاصيل استعلامات الإجازة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-end mb-2 flex-wrap gap-2">
                        <button class="btn btn-danger-custom btn-sm" id="btn-delete-all-queries">
                            <i class="bi bi-trash3-fill"></i> حذف كل الاستعلامات
                        </button>
                        <button class="btn btn-light btn-sm" id="sortQueriesDetailNewest">
                            <i class="bi bi-arrow-down-circle"></i> الأحدث
                        </button>
                        <button class="btn btn-light btn-sm" id="sortQueriesDetailOldest">
                            <i class="bi bi-arrow-up-circle"></i> الأقدم
                        </button>
                        <button class="btn btn-light btn-sm" id="sortQueriesDetailReset">
                            <i class="bi bi-arrow-repeat"></i> الافتراضي
                        </button>
                    </div>
                    <div id="queriesDetailsContainer">
                        <p class="text-center">جارٍ جلب البيانات...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid py-2">
        <div class="row g-2 mb-3">
            <div class="col stats-box">إجمالي الإجازات<br><?= $stats['total'] ?></div>
            <div class="col stats-box">نشطة<br><?= $stats['active'] ?></div>
            <div class="col stats-box">أرشيف<br><?= $stats['archived'] ?></div>
            <div class="col stats-box">المرضى<br><?= $stats['patients'] ?></div>
            <div class="col stats-box">الأطباء<br><?= $stats['doctors'] ?></div>
            <div class="col stats-box">مدفوعة<br><?= $stats['paid'] ?></div>
            <div class="col stats-box">غير مدفوعة<br><?= $stats['unpaid'] ?></div>
            <div class="col stats-box">إجمالي المدفوعات<br><?= number_format($stats['paid_amount'], 2) ?></div>
            <div class="col stats-box">إجمالي غير المدفوعات<br><?= number_format($stats['unpaid_amount'], 2) ?></div>
        </div>

        <div class="mb-3 text-end">
            <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#paymentNotifModal"
                id="btn-payment-notifs">
                <i class="bi bi-bell"></i> إشعارات المدفوعات
            </button>
        </div>

        <div class="mb-2 d-flex gap-2 justify-content-end flex-wrap">
            <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#doctorsModal">
                <i class="bi bi-person-badge-fill"></i> إدارة الأطباء
            </button>
            <button class="btn btn-gradient btn-sm" data-bs-toggle="modal" data-bs-target="#patientsModal">
                <i class="bi bi-person-lines-fill"></i> إدارة المرضى
            </button>
            <button class="btn btn-gradient btn-sm" data-bs-toggle="collapse" data-bs-target="#queriesSection">
                <i class="bi bi-journal-text"></i> سجل الاستعلامات
            </button>
            <button class="btn btn-gradient btn-sm" data-bs-toggle="collapse" data-bs-target="#paymentsSection">
                <i class="bi bi-cash"></i> المدفوعات
            </button>
            <button class="btn btn-info btn-sm" id="exportPDF"><i class="bi bi-file-earmark-pdf-fill"></i> تصدير
                PDF</button>
            <button class="btn btn-success btn-sm" id="exportExcel"><i class="bi bi-file-earmark-excel-fill"></i> تصدير
                Excel</button>
            <button class="btn btn-secondary btn-sm" id="printTable"><i class="bi bi-printer-fill"></i> طباعة</button>
        </div>

        <div class="card card-custom p-3">
            <h5>إضافة إجازة مرضية</h5>
            <form id="leaveForm" class="row g-2 align-items-end needs-validation" novalidate>
                <?= csrf_input(); ?>

                <div class="col-md-3">
                    <label for="service_prefix">بادئة رمز الخدمة</label>
                    <select name="service_prefix" id="service_prefix" class="form-select">
                        <option value="">اختر بادئة</option>
                        <option value="GSL">GSL (مستشفى)</option>
                        <option value="PSL">PSL (مركز)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="service_code_manual">رمز الخدمة يدوي</label>
                    <input type="text" name="service_code_manual" id="service_code_manual" class="form-control"
                        placeholder="أدخل رمز الخدمة يدويًا">
                    <div class="form-text">إذا تُركت هذه الخانة فارغة، سيتم التوليد تلقائيًا.</div>
                </div>
                <div class="col-md-6"></div>

                <div class="col-md-6">
                    <label for="patient_select">ابحث عن مريض</label>
                    <div class="input-group mb-1">
                        <input type="text" id="searchPatient" class="form-control" placeholder="ابحث بالاسم أو الهوية">
                        <button class="btn btn-primary" type="button" id="btn-search-patient"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <select name="patient_select" id="patient_select" class="form-select" required>
                        <option value="">اختر مريضًا</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                                data-identity="<?= htmlspecialchars(strtolower($p['identity_number'])) ?>">
                                <?= htmlspecialchars($p['name'] . ' (' . $p['identity_number'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="manual">إدخال يدوي</option>
                    </select>
                    <div class="invalid-feedback">اختر مريضًا أو أدخله يدويًا.</div>
                    <input type="text" name="patient_manual_name" id="patient_manual_name"
                        class="form-control mt-2 hidden-field" placeholder="اسم المريض الجديد">
                    <input type="text" name="patient_manual_id" id="patient_manual_id"
                        class="form-control mt-1 hidden-field" placeholder="رقم الهوية الجديد">
                    <div class="invalid-feedback">أدخل اسم المريض ورقم هويته.</div>
                    <div id="noPatientResult" class="no-results mt-1" style="display:none;">
                        لم يتم العثور على مريض مطابق.
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="doctor_select">ابحث عن طبيب</label>
                    <div class="input-group mb-1">
                        <input type="text" id="searchDoctor" class="form-control" placeholder="ابحث بالاسم أو المسمى">
                        <button class="btn btn-primary" type="button" id="btn-search-doctor"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <select name="doctor_select" id="doctor_select" class="form-select" required>
                        <option value="">اختر طبيبًا</option>
                        <?php foreach ($doctors as $d): ?>
                            <option value="<?= $d['id'] ?>" data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>"
                                data-title="<?= htmlspecialchars(strtolower($d['title'])) ?>"
                                data-note="<?= htmlspecialchars(strtolower($d['note'] ?? '')) ?>">
                                <?= htmlspecialchars($d['name'] . ' - ' . $d['title']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="manual">إدخال يدوي</option>
                    </select>
                    <div class="invalid-feedback">اختر طبيبًا أو ادخله يدويًا.</div>
                    <input type="text" name="doctor_manual_name" id="doctor_manual_name"
                        class="form-control mt-2 hidden-field" placeholder="اسم الطبيب الجديد">
                    <input type="text" name="doctor_manual_title" id="doctor_manual_title"
                        class="form-control mt-1 hidden-field" placeholder="المسمى الوظيفي الجديد">
                    <textarea name="doctor_manual_note" id="doctor_manual_note" class="form-control mt-1 hidden-field"
                        placeholder="ملاحظة"></textarea>
                    <input type="text" id="doctor_saved_title" class="form-control mt-1 hidden-field" readonly
                        placeholder="المسمى الوظيفي">
                    <textarea id="doctor_saved_note" class="form-control mt-1 hidden-field" readonly
                        placeholder="ملاحظة"></textarea>
                    <div class="invalid-feedback">أدخل اسم الطبيب ومسمّاه الوظيفي.</div>
                    <div id="noDoctorResult" class="no-results mt-1" style="display:none;">
                        لم يتم العثور على طبيب مطابق.
                    </div>
                </div>

                <div class="col-md-4">
                    <label for="issue_date">تاريخ الإصدار</label>
                    <input type="date" name="issue_date" id="issue_date" class="form-control" required>
                    <div class="invalid-feedback">اختر تاريخ الإصدار.</div>
                </div>
                <div class="col-md-4">
                    <label for="start_date">بداية الإجازة</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                    <div class="invalid-feedback">اختر تاريخ بداية الإجازة.</div>
                </div>
                <div class="col-md-4">
                    <label for="end_date">نهاية الإجازة</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                    <div class="invalid-feedback">اختر تاريخ نهاية الإجازة.</div>
                </div>

                <div class="col-md-4">
                    <label for="days_count">عدد الأيام</label>
                    <input type="number" name="days_count" id="days_count" class="form-control" readonly required>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" id="days_manual" name="days_manual" value="1">
                        <label class="form-check-label" for="days_manual" style="font-size:13px">أدخل يدويًا</label>
                    </div>
                    <div class="invalid-feedback">حدد عدد الأيام أو ادخله يدويًا.</div>
                </div>

                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_companion" id="is_companion" value="1">
                        <label class="form-check-label" for="is_companion">إجازة مرافق</label>
                    </div>
                </div>
                <div class="col-md-3 mt-2 companion-fields hidden-field">
                    <label for="companion_name">اسم المرافق</label>
                    <input type="text" name="companion_name" id="companion_name" class="form-control">
                    <div class="invalid-feedback">أدخل اسم المرافق.</div>
                </div>
                <div class="col-md-3 mt-2 companion-fields hidden-field">
                    <label for="companion_relation">صلة القرابة</label>
                    <input type="text" name="companion_relation" id="companion_relation" class="form-control">
                    <div class="invalid-feedback">أدخل صلة القرابة.</div>
                </div>

                <div class="col-md-2">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_paid" id="is_paid" value="1">
                        <label class="form-check-label" for="is_paid">مدفوعة</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="payment_amount">المبلغ</label>
                    <input type="number" step="0.01" name="payment_amount" id="payment_amount" class="form-control"
                        value="0">
                </div>

                <div class="col-12 text-center mt-3">
                    <button type="submit" class="btn btn-gradient w-100">
                        <i class="bi bi-plus-circle"></i> إضافة الإجازة
                    </button>
                </div>
            </form>
        </div>

        <div class="card card-custom mt-4">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
                style="background: var(--secondary-color); color: #fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
                <span class="fw-bold">جميع الإجازات المرضية النشطة</span>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-light btn-sm" id="sortLeavesNewest">
                        <i class="bi bi-arrow-down-circle"></i> الأحدث
                    </button>
                    <button class="btn btn-light btn-sm" id="sortLeavesOldest">
                        <i class="bi bi-arrow-up-circle"></i> الأقدم
                    </button>
                    <button class="btn btn-light btn-sm" id="sortLeavesReset">
                        <i class="bi bi-arrow-repeat"></i> افتراضي
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label for="filter_from_date" class="form-label visually-hidden">من تاريخ</label>
                        <input type="date" id="filter_from_date" class="form-control" placeholder="من تاريخ">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_to_date" class="form-label visually-hidden">إلى تاريخ</label>
                        <input type="date" id="filter_to_date" class="form-control" placeholder="إلى تاريخ">
                    </div>
                    <div class="col-md-6 d-flex gap-2">
                        <button class="btn btn-primary btn-sm" id="btn-filter-dates"><i class="bi bi-funnel"></i>
                            فلترة</button>
                        <button class="btn btn-light btn-sm" id="btn-reset-dates"><i
                                class="bi bi-arrow-counterclockwise"></i> إعادة تعيين</button>
                    </div>
                </div>

                <div class="input-group mb-2">
                    <label for="searchLeaves" class="form-label visually-hidden">بحث</label>
                    <input type="text" id="searchLeaves" class="form-control"
                        placeholder="ابحث برمز الخدمة أو المريض أو الطبيب">
                    <button class="btn btn-primary" type="button" id="btn-search-leaves"><i class="bi bi-search"></i>
                        بحث</button>
                </div>
                <div class="mb-3 d-flex gap-2 flex-wrap">
                    <button class="btn btn-success btn-sm" id="showPaidLeaves">مدفوعة فقط</button>
                    <button class="btn btn-warning btn-sm" id="showUnpaidLeaves">غير مدفوعة فقط</button>
                    <button class="btn btn-light btn-sm" id="showAllLeaves">الكل</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover text-center" id="leavesTable">
                        <thead class="table-light">
                            <tr>
                                <th>رقم</th>
                                <th>رمز الخدمة <i class="bi bi-sort-alpha-down"></i></th>
                                <th>المريض</th>
                                <th>الهوية</th>
                                <th>الطبيب</th>
                                <th>المسمى</th>
                                <th>الملاحظة</th>
                                <th>تاريخ الإصدار</th>
                                <th>من</th>
                                <th>إلى</th>
                                <th>الأيام</th>
                                <th>نوع الإجازة</th>
                                <th>عدد الاستعلامات</th>
                                <th>تاريخ الإضافة <i class="bi bi-sort-down"></i></th>
                                <th>مدفوعة؟</th>
                                <th>المبلغ</th>
                                <th style="min-width:300px;">تحكم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $idx => $lv): ?>
                                <tr data-id="<?= $lv['id'] ?>" data-patient="<?= $lv['patient_id'] ?>"
                                    data-comp-name="<?= htmlspecialchars($lv['companion_name'] ?? '') ?>"
                                    data-comp-rel="<?= htmlspecialchars($lv['companion_relation'] ?? '') ?>">
                                    <td class="row-num"></td>
                                    <td class="cell-service"><?= htmlspecialchars(strtoupper($lv['service_code'])) ?></td>
                                    <td class="cell-patient"><?= htmlspecialchars($lv['patient_name']) ?></td>
                                    <td class="cell-identity"><?= htmlspecialchars($lv['identity_number']) ?></td>
                                    <td class="cell-doctor"><?= htmlspecialchars($lv['doctor_name']) ?></td>
                                    <td><?= htmlspecialchars($lv['doctor_title']) ?></td>
                                    <td><?= htmlspecialchars($lv['doctor_note'] ?? '') ?></td>
                                    <td class="cell-issue"><?= htmlspecialchars($lv['issue_date']) ?></td>
                                    <td><?= htmlspecialchars($lv['start_date']) ?></td>
                                    <td><?= htmlspecialchars($lv['end_date']) ?></td>
                                    <td><?= htmlspecialchars($lv['days_count']) ?></td>
                                    <td>
                                        <?= $lv['is_companion']
                                            ? '<span class="badge bg-warning text-dark">مرافق</span>'
                                            : '<span class="badge bg-info text-dark">أساسي</span>' ?>
                                    </td>
                                    <td class="cell-queries-count"><?= $lv['queries_count'] ?></td>
                                    <td class="cell-created"><?= htmlspecialchars($lv['created_at']) ?></td>
                                    <td><?= $lv['is_paid'] ? 'نعم' : 'لا' ?></td>
                                    <td><?= number_format($lv['payment_amount'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-info btn-sm action-btn btn-edit-leave"><i
                                                class="bi bi-pencil-square"></i> تعديل</button>
                                        <button class="btn btn-danger btn-sm action-btn btn-delete-leave"><i
                                                class="bi bi-archive-fill"></i> أرشفة</button>
                                        <button class="btn btn-warning btn-sm action-btn btn-view-queries"><i
                                                class="bi bi-journal-text"></i> استعلامات</button>
                                        <button
                                            class="btn btn-success btn-sm action-btn btn-mark-paid <?= $lv['is_paid'] ? 'd-none' : '' ?>"><i
                                                class="bi bi-cash-stack"></i> دفع</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($leaves)): ?>
                                <tr class="no-results">
                                    <td colspan="17">لا توجد إجازات نشطة حاليًا.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card card-custom mt-4 mb-5">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
                style="background: var(--danger-color); color: #fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
                <span class="fw-bold">الأرشيف (الإجازات المؤرشفة)</span>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-light btn-sm" id="sortArchivedNewest">
                        <i class="bi bi-arrow-down-circle"></i> الأحدث
                    </button>
                    <button class="btn btn-light btn-sm" id="sortArchivedOldest">
                        <i class="bi bi-arrow-up-circle"></i> الأقدم
                    </button>
                    <button class="btn btn-light btn-sm" id="sortArchivedReset">
                        <i class="bi bi-arrow-repeat"></i> افتراضي
                    </button>
                    <button class="btn btn-danger btn-sm" id="btn-delete-all-archived">
                        <i class="bi bi-trash3-fill"></i> حذف الكل نهائيًا
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label for="filter_arch_from_date" class="form-label visually-hidden">من تاريخ الحذف</label>
                        <input type="date" id="filter_arch_from_date" class="form-control" placeholder="من تاريخ الحذف">
                    </div>
                    <div class="col-md-3">
                        <label for="filter_arch_to_date" class="form-label visually-hidden">إلى تاريخ الحذف</label>
                        <input type="date" id="filter_arch_to_date" class="form-control" placeholder="إلى تاريخ الحذف">
                    </div>
                    <div class="col-md-6 d-flex gap-2">
                        <button class="btn btn-primary btn-sm" id="btn-filter-arch-dates"><i class="bi bi-funnel"></i>
                            فلترة</button>
                        <button class="btn btn-light btn-sm" id="btn-reset-arch-dates"><i
                                class="bi bi-arrow-counterclockwise"></i> إعادة تعيين</button>
                    </div>
                </div>

                <div class="input-group mb-2">
                    <label for="searchArchived" class="form-label visually-hidden">بحث</label>
                    <input type="text" id="searchArchived" class="form-control"
                        placeholder="ابحث برمز الخدمة أو المريض أو الطبيب">
                    <button class="btn btn-primary" type="button" id="btn-search-archived"><i class="bi bi-search"></i>
                        بحث</button>
                </div>
                <div class="mb-3 d-flex gap-2 flex-wrap">
                    <button class="btn btn-success btn-sm" id="showPaidArchived">مدفوعة فقط</button>
                    <button class="btn btn-warning btn-sm" id="showUnpaidArchived">غير مدفوعة فقط</button>
                    <button class="btn btn-light btn-sm" id="showAllArchived">الكل</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover text-center" id="archivedTable">
                        <thead class="table-light">
                            <tr>
                                <th>رقم</th>
                                <th>رمز الخدمة <i class="bi bi-sort-alpha-down"></i></th>
                                <th>المريض</th>
                                <th>الهوية</th>
                                <th>الطبيب</th>
                                <th>المسمى</th>
                                <th>الملاحظة</th>
                                <th>تاريخ الإصدار</th>
                                <th>من</th>
                                <th>إلى</th>
                                <th>الأيام</th>
                                <th>نوع الإجازة</th>
                                <th>عدد الاستعلامات</th>
                                <th>تاريخ الحذف <i class="bi bi-sort-down"></i></th>
                                <th>مدفوعة؟</th>
                                <th>المبلغ</th>
                                <th style="min-width:260px;">تحكم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($archived)): ?>
                                <tr class="no-results">
                                    <td colspan="17">لا توجد إجازات في الأرشيف.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($archived as $idx => $lv): ?>
                                    <tr data-id="<?= $lv['id'] ?>" data-patient="<?= $lv['patient_id'] ?>"
                                        data-comp-name="<?= htmlspecialchars($lv['companion_name'] ?? '') ?>"
                                        data-comp-rel="<?= htmlspecialchars($lv['companion_relation'] ?? '') ?>">
                                        <td class="row-num"></td>
                                        <td class="cell-service"><?= htmlspecialchars(strtoupper($lv['service_code'])) ?></td>
                                        <td class="cell-patient"><?= htmlspecialchars($lv['patient_name']) ?></td>
                                        <td class="cell-identity"><?= htmlspecialchars($lv['identity_number']) ?></td>
                                        <td class="cell-doctor"><?= htmlspecialchars($lv['doctor_name']) ?></td>
                                        <td><?= htmlspecialchars($lv['doctor_title']) ?></td>
                                        <td><?= htmlspecialchars($lv['doctor_note'] ?? '') ?></td>
                                        <td class="cell-issue"><?= htmlspecialchars($lv['issue_date']) ?></td>
                                        <td><?= htmlspecialchars($lv['start_date']) ?></td>
                                        <td><?= htmlspecialchars($lv['end_date']) ?></td>
                                        <td><?= htmlspecialchars($lv['days_count']) ?></td>
                                        <td>
                                            <?= $lv['is_companion']
                                                ? '<span class="badge bg-warning text-dark">مرافق</span>'
                                                : '<span class="badge bg-info text-dark">أساسي</span>' ?>
                                        </td>
                                        <td class="cell-queries-count"><?= $lv['queries_count'] ?></td>
                                        <td class="cell-deleted"><?= htmlspecialchars($lv['deleted_at']) ?></td>
                                        <td><?= $lv['is_paid'] ? 'نعم' : 'لا' ?></td>
                                        <td><?= number_format($lv['payment_amount'], 2) ?></td>
                                        <td>
                                            <button class="btn btn-success btn-sm action-btn btn-restore-leave"><i
                                                    class="bi bi-arrow-counterclockwise"></i> استعادة</button>
                                            <button class="btn btn-danger btn-sm action-btn btn-force-delete-leave"><i
                                                    class="bi bi-x-circle"></i>
                                                حذف نهائي</button>
                                            <button class="btn btn-warning btn-sm action-btn btn-view-queries"><i
                                                    class="bi bi-journal-text"></i>
                                                استعلامات</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="modal fade" id="paymentNotifModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">إشعارات المدفوعات</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex justify-content-end mb-2">
                            <button class="btn btn-secondary btn-sm" id="refreshNotifs"><i
                                    class="bi bi-arrow-repeat"></i> تحديث</button>
                        </div>
                        <ul id="notifPayments" class="list-group">
                            <?php foreach ($notifications_payment as $n): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center"
                                    data-leave="<?= $n['leave_id'] ?>" data-id="<?= $n['id'] ?>"
                                    data-amount="<?= number_format($n['payment_amount'], 2, '.', '') ?>">
                                    <span><?= htmlspecialchars($n['message']) ?></span>
                                    <div class="btn-group">
                                        <button class="btn btn-info btn-sm btn-view-leave"
                                            data-leave="<?= $n['leave_id'] ?>"><i class="bi bi-info-circle"></i>
                                            تفاصيل</button>
                                        <button class="btn btn-success btn-sm btn-pay-notif"
                                            data-leave="<?= $n['leave_id'] ?>"><i class="bi bi-cash-stack"></i>
                                            مدفوعة</button>
                                        <button class="btn btn-danger btn-sm btn-del-notif" data-id="<?= $n['id'] ?>"><i
                                                class="bi bi-trash-fill"></i> حذف</button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($notifications_payment)): ?>
                                <li class="list-group-item">لا توجد إشعارات مدفوعات حاليًا.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="leaveDetailsModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">تفاصيل الإجازة</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                    </div>
                    <div class="modal-body" id="leaveDetailsContainer">
                        <p class="text-center">لا توجد بيانات</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="collapse" id="queriesSection">
            <div class="card card-custom mt-4 mb-5">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
                    style="background: var(--warning-color); color: #000; border-radius: var(--border-radius) var(--border-radius) 0 0;">
                    <span class="fw-bold">سجل الاستعلامات</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-light btn-sm" id="sortQueriesNewest">
                            <i class="bi bi-arrow-down-circle"></i> الأحدث
                        </button>
                        <button class="btn btn-light btn-sm" id="sortQueriesOldest">
                            <i class="bi bi-arrow-up-circle"></i> الأقدم
                        </button>
                        <button class="btn btn-light btn-sm" id="sortQueriesReset">
                            <i class="bi bi-arrow-repeat"></i> افتراضي
                        </button>
                        <button class="btn btn-danger btn-sm" id="deleteAllQueries">
                            <i class="bi bi-trash3-fill"></i> حذف كل السجلات
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label for="filter_q_from_date" class="form-label visually-hidden">من تاريخ
                                الاستعلام</label>
                            <input type="date" id="filter_q_from_date" class="form-control"
                                placeholder="من تاريخ الاستعلام">
                        </div>
                        <div class="col-md-3">
                            <label for="filter_q_to_date" class="form-label visually-hidden">إلى تاريخ الاستعلام</label>
                            <input type="date" id="filter_q_to_date" class="form-control"
                                placeholder="إلى تاريخ الاستعلام">
                        </div>
                        <div class="col-md-6 d-flex gap-2">
                            <button class="btn btn-primary btn-sm" id="btn-filter-queries-dates"><i
                                    class="bi bi-funnel"></i> فلترة</button>
                            <button class="btn btn-light btn-sm" id="btn-reset-queries-dates"><i
                                    class="bi bi-arrow-counterclockwise"></i> إعادة تعيين</button>
                        </div>
                    </div>

                    <div class="input-group mb-2">
                        <label for="searchQueries" class="form-label visually-hidden">بحث</label>
                        <input type="text" id="searchQueries" class="form-control"
                            placeholder="ابحث برمز الخدمة أو المريض أو الهوية">
                        <button class="btn btn-primary" type="button" id="btn-search-queries"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center" id="queriesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم</th>
                                    <th>رمز الخدمة <i class="bi bi-sort-alpha-down"></i></th>
                                    <th>المريض</th>
                                    <th>الهوية</th>
                                    <th>وقت الاستعلام <i class="bi bi-sort-down"></i></th>
                                    <th>تحكم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($queries)): ?>
                                    <tr class="no-results">
                                        <td colspan="6">لا توجد سجلات للاستعلام.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($queries as $idx => $q): ?>
                                        <tr data-id="<?= $q['qid'] ?>">
                                            <td class="row-num"></td>
                                            <td class="cell-service"><?= htmlspecialchars(strtoupper($q['service_code'])) ?>
                                            </td>
                                            <td class="cell-patient"><?= htmlspecialchars($q['patient_name']) ?></td>
                                            <td class="cell-identity"><?= htmlspecialchars($q['identity_number']) ?></td>
                                            <td class="cell-queried"><?= htmlspecialchars($q['queried_at']) ?></td>
                                            <td>
                                                <button class="btn btn-danger btn-sm action-btn btn-delete-query"><i
                                                        class="bi bi-trash-fill"></i> حذف</button>
                                                <button class="btn btn-info btn-sm action-btn btn-view-leave-from-query"
                                                    data-leave-id="<?= $q['leave_id'] ?>"><i class="bi bi-info-circle"></i>
                                                    تفاصيل إجازة</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="collapse" id="paymentsSection">
            <div class="card card-custom mt-4 mb-5">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"
                    style="background: var(--success-color); color:#fff; border-radius: var(--border-radius) var(--border-radius) 0 0;">
                    <span class="fw-bold">إحصائيات المدفوعات لكل مريض</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-light btn-sm" id="sortPaymentsPaid"><i
                                class="bi bi-sort-numeric-down"></i> أعلى المدفوعات</button>
                        <button class="btn btn-light btn-sm" id="sortPaymentsUnpaid"><i
                                class="bi bi-sort-numeric-down"></i> أعلى غير المدفوعة</button>
                        <button class="btn btn-light btn-sm" id="sortPaymentsReset"><i class="bi bi-arrow-repeat"></i>
                            افتراضي</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="input-group mb-2">
                        <label for="searchPayments" class="form-label visually-hidden">بحث</label>
                        <input type="text" id="searchPayments" class="form-control" placeholder="ابحث باسم المريض">
                        <button class="btn btn-primary" type="button" id="btn-search-payments"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center" id="paymentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم</th>
                                    <th>المريض</th>
                                    <th>الإجازات الكلية</th>
                                    <th>مدفوعة</th>
                                    <th>غير مدفوعة</th>
                                    <th>إجمالي المدفوع</th>
                                    <th>إجمالي غير المدفوع</th>
                                    <th>عرض الإجازات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $idx => $p): ?>
                                    <tr data-id="<?= $p['id'] ?>">
                                        <td class="row-num"></td>
                                        <td><?= htmlspecialchars($p['name']) ?></td>
                                        <td><?= $p['total'] ?></td>
                                        <td><?= $p['paid_count'] ?></td>
                                        <td><?= $p['unpaid_count'] ?></td>
                                        <td><?= number_format($p['paid_amount'], 2) ?></td>
                                        <td><?= number_format($p['unpaid_amount'], 2) ?></td>
                                        <td><button class="btn btn-info btn-sm btn-view-patient-leaves"><i
                                                    class="bi bi-eye-fill"></i> عرض</button></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payments)): ?>
                                    <tr class="no-results">
                                        <td colspan="8">لا توجد بيانات مدفوعات.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="doctorsModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content p-3">
                    <h5>قائمة الأطباء
                        <button class="btn btn-success-custom btn-sm float-end" id="btn-show-add-doctor">
                            <i class="bi bi-person-plus-fill"></i> إضافة
                        </button>
                    </h5>
                    <div class="input-group mb-2">
                        <label for="searchDoctorsTable" class="form-label visually-hidden">بحث</label>
                        <input type="text" id="searchDoctorsTable" class="form-control"
                            placeholder="ابحث بالاسم أو المسمى">
                        <button class="btn btn-primary" type="button" id="btn-search-doctors"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center" id="doctorsTable">
                            <thead>
                                <tr>
                                    <th>رقم</th>
                                    <th>الاسم <i class="bi bi-sort-alpha-down"></i></th>
                                    <th>المسمى</th>
                                    <th>الملاحظة</th>
                                    <th>تحكم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doctors as $idx => $d): ?>
                                    <tr data-id="<?= $d['id'] ?>">
                                        <td class="row-num"></td>
                                        <td><?= htmlspecialchars($d['name']) ?></td>
                                        <td><?= htmlspecialchars($d['title']) ?></td>
                                        <td><?= htmlspecialchars($d['note'] ?? '') ?></td>
                                        <td>
                                            <button class="btn btn-warning btn-sm action-btn btn-edit-doctor"><i
                                                    class="bi bi-pencil-square"></i> تعديل</button>
                                            <button class="btn btn-danger btn-sm action-btn btn-delete-doctor"><i
                                                    class="bi bi-trash-fill"></i> حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($doctors)): ?>
                                    <tr class="no-results">
                                        <td colspan="5">لا يوجد أطباء حاليًا.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <form class="row g-2 mt-3 needs-validation" id="doctorForm" style="display:none;" novalidate>
                        <?= csrf_input(); ?>
                        <input type="hidden" id="doctor_form_id" name="doctor_id">
                        <div class="col-md-4">
                            <label for="doctor_form_name" class="form-label visually-hidden">اسم الطبيب</label>
                            <input type="text" id="doctor_form_name" name="doctor_name" class="form-control"
                                placeholder="اسم الطبيب" required>
                            <div class="invalid-feedback">أدخل اسم الطبيب.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="doctor_form_title" class="form-label visually-hidden">المسمى الوظيفي</label>
                            <input type="text" id="doctor_form_title" name="doctor_title" class="form-control"
                                placeholder="المسمى الوظيفي" required>
                            <div class="invalid-feedback">أدخل المسمى الوظيفي.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="doctor_form_note" class="form-label visually-hidden">ملاحظة</label>
                            <input type="text" id="doctor_form_note" name="doctor_note" class="form-control"
                                placeholder="ملاحظة">
                        </div>
                        <div class="col-md-12 d-flex gap-1">
                            <button type="submit" class="btn btn-success-custom w-100"><i class="bi bi-save-fill"></i>
                                حفظ</button>
                            <button type="button" class="btn btn-secondary w-100" id="btn-cancel-doctor"><i
                                    class="bi bi-x-circle"></i> إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="patientsModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content p-3">
                    <h5>قائمة المرضى
                        <button class="btn btn-success-custom btn-sm float-end" id="btn-show-add-patient"><i
                                class="bi bi-person-plus-fill"></i> إضافة</button>
                    </h5>
                    <div class="input-group mb-2">
                        <label for="searchPatientsTable" class="form-label visually-hidden">بحث</label>
                        <input type="text" id="searchPatientsTable" class="form-control"
                            placeholder="ابحث بالاسم أو الهوية">
                        <button class="btn btn-primary" type="button" id="btn-search-patients"><i
                                class="bi bi-search"></i> بحث</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover text-center" id="patientsTable">
                            <thead>
                                <tr>
                                    <th>رقم</th>
                                    <th>الاسم <i class="bi bi-sort-alpha-down"></i></th>
                                    <th>الهوية</th>
                                    <th>تحكم</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $idx => $p): ?>
                                    <tr data-id="<?= $p['id'] ?>">
                                        <td class="row-num"></td>
                                        <td><?= htmlspecialchars($p['name']) ?></td>
                                        <td><?= htmlspecialchars($p['identity_number']) ?></td>
                                        <td>
                                            <button class="btn btn-warning btn-sm action-btn btn-edit-patient"><i
                                                    class="bi bi-pencil-square"></i> تعديل</button>
                                            <button class="btn btn-danger btn-sm action-btn btn-delete-patient"><i
                                                    class="bi bi-trash-fill"></i> حذف</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($patients)): ?>
                                    <tr class="no-results">
                                        <td colspan="4">لا يوجد مرضى حاليًا.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <form class="row g-2 mt-3 needs-validation" id="patientForm" style="display:none;" novalidate>
                        <?= csrf_input(); ?>
                        <input type="hidden" id="patient_form_id" name="patient_id">
                        <div class="col-md-5">
                            <label for="patient_form_name" class="form-label visually-hidden">اسم المريض</label>
                            <input type="text" id="patient_form_name" name="patient_name" class="form-control"
                                placeholder="اسم المريض" required>
                            <div class="invalid-feedback">أدخل اسم المريض.</div>
                        </div>
                        <div class="col-md-5">
                            <label for="patient_form_identity" class="form-label visually-hidden">رقم الهوية</label>
                            <input type="text" id="patient_form_identity" name="identity_number" class="form-control"
                                placeholder="رقم الهوية" required>
                            <div class="invalid-feedback">أدخل رقم الهوية.</div>
                        </div>
                        <div class="col-md-2 d-flex gap-1">
                            <button type="submit" class="btn btn-success-custom w-100"><i class="bi bi-save-fill"></i>
                                حفظ</button>
                            <button type="button" class="btn btn-secondary w-100" id="btn-cancel-patient"><i
                                    class="bi bi-x-circle"></i> إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editLeaveModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content p-3">
                    <h5>تعديل الإجازة</h5>
                    <form id="editLeaveForm" class="row g-2 needs-validation" novalidate>
                        <?= csrf_input(); ?>
                        <input type="hidden" id="leave_id_edit" name="leave_id_edit">
                        <div class="col-md-3">
                            <label for="service_code_edit">رمز الخدمة</label>
                            <input type="text" id="service_code_edit" name="service_code_edit" class="form-control"
                                required>
                            <div class="invalid-feedback">أدخل رمز الخدمة.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="issue_date_edit">تاريخ الإصدار</label>
                            <input type="date" id="issue_date_edit" name="issue_date_edit" class="form-control"
                                required>
                            <div class="invalid-feedback">اختر تاريخ الإصدار.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="patient_edit">المريض</label>
                            <input type="text" id="patient_edit" class="form-control" readonly>
                        </div>
                        <div class="col-md-3">
                            <label for="doctor_edit">الطبيب</label>
                            <input type="text" id="doctor_edit" class="form-control" readonly>
                        </div>
                        <div class="col-md-3">
                            <label for="doctor_note_edit">ملاحظة الطبيب</label>
                            <input type="text" id="doctor_note_edit" class="form-control" readonly>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date_edit">بداية الإجازة</label>
                            <input type="date" id="start_date_edit" name="start_date_edit" class="form-control"
                                required>
                            <div class="invalid-feedback">اختر تاريخ البداية.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date_edit">نهاية الإجازة</label>
                            <input type="date" id="end_date_edit" name="end_date_edit" class="form-control" required>
                            <div class="invalid-feedback">اختر تاريخ النهاية.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="days_count_edit">عدد الأيام</label>
                            <input type="number" name="days_count_edit" id="days_count_edit" class="form-control"
                                readonly required>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="days_manual_edit"
                                    name="days_manual_edit" value="1">
                                <label class="form-check-label" for="days_manual_edit" style="font-size:13px">أدخل
                                    يدويًا</label>
                            </div>
                            <div class="invalid-feedback">حدد عدد الأيام.</div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="is_companion_edit"
                                    id="is_companion_edit" value="1">
                                <label class="form-check-label" for="is_companion_edit">إجازة مرافق</label>
                            </div>
                        </div>
                        <div class="col-md-3 mt-2 companion-fields-edit hidden-field">
                            <label for="companion_name_edit">اسم المرافق</label>
                            <input type="text" name="companion_name_edit" id="companion_name_edit" class="form-control">
                            <div class="invalid-feedback">أدخل اسم المرافق.</div>
                        </div>
                        <div class="col-md-3 mt-2 companion-fields-edit hidden-field">
                            <label for="companion_relation_edit">صلة القرابة</label>
                            <input type="text" name="companion_relation_edit" id="companion_relation_edit"
                                class="form-control">
                            <div class="invalid-feedback">أدخل صلة القرابة.</div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="is_paid_edit" id="is_paid_edit"
                                    value="1">
                                <label class="form-check-label" for="is_paid_edit">مدفوعة</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="payment_amount_edit">المبلغ</label>
                            <input type="number" step="0.01" name="payment_amount_edit" id="payment_amount_edit"
                                class="form-control" value="0">
                        </div>
                        <div class="col-12 text-center mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success-custom w-50"><i class="bi bi-save-fill"></i>
                                حفظ التعديلات</button>
                            <button type="button" class="btn btn-secondary w-50" data-bs-dismiss="modal"><i
                                    class="bi bi-x-circle"></i> إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script>
        /*
 * ملف: script.js
 * المهام: معالجة التفاعلات على الواجهة الأمامية (الجافاسكريبت).
 * - التعامل مع طلبات AJAX لإدارة الأطباء، المرضى، الإجازات، وسجل الاستعلامات.
 * - تحديث الجداول والإحصائيات ديناميكيًا.
 * - وظائف البحث، الفرز، والفلترة بدون إعادة تحميل الصفحة.
 * - إدارة الوضع الفاتح/الداكن.
 * - عرض رسائل التنبيه (Toasts) ومؤشرات التحميل.
 * - وظائف الطباعة والتصدير (PDF, Excel).
 */

        // ======================== إعدادات عامة ومتغيرات DOM ========================
        document.addEventListener('DOMContentLoaded', () => {
            // تحديد عناصر DOM الرئيسية
            const leaveForm = document.getElementById('leaveForm');
            const editLeaveForm = document.getElementById('editLeaveForm');
            const doctorsTable = document.getElementById('doctorsTable');
            const patientsTable = document.getElementById('patientsTable');
            const leavesTable = document.getElementById('leavesTable');
            const archivedTable = document.getElementById('archivedTable');
            const queriesTable = document.getElementById('queriesTable');
            const paymentsTable = document.getElementById('paymentsTable');

            const patientSelect = document.getElementById('patient_select');
            const patientManualName = document.getElementById('patient_manual_name');
            const patientManualId = document.getElementById('patient_manual_id');
            const searchPatientInput = document.getElementById('searchPatient');
            const noPatientResult = document.getElementById('noPatientResult');

            const doctorSelect = document.getElementById('doctor_select');
            const doctorManualName = document.getElementById('doctor_manual_name');
            const doctorManualTitle = document.getElementById('doctor_manual_title');
            const doctorManualNote = document.getElementById('doctor_manual_note');
            const doctorSavedTitle = document.getElementById('doctor_saved_title');
            const doctorSavedNote = document.getElementById('doctor_saved_note');
            const searchDoctorInput = document.getElementById('searchDoctor');
            const noDoctorResult = document.getElementById('noDoctorResult');

            const issueDateInput = document.getElementById('issue_date');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const daysCountInput = document.getElementById('days_count');
            const daysManualCheckbox = document.getElementById('days_manual');

            const companionCheckbox = document.getElementById('is_companion');
            const companionFields = document.querySelectorAll('.companion-fields');
            const companionNameInput = document.getElementById('companion_name');
            const companionRelationInput = document.getElementById('companion_relation');

            const serviceCodeManualInput = document.getElementById('service_code_manual');
            const servicePrefixSelect = document.getElementById('service_prefix');

            const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
            const confirmMessage = document.getElementById('confirmMessage');
            const confirmYesBtn = document.getElementById('confirmYesBtn');

            const viewQueriesModal = new bootstrap.Modal(document.getElementById('viewQueriesModal'));
            const queriesDetailsContainer = document.getElementById('queriesDetailsContainer');

            const paymentNotifModal = new bootstrap.Modal(document.getElementById('paymentNotifModal'));
            const notifPaymentsList = document.getElementById('notifPayments');

            const leaveDetailsModal = new bootstrap.Modal(document.getElementById('leaveDetailsModal'));
            const leaveDetailsContainer = document.getElementById('leaveDetailsContainer');

            const doctorsModal = new bootstrap.Modal(document.getElementById('doctorsModal'));
            const patientsModal = new bootstrap.Modal(document.getElementById('patientsModal'));
            const editLeaveModal = new bootstrap.Modal(document.getElementById('editLeaveModal'));

            const loadingOverlay = document.getElementById('loadingOverlay');
            const alertContainer = document.getElementById('alert-container');

            let currentConfirmAction = null;
            let currentConfirmId = null;
            let currentTableData = {
                leaves: [],
                archived: [],
                queries: [],
                doctors: [],
                patients: [],
                payments: []
            }; // لتخزين البيانات الحالية للجداول

            // ======================== دوال مساعدة (Helper Functions) ========================

            /**
             * يعرض رسالة تنبيه (Toast) للمستخدم.
             * @param {string} message - نص الرسالة.
             * @param {'success'|'danger'|'info'|'warning'} type - نوع الرسالة لتحديد اللون.
             */
            function showToast(message, type = 'info') {
                const toast = document.createElement('div');
                toast.className = `toast align-items-center text-white bg-${type} border-0`;
                toast.setAttribute('role', 'alert');
                toast.setAttribute('aria-live', 'assertive');
                toast.setAttribute('aria-atomic', 'true');
                toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
                alertContainer.append(toast);
                const bsToast = new bootstrap.Toast(toast, {
                    delay: 5000
                });
                bsToast.show();
                toast.addEventListener('hidden.bs.toast', () => toast.remove());
            }

            /**
             * يعرض مؤشر التحميل.
             */
            function showLoading() {
                loadingOverlay.classList.add('show');
            }

            /**
             * يخفي مؤشر التحميل.
             */
            function hideLoading() {
                loadingOverlay.classList.remove('show');
            }

            /**
             * يرسل طلب AJAX إلى الخادم.
             * @param {string} action - الإجراء المطلوب تنفيذه في السيرفر.
             * @param {FormData | URLSearchParams | object} data - البيانات المراد إرسالها.
             * @returns {Promise<object>} - وعد (Promise) بالاستجابة من السيرفر.
             */
            async function sendAjaxRequest(action, data) {
                showLoading();
                let formData;
                if (data instanceof FormData) {
                    formData = data;
                } else if (data instanceof URLSearchParams) {
                    formData = data; // يمكن استخدامها مباشرة إذا كانت URLSearchParams
                } else {
                    formData = new FormData();
                    for (const key in data) {
                        formData.append(key, data[key]);
                    }
                }
                formData.append('action', action); // إضافة الإجراء إلى البيانات

                // إضافة CSRF token
                const csrfToken = document.querySelector('input[name="csrf_token"]');
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken.value);
                }

                try {
                    const response = await fetch('admin_dashboard.php', {
                        method: 'POST',
                        body: formData,
                    });
                    if (!response.ok) {
                        // إذا لم تكن الاستجابة OK (مثل 404, 500)، ألقِ خطأ
                        const errorText = await response.text();
                        throw new Error(`HTTP error! status: ${response.status}, message: ${errorText}`);
                    }
                    const result = await response.json();
                    if (!result.success) {
                        showToast(result.message || 'حدث خطأ غير معروف.', 'danger');
                    }
                    return result;
                } catch (error) {
                    console.error('AJAX request failed:', error);
                    showToast('فشل في الاتصال بالخادم. يرجى المحاولة لاحقاً.', 'danger');
                    return {
                        success: false,
                        message: 'فشل في الاتصال بالخادم.'
                    };
                } finally {
                    hideLoading();
                }
            }

            /**
             * يقوم بتحديث الأرقام الإحصائية في لوحة التحكم.
             * @param {object} stats - كائن يحتوي على الإحصائيات الجديدة.
             */
            function updateStats(stats) {
                if (!stats) return;

                document.querySelector('.stats-box:nth-child(1) br').nextSibling.textContent = stats.total;
                document.querySelector('.stats-box:nth-child(2) br').nextSibling.textContent = stats.active;
                document.querySelector('.stats-box:nth-child(3) br').nextSibling.textContent = stats.archived;
                document.querySelector('.stats-box:nth-child(4) br').nextSibling.textContent = stats.patients;
                document.querySelector('.stats-box:nth-child(5) br').nextSibling.textContent = stats.doctors;
                document.querySelector('.stats-box:nth-child(6) br').nextSibling.textContent = stats.paid;
                document.querySelector('.stats-box:nth-child(7) br').nextSibling.textContent = stats.unpaid;
                document.querySelector('.stats-box:nth-child(8) br').nextSibling.textContent = parseFloat(stats.paid_amount).toFixed(2);
                document.querySelector('.stats-box:nth-child(9) br').nextSibling.textContent = parseFloat(stats.unpaid_amount).toFixed(2);
            }

            /**
             * يحدّث أرقام الصفوف في جدول معين.
             * @param {HTMLTableElement} table - عنصر الجدول.
             */
            function updateRowNumbers(table) {
                const rows = table.querySelectorAll('tbody tr:not(.no-results)');
                rows.forEach((row, index) => {
                    const numCell = row.querySelector('.row-num');
                    if (numCell) {
                        numCell.textContent = index + 1;
                    }
                });
            }

            /**
             * يقوم بتنسيق التاريخ إلى 'YYYY-MM-DD'.
             * @param {string} dateString - سلسلة التاريخ.
             * @returns {string} - التاريخ المنسق.
             */
            function formatDate(dateString) {
                if (!dateString) return '';
                const date = new Date(dateString);
                return date.toISOString().split('T')[0];
            }

            /**
             * يقوم بتنسيق التاريخ والوقت إلى 'YYYY-MM-DD HH:MM AM/PM' بتوقيت مكة.
             * @param {string} dateTimeString - سلسلة التاريخ والوقت.
             * @returns {string} - التاريخ والوقت المنسق.
             */
            function formatDateTime(dateTimeString) {
                if (!dateTimeString) return '';
                const date = new Date(dateTimeString);
                // لضمان التوقيت الصحيح (مكة المكرمة GMT+3) يفضل أن يكون التنسيق من السيرفر مباشرة
                // أو استخدام مكتبة توقيت مثل moment.js أو luxon.js
                // بما أن السيرفر يرسل بتوقيت مكة، سنفترض أن هذه الدالة ستعرضها كما هي أو بتنسيق بسيط
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                let hours = date.getHours();
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                const ampm = hours >= 12 ? 'م' : 'ص';
                hours = hours % 12;
                hours = hours ? hours : 12; // الساعة '0' يجب أن تكون '12'
                return `${year}-${month}-${day} ${hours}:${minutes}:${seconds} ${ampm}`;
            }

            /**
             * يحسب عدد الأيام بين تاريخين.
             * @param {string} start - تاريخ البداية (YYYY-MM-DD).
             * @param {string} end - تاريخ النهاية (YYYY-MM-DD).
             * @returns {number} - عدد الأيام، 0 إذا كان التاريخ غير صالح.
             */
            function calculateDays(start, end) {
                if (!start || !end) return 0;
                const startDate = new Date(start);
                const endDate = new Date(end);
                if (isNaN(startDate) || isNaN(endDate) || endDate < startDate) return 0;
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 لتضمين يوم البداية والنهاية
                return diffDays;
            }

            /**
             * لمسح جميع حقول النموذج.
             * @param {HTMLFormElement} form - عنصر النموذج.
             */
            function clearForm(form) {
                form.reset();
                form.classList.remove('was-validated');
                // إخفاء الحقول اليدوية والتأكد من إعدادات days_count
                document.querySelectorAll(`#${form.id} .hidden-field`).forEach(el => el.style.display = 'none');
                if (form.id === 'leaveForm') {
                    daysManualCheckbox.checked = false;
                    daysCountInput.readOnly = true;
                    companionCheckbox.checked = false;
                    companionFields.forEach(el => el.style.display = 'none');
                    patientSelect.value = '';
                    doctorSelect.value = '';
                    searchPatientInput.value = '';
                    searchDoctorInput.value = '';
                    noPatientResult.style.display = 'none';
                    noDoctorResult.style.display = 'none';
                } else if (form.id === 'editLeaveForm') {
                    document.getElementById('days_manual_edit').checked = false;
                    document.getElementById('days_count_edit').readOnly = true;
                    document.getElementById('is_companion_edit').checked = false;
                    document.querySelectorAll('.companion-fields-edit').forEach(el => el.style.display = 'none');
                } else if (form.id === 'doctorForm' || form.id === 'patientForm') {
                    form.style.display = 'none';
                }
            }

            /**
             * يقوم بتحديث عنصر tbody في جدول معين بالبيانات الجديدة.
             * @param {HTMLTableElement} tableElement - عنصر الجدول.
             * @param {Array<object>} data - مصفوفة البيانات الجديدة.
             * @param {function(object): string} rowGenerator - دالة تولّد HTML لصف واحد.
             */
            function updateTable(tableElement, data, rowGenerator) {
                const tbody = tableElement.querySelector('tbody');
                tbody.innerHTML = ''; // مسح المحتوى الحالي

                if (data.length === 0) {
                    const colspan = tableElement.querySelector('thead tr').children.length;
                    tbody.innerHTML = `<tr class="no-results"><td colspan="${colspan}">لا توجد نتائج مطابقة.</td></tr>`;
                    return;
                }

                data.forEach((item) => {
                    tbody.insertAdjacentHTML('beforeend', rowGenerator(item));
                });
                updateRowNumbers(tableElement);
            }

            /**
             * للتحقق من صحة النموذج.
             * @param {HTMLFormElement} form - النموذج المراد التحقق منه.
             * @returns {boolean} - true إذا كان النموذج صالحًا، false بخلاف ذلك.
             */
            function validateForm(form) {
                let isValid = true;
                form.querySelectorAll('[required]').forEach(input => {
                    if (!input.classList.contains('hidden-field') && !input.readOnly && !input.value.trim()) {
                        input.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        input.classList.remove('is-invalid');
                    }
                });
                // التحقق من صلاحية التواريخ
                if (form.id === 'leaveForm' || form.id === 'editLeaveForm') {
                    const startDate = new Date(form.querySelector('[name*="start_date"]').value);
                    const endDate = new Date(form.querySelector('[name*="end_date"]').value);
                    if (endDate < startDate) {
                        form.querySelector('[name*="end_date"]').classList.add('is-invalid');
                        showToast('تاريخ نهاية الإجازة يجب أن يكون بعد أو يساوي تاريخ البداية.', 'danger');
                        isValid = false;
                    } else {
                        form.querySelector('[name*="end_date"]').classList.remove('is-invalid');
                    }
                }
                return isValid;
            }

            // ======================== CSRF Token Management ========================
            // هذه الدالة ستستدعى عند تحميل الصفحة أو عندما تحتاج إلى توكن جديد
            async function refreshCsrfToken() {
                const result = await sendAjaxRequest('get_csrf_token', {});
                if (result.success && result.csrf_token) {
                    document.querySelectorAll('input[name="csrf_token"]').forEach(input => {
                        input.value = result.csrf_token;
                    });
                }
            }
            // refreshCsrfToken(); // يمكن استدعاؤها عند الحاجة، ولكن PHP يولدها تلقائياً عند تحميل الصفحة

            // ======================== وظائف إدارة الأطباء ========================

            /**
             * تجلب وتحدث قائمة الأطباء في المودال وفي قائمة select.
             * @param {string} selectedId - معرف الطبيب الذي يجب تحديده بعد التحديث (للتعديل).
             */
            async function fetchDoctors(selectedId = null) {
                const result = await sendAjaxRequest('fetch_all_doctors', {});
                if (result.success) {
                    currentTableData.doctors = result.doctors; // تحديث البيانات المخزنة محليا
                    updateTable(doctorsTable, result.doctors, generateDoctorRow);

                    // تحديث قائمة الأطباء في نموذج إضافة/تعديل الإجازة
                    doctorSelect.innerHTML = '<option value="">اختر طبيبًا</option><option value="manual">إدخال يدوي</option>';
                    result.doctors.forEach(d => {
                        const option = document.createElement('option');
                        option.value = d.id;
                        option.textContent = `${d.name} - ${d.title}`;
                        option.dataset.name = d.name.toLowerCase();
                        option.dataset.title = d.title.toLowerCase();
                        option.dataset.note = (d.note || '').toLowerCase();
                        doctorSelect.append(option);
                    });
                    if (selectedId) {
                        doctorSelect.value = selectedId;
                        toggleDoctorManualFields(); // لإظهار تفاصيل الطبيب بعد التحديد
                    }
                }
            }

            /**
             * تولّد صف HTML لبيانات الطبيب.
             * @param {object} d - بيانات الطبيب.
             * @returns {string} - HTML لصف الطبيب.
             */
            function generateDoctorRow(d) {
                return `
            <tr data-id="${d.id}">
                <td class="row-num"></td>
                <td class="cell-doctor-name">${htmlspecialchars(d.name)}</td>
                <td class="cell-doctor-title">${htmlspecialchars(d.title)}</td>
                <td class="cell-doctor-note">${htmlspecialchars(d.note || '')}</td>
                <td>
                    <button class="btn btn-warning btn-sm action-btn btn-edit-doctor"><i class="bi bi-pencil-square"></i> تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-doctor"><i class="bi bi-trash-fill"></i> حذف</button>
                </td>
            </tr>
        `;
            }

            // ======================== وظائف إدارة المرضى ========================

            /**
             * تجلب وتحدث قائمة المرضى في المودال وفي قائمة select.
             * @param {string} selectedId - معرف المريض الذي يجب تحديده بعد التحديث (للتعديل).
             */
            async function fetchPatients(selectedId = null) {
                const result = await sendAjaxRequest('fetch_all_patients', {});
                if (result.success) {
                    currentTableData.patients = result.patients; // تحديث البيانات المخزنة محليا
                    updateTable(patientsTable, result.patients, generatePatientRow);

                    // تحديث قائمة المرضى في نموذج إضافة/تعديل الإجازة
                    patientSelect.innerHTML = '<option value="">اختر مريضًا</option><option value="manual">إدخال يدوي</option>';
                    result.patients.forEach(p => {
                        const option = document.createElement('option');
                        option.value = p.id;
                        option.textContent = `${p.name} (${p.identity_number})`;
                        option.dataset.name = p.name.toLowerCase();
                        option.dataset.identity = p.identity_number.toLowerCase();
                        patientSelect.append(option);
                    });
                    if (selectedId) {
                        patientSelect.value = selectedId;
                        togglePatientManualFields(); // لإخفاء الحقول اليدوية إذا تم اختيار مريض موجود
                    }
                }
            }

            /**
             * تولّد صف HTML لبيانات المريض.
             * @param {object} p - بيانات المريض.
             * @returns {string} - HTML لصف المريض.
             */
            function generatePatientRow(p) {
                return `
            <tr data-id="${p.id}">
                <td class="row-num"></td>
                <td class="cell-patient-name">${htmlspecialchars(p.name)}</td>
                <td class="cell-patient-identity">${htmlspecialchars(p.identity_number)}</td>
                <td>
                    <button class="btn btn-warning btn-sm action-btn btn-edit-patient"><i class="bi bi-pencil-square"></i> تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-patient"><i class="bi bi-trash-fill"></i> حذف</button>
                </td>
            </tr>
        `;
            }

            // ======================== وظائف إدارة الإجازات ========================

            /**
             * تجلب وتحدّث بيانات الإجازات (النشطة والأرشيف).
             */
            async function fetchAllLeaves() {
                showLoading();
                const response = await fetch('admin_dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'fetch_all_leaves', // إجراء افتراضي لجلب كل شيء
                        csrf_token: document.querySelector('input[name="csrf_token"]').value
                    })
                });
                const data = await response.json();
                hideLoading();

                if (data.success) {
                    // تحديث البيانات المحلية
                    currentTableData.leaves = data.leaves;
                    currentTableData.archived = data.archived;
                    currentTableData.queries = data.queries;
                    currentTableData.notifications_payment = data.notifications_payment;

                    // تحديث الجداول
                    updateTable(leavesTable, currentTableData.leaves, generateLeaveRow);
                    updateTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow);
                    updateTable(queriesTable, currentTableData.queries, generateQueryRow);
                    updatePaymentNotifications(currentTableData.notifications_payment);
                    updateStats(data.stats);

                } else {
                    showToast(data.message || 'فشل في جلب بيانات الإجازات.', 'danger');
                }
            }


            /**
             * تولّد صف HTML لبيانات الإجازة النشطة.
             * @param {object} lv - بيانات الإجازة.
             * @returns {string} - HTML لصف الإجازة.
             */
            function generateLeaveRow(lv) {
                const isPaidBadge = lv.is_paid == 1 ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-danger">لا</span>';
                const typeBadge = lv.is_companion == 1 ? '<span class="badge bg-warning text-dark">مرافق</span>' : '<span class="badge bg-info text-dark">أساسي</span>';
                const paymentBtnClass = lv.is_paid == 1 ? 'd-none' : ''; // إخفاء زر الدفع إذا كانت مدفوعة

                return `
            <tr data-id="${lv.id}" data-patient="${lv.patient_id}" data-comp-name="${htmlspecialchars(lv.companion_name || '')}" data-comp-rel="${htmlspecialchars(lv.companion_relation || '')}" data-is-paid="${lv.is_paid}">
                <td class="row-num"></td>
                <td class="cell-service">${htmlspecialchars(lv.service_code.toUpperCase())}</td>
                <td class="cell-patient">${htmlspecialchars(lv.patient_name)}</td>
                <td class="cell-identity">${htmlspecialchars(lv.identity_number)}</td>
                <td class="cell-doctor">${htmlspecialchars(lv.doctor_name)}</td>
                <td>${htmlspecialchars(lv.doctor_title)}</td>
                <td>${htmlspecialchars(lv.doctor_note || '')}</td>
                <td class="cell-issue">${htmlspecialchars(lv.issue_date)}</td>
                <td>${htmlspecialchars(lv.start_date)}</td>
                <td>${htmlspecialchars(lv.end_date)}</td>
                <td>${htmlspecialchars(lv.days_count)}</td>
                <td>${typeBadge}</td>
                <td class="cell-queries-count">${lv.queries_count}</td>
                <td class="cell-created">${htmlspecialchars(lv.created_at)}</td>
                <td class="cell-is-paid">${isPaidBadge}</td>
                <td class="cell-amount">${parseFloat(lv.payment_amount).toFixed(2)}</td>
                <td>
                    <button class="btn btn-info btn-sm action-btn btn-edit-leave"><i class="bi bi-pencil-square"></i> تعديل</button>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-leave"><i class="bi bi-archive-fill"></i> أرشفة</button>
                    <button class="btn btn-warning btn-sm action-btn btn-view-queries" data-leave-id="${lv.id}"><i class="bi bi-journal-text"></i> استعلامات</button>
                    <button class="btn btn-success btn-sm action-btn btn-mark-paid ${paymentBtnClass}" data-leave-id="${lv.id}" data-amount="${parseFloat(lv.payment_amount).toFixed(2)}"><i class="bi bi-cash-stack"></i> دفع</button>
                </td>
            </tr>
        `;
            }

            /**
             * تولّد صف HTML لبيانات الإجازة المؤرشفة.
             * @param {object} lv - بيانات الإجازة المؤرشفة.
             * @returns {string} - HTML لصف الإجازة المؤرشفة.
             */
            function generateArchivedLeaveRow(lv) {
                const isPaidBadge = lv.is_paid == 1 ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-danger">لا</span>';
                const typeBadge = lv.is_companion == 1 ? '<span class="badge bg-warning text-dark">مرافق</span>' : '<span class="badge bg-info text-dark">أساسي</span>';
                return `
            <tr data-id="${lv.id}" data-patient="${lv.patient_id}" data-comp-name="${htmlspecialchars(lv.companion_name || '')}" data-comp-rel="${htmlspecialchars(lv.companion_relation || '')}">
                <td class="row-num"></td>
                <td class="cell-service">${htmlspecialchars(lv.service_code.toUpperCase())}</td>
                <td class="cell-patient">${htmlspecialchars(lv.patient_name)}</td>
                <td class="cell-identity">${htmlspecialchars(lv.identity_number)}</td>
                <td class="cell-doctor">${htmlspecialchars(lv.doctor_name)}</td>
                <td>${htmlspecialchars(lv.doctor_title)}</td>
                <td>${htmlspecialchars(lv.doctor_note || '')}</td>
                <td class="cell-issue">${htmlspecialchars(lv.issue_date)}</td>
                <td>${htmlspecialchars(lv.start_date)}</td>
                <td>${htmlspecialchars(lv.end_date)}</td>
                <td>${htmlspecialchars(lv.days_count)}</td>
                <td>${typeBadge}</td>
                <td class="cell-queries-count">${lv.queries_count}</td>
                <td class="cell-deleted">${htmlspecialchars(lv.deleted_at)}</td>
                <td>${isPaidBadge}</td>
                <td>${parseFloat(lv.payment_amount).toFixed(2)}</td>
                <td>
                    <button class="btn btn-success btn-sm action-btn btn-restore-leave"><i class="bi bi-arrow-counterclockwise"></i> استعادة</button>
                    <button class="btn btn-danger btn-sm action-btn btn-force-delete-leave"><i class="bi bi-x-circle"></i> حذف نهائي</button>
                    <button class="btn btn-warning btn-sm action-btn btn-view-queries" data-leave-id="${lv.id}"><i class="bi bi-journal-text"></i> استعلامات</button>
                </td>
            </tr>
        `;
            }

            /**
             * تولّد صف HTML لبيانات الاستعلام.
             * @param {object} q - بيانات الاستعلام.
             * @returns {string} - HTML لصف الاستعلام.
             */
            function generateQueryRow(q) {
                return `
            <tr data-id="${q.qid}" data-leave-id="${q.leave_id}">
                <td class="row-num"></td>
                <td class="cell-service">${htmlspecialchars(q.service_code.toUpperCase())}</td>
                <td class="cell-patient">${htmlspecialchars(q.patient_name)}</td>
                <td class="cell-identity">${htmlspecialchars(q.identity_number)}</td>
                <td class="cell-queried">${htmlspecialchars(q.queried_at)}</td>
                <td>
                    <button class="btn btn-danger btn-sm action-btn btn-delete-query"><i class="bi bi-trash-fill"></i> حذف</button>
                    <button class="btn btn-info btn-sm action-btn btn-view-leave-from-query" data-leave-id="${q.leave_id}"><i class="bi bi-info-circle"></i> تفاصيل إجازة</button>
                </td>
            </tr>
        `;
            }

            /**
             * يقوم بتحديث قائمة إشعارات المدفوعات.
             * @param {Array<object>} notifications - مصفوفة الإشعارات.
             */
            function updatePaymentNotifications(notifications) {
                notifPaymentsList.innerHTML = '';
                if (notifications.length === 0) {
                    notifPaymentsList.innerHTML = '<li class="list-group-item">لا توجد إشعارات مدفوعات حاليًا.</li>';
                    return;
                }
                notifications.forEach(n => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center';
                    li.setAttribute('data-leave', n.leave_id);
                    li.setAttribute('data-id', n.id);
                    li.setAttribute('data-amount', parseFloat(n.payment_amount || 0).toFixed(2));
                    li.innerHTML = `
                <span>${htmlspecialchars(n.message)}</span>
                <div class="btn-group">
                    <button class="btn btn-info btn-sm btn-view-leave" data-leave="${n.leave_id}"><i class="bi bi-info-circle"></i> تفاصيل</button>
                    <button class="btn btn-success btn-sm btn-pay-notif" data-leave="${n.leave_id}"><i class="bi bi-cash-stack"></i> مدفوعة</button>
                    <button class="btn btn-danger btn-sm btn-del-notif" data-id="${n.id}"><i class="bi bi-trash-fill"></i> حذف</button>
                </div>
            `;
                    notifPaymentsList.appendChild(li);
                });
            }

            // ======================== وظائف الفرز، البحث، والفلترة ========================

            /**
             * يقوم بفلترة وترتيب البيانات في الجدول.
             * @param {HTMLTableElement} tableElement - الجدول المراد فلترته/فرزه.
             * @param {Array<object>} originalData - البيانات الأصلية للجدول.
             * @param {function(object): string} rowGenerator - دالة تولّد HTML لصف واحد.
             * @param {object} filters - كائن يحتوي على معايير الفلترة (search, fromDate, toDate, typeFilter).
             * @param {string} sortColumn - العمود الذي سيتم الفرز على أساسه.
             * @param {string} sortOrder - ترتيب الفرز ('asc' أو 'desc').
             */
            function filterAndSortTable(tableElement, originalData, rowGenerator, filters, sortColumn = null, sortOrder = 'desc') {
                let filteredData = [...originalData];

                // 1. الفلترة
                if (filters.search) {
                    const searchTerm = filters.search.toLowerCase();
                    filteredData = filteredData.filter(item => {
                        if (tableElement.id === 'leavesTable' || tableElement.id === 'archivedTable') {
                            return item.service_code.toLowerCase().includes(searchTerm) ||
                                item.patient_name.toLowerCase().includes(searchTerm) ||
                                item.doctor_name.toLowerCase().includes(searchTerm);
                        } else if (tableElement.id === 'doctorsTable') {
                            return item.name.toLowerCase().includes(searchTerm) ||
                                item.title.toLowerCase().includes(searchTerm);
                        } else if (tableElement.id === 'patientsTable') {
                            return item.name.toLowerCase().includes(searchTerm) ||
                                item.identity_number.toLowerCase().includes(searchTerm);
                        } else if (tableElement.id === 'queriesTable') {
                            return item.service_code.toLowerCase().includes(searchTerm) ||
                                item.patient_name.toLowerCase().includes(searchTerm) ||
                                item.identity_number.toLowerCase().includes(searchTerm);
                        } else if (tableElement.id === 'paymentsTable') {
                            return item.name.toLowerCase().includes(searchTerm);
                        }
                        return true;
                    });
                }

                if (filters.fromDate && filters.toDate) {
                    const from = new Date(filters.fromDate);
                    const to = new Date(filters.toDate);
                    filteredData = filteredData.filter(item => {
                        let dateToCheck;
                        if (tableElement.id === 'leavesTable') {
                            dateToCheck = new Date(item.created_at);
                        } else if (tableElement.id === 'archivedTable') {
                            dateToCheck = new Date(item.deleted_at);
                        } else if (tableElement.id === 'queriesTable') {
                            dateToCheck = new Date(item.queried_at);
                        } else {
                            return true; // لا يوجد فلترة تاريخ لهذا الجدول
                        }
                        dateToCheck.setHours(0, 0, 0, 0); // لإزالة الوقت ومقارنة اليوم فقط
                        return dateToCheck >= from && dateToCheck <= to;
                    });
                }

                if (filters.typeFilter === 'paid') {
                    filteredData = filteredData.filter(item => item.is_paid == 1);
                } else if (filters.typeFilter === 'unpaid') {
                    filteredData = filteredData.filter(item => item.is_paid == 0);
                }

                // 2. الفرز
                if (sortColumn) {
                    filteredData.sort((a, b) => {
                        let valA, valB;
                        if (sortColumn.includes('date') || sortColumn.includes('created_at') || sortColumn.includes('deleted_at') || sortColumn.includes('queried_at')) {
                            valA = new Date(a[sortColumn] || '1970-01-01');
                            valB = new Date(b[sortColumn] || '1970-01-01');
                        } else if (sortColumn.includes('amount') || sortColumn.includes('count')) {
                            valA = parseFloat(a[sortColumn] || 0);
                            valB = parseFloat(b[sortColumn] || 0);
                        } else {
                            valA = String(a[sortColumn] || '').toLowerCase();
                            valB = String(b[sortColumn] || '').toLowerCase();
                        }

                        if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
                        if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
                        return 0;
                    });
                }

                // 3. تحديث الجدول
                updateTable(tableElement, filteredData, rowGenerator);
            }

            // ======================== وظائف التصدير والطباعة ========================

            /**
             * تصدير محتوى الجدول إلى PDF.
             * @param {HTMLTableElement} tableElement - عنصر الجدول المراد تصديره.
             * @param {string} filename - اسم ملف PDF.
             * @param {string} title - عنوان يظهر في ملف PDF.
             */
            function exportTableToPdf(tableElement, filename = 'document.pdf', title = 'تقرير') {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF('l', 'mm', 'a4'); // 'l' for landscape

                // Set font for Arabic support
                doc.addFont('Cairo-Regular.ttf', 'Cairo', 'normal'); // Assuming Cairo-Regular.ttf is added
                doc.setFont('Cairo');

                const tableColumn = [];
                tableElement.querySelectorAll('thead th:not(:last-child)').forEach(th => {
                    tableColumn.push(th.textContent.trim().split(' ')[0]); // Remove sort icons
                });

                const tableRows = [];
                tableElement.querySelectorAll('tbody tr:not(.no-results)').forEach(tr => {
                    const rowData = [];
                    tr.querySelectorAll('td:not(:last-child)').forEach(td => {
                        // If it's a badge, get its text content
                        if (td.querySelector('.badge')) {
                            rowData.push(td.querySelector('.badge').textContent.trim());
                        } else {
                            rowData.push(td.textContent.trim());
                        }
                    });
                    tableRows.push(rowData);
                });

                doc.autoTable({
                    head: [tableColumn],
                    body: tableRows,
                    startY: 20,
                    theme: 'striped',
                    headStyles: {
                        fillColor: [32, 162, 178],
                        textColor: [255, 255, 255],
                        font: 'Cairo',
                        fontStyle: 'normal',
                        halign: 'center' // Align header text to center
                    },
                    bodyStyles: {
                        textColor: [0, 0, 0],
                        font: 'Cairo',
                        fontStyle: 'normal',
                        halign: 'center' // Align body text to center
                    },
                    alternateRowStyles: {
                        fillColor: [240, 240, 240]
                    },
                    styles: {
                        font: 'Cairo',
                        fontStyle: 'normal',
                        fontSize: 8,
                        cellPadding: 2,
                        overflow: 'linebreak',
                        minCellHeight: 8
                    },
                    margin: {
                        top: 10,
                        right: 10,
                        bottom: 10,
                        left: 10
                    },
                    didDrawPage: function (data) {
                        // Header
                        doc.setFontSize(14);
                        doc.setTextColor(40);
                        doc.text(title, doc.internal.pageSize.width / 2, 10, {
                            align: 'center'
                        });

                        // Footer
                        doc.setFontSize(8);
                        const pageCount = doc.internal.getNumberOfPages();
                        for (let i = 0; i < pageCount; i++) {
                            doc.setPage(i);
                            doc.text('صفحة ' + (i + 1) + ' من ' + pageCount, doc.internal.pageSize.width - 20, doc.internal.pageSize.height - 10, {
                                align: 'right'
                            });
                        }
                    }
                });

                doc.save(filename);
                showToast('تم تصدير الجدول إلى PDF.', 'success');
            }

            /**
             * تصدير محتوى الجدول إلى ملف Excel (CSV).
             * @param {HTMLTableElement} tableElement - عنصر الجدول المراد تصديره.
             * @param {string} filename - اسم ملف Excel (مع امتداد .csv).
             */
            function exportTableToExcel(tableElement, filename = 'data.csv') {
                let csv = [];
                const rows = tableElement.querySelectorAll('tr');

                rows.forEach(row => {
                    const cols = row.querySelectorAll('th, td');
                    const rowData = [];
                    cols.forEach(col => {
                        let text = col.textContent.trim();
                        // Replace commas with semicolons or remove them to avoid issues in CSV
                        text = text.replace(/,/g, ''); // Remove commas
                        // Handle badges specially
                        if (col.querySelector('.badge')) {
                            text = col.querySelector('.badge').textContent.trim();
                        }
                        rowData.push(`"${text}"`); // Enclose in quotes to handle spaces/special characters
                    });
                    csv.push(rowData.join(','));
                });

                const csvString = csv.join('\n');
                const blob = new Blob(["\uFEFF", csvString], { // Add BOM for UTF-8 in Excel
                    type: 'text/csv;charset=utf-8;'
                });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showToast('تم تصدير الجدول إلى Excel (CSV).', 'success');
            }


            /**
             * طباعة محتوى الجدول.
             * @param {HTMLTableElement} tableElement - عنصر الجدول المراد طباعته.
             * @param {string} title - عنوان يظهر في صفحة الطباعة.
             */
            function printTableContent(tableElement, title = 'تقرير') {
                const printWindow = window.open('', '', 'height=600,width=800');
                printWindow.document.write('<html><head><title>' + title + '</title>');
                printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">');
                printWindow.document.write('<style>');
                printWindow.document.write(`
            body { font-family: 'Cairo', sans-serif; direction: rtl; text-align: center; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
            th { background-color: #f2f2f2; }
            .badge { padding: 0.3em 0.6em; font-size: 0.75em; font-weight: bold; border-radius: 0.25rem; }
            .badge.bg-warning { background-color: #ffc107; color: #212529; }
            .badge.bg-info { background-color: #17a2b8; color: #fff; }
            .badge.bg-success { background-color: #28a745; color: #fff; }
            .badge.bg-danger { background-color: #dc3545; color: #fff; }
            /* Hide the last column (control buttons) for printing */
            table th:last-child, table td:last-child { display: none; }
        `);
                printWindow.document.write('</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write('<h1>' + title + '</h1>');
                printWindow.document.write(tableElement.outerHTML); // Use outerHTML to include the table tags
                printWindow.document.write('</body></html>');
                printWindow.document.close();
                printWindow.print();
            }


            // ======================== وظائف الوضع الداكن/الفاتح ========================

            const darkModeToggle = document.getElementById('darkModeToggle');

            /**
             * يطبق الوضع الداكن أو الفاتح بناءً على تفضيل المستخدم.
             */
            function applyDarkModePreference() {
                const isDarkMode = localStorage.getItem('darkMode') === 'true';
                if (isDarkMode) {
                    document.body.classList.add('dark-mode');
                    darkModeToggle.innerHTML = '<i class="bi bi-sun-fill"></i> فاتح';
                } else {
                    document.body.classList.remove('dark-mode');
                    darkModeToggle.innerHTML = '<i class="bi bi-moon-fill"></i> داكن';
                }
            }

            // ======================== تهيئة الأحداث (Event Listeners) ========================

            // تطبيق الوضع الداكن عند تحميل الصفحة
            applyDarkModePreference();

            // التبديل بين الوضع الداكن والفاتح
            darkModeToggle.addEventListener('click', () => {
                const isDarkMode = document.body.classList.toggle('dark-mode');
                localStorage.setItem('darkMode', isDarkMode);
                applyDarkModePreference(); // تحديث النص والأيقونة
            });

            // ====== أحداث نموذج إضافة إجازة ======

            // حساب عدد الأيام تلقائياً أو تمكين الإدخال اليدوي
            [startDateInput, endDateInput].forEach(input => {
                input.addEventListener('change', () => {
                    if (!daysManualCheckbox.checked) {
                        daysCountInput.value = calculateDays(startDateInput.value, endDateInput.value);
                    }
                });
            });

            daysManualCheckbox.addEventListener('change', () => {
                daysCountInput.readOnly = !daysManualCheckbox.checked;
                if (!daysManualCheckbox.checked) {
                    daysCountInput.value = calculateDays(startDateInput.value, endDateInput.value);
                }
            });

            // إظهار/إخفاء حقول المرافق
            companionCheckbox.addEventListener('change', () => {
                companionFields.forEach(field => {
                    field.classList.toggle('hidden-field', !companionCheckbox.checked);
                    if (!companionCheckbox.checked) {
                        field.querySelector('input, textarea').value = ''; // مسح القيم عند الإخفاء
                    }
                });
            });

            // إظهار/إخفاء حقول المريض اليدوية
            patientSelect.addEventListener('change', () => {
                togglePatientManualFields();
            });

            function togglePatientManualFields() {
                const isManual = patientSelect.value === 'manual';
                patientManualName.classList.toggle('hidden-field', !isManual);
                patientManualId.classList.toggle('hidden-field', !isManual);
                patientManualName.toggleAttribute('required', isManual);
                patientManualId.toggleAttribute('required', isManual);
                searchPatientInput.classList.toggle('hidden-field', isManual);
                document.getElementById('btn-search-patient').classList.toggle('hidden-field', isManual);

                // مسح الحقول اليدوية إذا لم يتم اختيار "إدخال يدوي"
                if (!isManual) {
                    patientManualName.value = '';
                    patientManualId.value = '';
                    patientManualName.classList.remove('is-invalid');
                    patientManualId.classList.remove('is-invalid');
                }
            }

            // البحث في قائمة المرضى
            searchPatientInput.addEventListener('input', () => {
                const searchTerm = searchPatientInput.value.toLowerCase();
                let found = false;
                patientSelect.querySelectorAll('option:not([value="manual"]):not([value=""])').forEach(option => {
                    const patientName = option.dataset.name;
                    const patientIdentity = option.dataset.identity;
                    const matches = patientName.includes(searchTerm) || patientIdentity.includes(searchTerm);
                    option.style.display = matches ? '' : 'none';
                    if (matches) found = true;
                });
                noPatientResult.style.display = found || !searchTerm ? 'none' : 'block';
            });
            document.getElementById('btn-search-patient').addEventListener('click', () => {
                searchPatientInput.dispatchEvent(new Event('input')); // لتشغيل البحث يدوياً
            });

            // إظهار/إخفاء حقول الطبيب اليدوية
            doctorSelect.addEventListener('change', () => {
                toggleDoctorManualFields();
            });

            function toggleDoctorManualFields() {
                const isManual = doctorSelect.value === 'manual';
                const isSelectedDoctor = doctorSelect.value && doctorSelect.value !== 'manual';
                doctorManualName.classList.toggle('hidden-field', !isManual);
                doctorManualTitle.classList.toggle('hidden-field', !isManual);
                doctorManualNote.classList.toggle('hidden-field', !isManual);
                doctorSavedTitle.classList.toggle('hidden-field', isManual || !isSelectedDoctor);
                doctorSavedNote.classList.toggle('hidden-field', isManual || !isSelectedDoctor);

                doctorManualName.toggleAttribute('required', isManual);
                doctorManualTitle.toggleAttribute('required', isManual);

                searchDoctorInput.classList.toggle('hidden-field', isManual);
                document.getElementById('btn-search-doctor').classList.toggle('hidden-field', isManual);

                if (isSelectedDoctor) {
                    const selectedOption = doctorSelect.options[doctorSelect.selectedIndex];
                    doctorSavedTitle.value = selectedOption.dataset.title || '';
                    doctorSavedNote.value = selectedOption.dataset.note || '';
                } else {
                    doctorSavedTitle.value = '';
                    doctorSavedNote.value = '';
                }

                // مسح الحقول اليدوية إذا لم يتم اختيار "إدخال يدوي"
                if (!isManual) {
                    doctorManualName.value = '';
                    doctorManualTitle.value = '';
                    doctorManualNote.value = '';
                    doctorManualName.classList.remove('is-invalid');
                    doctorManualTitle.classList.remove('is-invalid');
                }
            }
            // تحديث الحالة الأولية عند تحميل الصفحة
            togglePatientManualFields();
            toggleDoctorManualFields();


            // البحث في قائمة الأطباء
            searchDoctorInput.addEventListener('input', () => {
                const searchTerm = searchDoctorInput.value.toLowerCase();
                let found = false;
                doctorSelect.querySelectorAll('option:not([value="manual"]):not([value=""])').forEach(option => {
                    const doctorName = option.dataset.name;
                    const doctorTitle = option.dataset.title;
                    const doctorNote = option.dataset.note;
                    const matches = doctorName.includes(searchTerm) || doctorTitle.includes(searchTerm) || doctorNote.includes(searchTerm);
                    option.style.display = matches ? '' : 'none';
                    if (matches) found = true;
                });
                noDoctorResult.style.display = found || !searchTerm ? 'none' : 'block';
            });
            document.getElementById('btn-search-doctor').addEventListener('click', () => {
                searchDoctorInput.dispatchEvent(new Event('input')); // لتشغيل البحث يدوياً
            });

            // ====== معالجة إضافة إجازة ======
            leaveForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(leaveForm)) {
                    showToast('الرجاء تعبئة جميع الحقول المطلوبة.', 'danger');
                    return;
                }

                const formData = new FormData(leaveForm);
                const result = await sendAjaxRequest('add_leave', formData);

                if (result.success) {
                    showToast(result.message, 'success');
                    clearForm(leaveForm);
                    // تحديث الجدول والإحصائيات
                    currentTableData.leaves.unshift(result.leave); // إضافة الإجازة الجديدة في البداية
                    updateTable(leavesTable, currentTableData.leaves, generateLeaveRow);
                    updateStats(result.stats);
                }
            });

            // ====== أحداث إدارة الأطباء (داخل مودال الأطباء) ======
            const doctorForm = document.getElementById('doctorForm');
            const doctorFormId = document.getElementById('doctor_form_id');
            const doctorFormName = document.getElementById('doctor_form_name');
            const doctorFormTitle = document.getElementById('doctor_form_title');
            const doctorFormNote = document.getElementById('doctor_form_note');

            document.getElementById('btn-show-add-doctor').addEventListener('click', () => {
                clearForm(doctorForm);
                doctorForm.style.display = 'flex'; // إظهار النموذج
            });

            document.getElementById('btn-cancel-doctor').addEventListener('click', () => {
                clearForm(doctorForm);
                doctorForm.style.display = 'none'; // إخفاء النموذج
            });

            doctorForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(doctorForm)) {
                    showToast('الرجاء تعبئة جميع الحقول المطلوبة.', 'danger');
                    return;
                }

                const action = doctorFormId.value ? 'edit_doctor' : 'add_doctor';
                const formData = new FormData(doctorForm);
                const result = await sendAjaxRequest(action, formData);

                if (result.success) {
                    showToast(result.message || 'تم تحديث الأطباء بنجاح.', 'success');
                    clearForm(doctorForm);
                    doctorForm.style.display = 'none'; // إخفاء النموذج
                    fetchDoctors(result.doctor ? result.doctor.id : null); // إعادة جلب وتحديث الأطباء وتحديد الطبيب المضاف/المعدّل
                    updateStats(result.stats);
                }
            });

            // تعديل طبيب
            doctorsTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-edit-doctor')) {
                    const row = e.target.closest('tr');
                    const doctorId = row.dataset.id;
                    const doctorName = row.querySelector('.cell-doctor-name').textContent;
                    const doctorTitle = row.querySelector('.cell-doctor-title').textContent;
                    const doctorNote = row.querySelector('.cell-doctor-note').textContent;

                    doctorFormId.value = doctorId;
                    doctorFormName.value = doctorName;
                    doctorFormTitle.value = doctorTitle;
                    doctorFormNote.value = doctorNote;
                    doctorForm.style.display = 'flex'; // إظهار النموذج للتعديل
                }
            });

            // حذف طبيب
            doctorsTable.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-delete-doctor')) {
                    const row = e.target.closest('tr');
                    const doctorId = row.dataset.id;
                    confirmMessage.textContent = 'هل أنت متأكد من حذف هذا الطبيب؟ سيتم حذف جميع الإجازات المرتبطة به.';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('delete_doctor', {
                            doctor_id: doctorId
                        });
                        if (result.success) {
                            showToast(result.message || 'تم حذف الطبيب بنجاح.', 'success');
                            fetchDoctors(); // إعادة جلب الأطباء بعد الحذف
                            updateStats(result.stats);
                        }
                    };
                    confirmModal.show();
                }
            });

            // البحث في جدول الأطباء
            document.getElementById('searchDoctorsTable').addEventListener('input', () => {
                filterAndSortTable(doctorsTable, currentTableData.doctors, generateDoctorRow, {
                    search: document.getElementById('searchDoctorsTable').value
                });
            });
            document.getElementById('btn-search-doctors').addEventListener('click', () => {
                document.getElementById('searchDoctorsTable').dispatchEvent(new Event('input'));
            });

            // ====== أحداث إدارة المرضى (داخل مودال المرضى) ======
            const patientForm = document.getElementById('patientForm');
            const patientFormId = document.getElementById('patient_form_id');
            const patientFormName = document.getElementById('patient_form_name');
            const patientFormIdentity = document.getElementById('patient_form_identity');

            document.getElementById('btn-show-add-patient').addEventListener('click', () => {
                clearForm(patientForm);
                patientForm.style.display = 'flex'; // إظهار النموذج
            });

            document.getElementById('btn-cancel-patient').addEventListener('click', () => {
                clearForm(patientForm);
                patientForm.style.display = 'none'; // إخفاء النموذج
            });

            patientForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(patientForm)) {
                    showToast('الرجاء تعبئة جميع الحقول المطلوبة.', 'danger');
                    return;
                }

                const action = patientFormId.value ? 'edit_patient' : 'add_patient';
                const formData = new FormData(patientForm);
                const result = await sendAjaxRequest(action, formData);

                if (result.success) {
                    showToast(result.message || 'تم تحديث المرضى بنجاح.', 'success');
                    clearForm(patientForm);
                    patientForm.style.display = 'none'; // إخفاء النموذج
                    fetchPatients(result.patient ? result.patient.id : null); // إعادة جلب وتحديث المرضى
                    updateStats(result.stats);
                }
            });

            // تعديل مريض
            patientsTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-edit-patient')) {
                    const row = e.target.closest('tr');
                    const patientId = row.dataset.id;
                    const patientName = row.querySelector('.cell-patient-name').textContent;
                    const patientIdentity = row.querySelector('.cell-patient-identity').textContent;

                    patientFormId.value = patientId;
                    patientFormName.value = patientName;
                    patientFormIdentity.value = patientIdentity;
                    patientForm.style.display = 'flex'; // إظهار النموذج للتعديل
                }
            });

            // حذف مريض
            patientsTable.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-delete-patient')) {
                    const row = e.target.closest('tr');
                    const patientId = row.dataset.id;
                    confirmMessage.textContent = 'هل أنت متأكد من حذف هذا المريض؟ سيتم حذف جميع الإجازات المرتبطة به.';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('delete_patient', {
                            patient_id: patientId
                        });
                        if (result.success) {
                            showToast(result.message || 'تم حذف المريض بنجاح.', 'success');
                            fetchPatients(); // إعادة جلب المرضى بعد الحذف
                            updateStats(result.stats);
                        }
                    };
                    confirmModal.show();
                }
            });

            // البحث في جدول المرضى
            document.getElementById('searchPatientsTable').addEventListener('input', () => {
                filterAndSortTable(patientsTable, currentTableData.patients, generatePatientRow, {
                    search: document.getElementById('searchPatientsTable').value
                });
            });
            document.getElementById('btn-search-patients').addEventListener('click', () => {
                document.getElementById('searchPatientsTable').dispatchEvent(new Event('input'));
            });

            // ====== أحداث إدارة الإجازات (الجدول الرئيسي) ======

            // معالجة تعديل إجازة (فتح المودال وملء البيانات)
            leavesTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-edit-leave')) {
                    const row = e.target.closest('tr');
                    const leaveId = row.dataset.id;
                    const leaveData = currentTableData.leaves.find(l => l.id == leaveId);

                    if (leaveData) {
                        document.getElementById('leave_id_edit').value = leaveData.id;
                        document.getElementById('service_code_edit').value = leaveData.service_code;
                        document.getElementById('issue_date_edit').value = leaveData.issue_date;
                        document.getElementById('patient_edit').value = leaveData.patient_name + ' (' + leaveData.identity_number + ')';
                        document.getElementById('doctor_edit').value = leaveData.doctor_name + ' - ' + leaveData.doctor_title;
                        document.getElementById('doctor_note_edit').value = leaveData.doctor_note || '';
                        document.getElementById('start_date_edit').value = leaveData.start_date;
                        document.getElementById('end_date_edit').value = leaveData.end_date;
                        document.getElementById('days_count_edit').value = leaveData.days_count;

                        // Companion fields
                        const isCompanionEdit = document.getElementById('is_companion_edit');
                        const companionNameEdit = document.getElementById('companion_name_edit');
                        const companionRelationEdit = document.getElementById('companion_relation_edit');
                        isCompanionEdit.checked = leaveData.is_companion == 1;
                        document.querySelectorAll('.companion-fields-edit').forEach(field => {
                            field.classList.toggle('hidden-field', !isCompanionEdit.checked);
                        });
                        companionNameEdit.value = leaveData.companion_name || '';
                        companionRelationEdit.value = leaveData.companion_relation || '';
                        companionNameEdit.toggleAttribute('required', isCompanionEdit.checked);
                        companionRelationEdit.toggleAttribute('required', isCompanionEdit.checked);

                        // Payment fields
                        const isPaidEdit = document.getElementById('is_paid_edit');
                        const paymentAmountEdit = document.getElementById('payment_amount_edit');
                        isPaidEdit.checked = leaveData.is_paid == 1;
                        paymentAmountEdit.value = parseFloat(leaveData.payment_amount).toFixed(2);

                        // Days manual checkbox for edit form
                        const daysManualEditCheckbox = document.getElementById('days_manual_edit');
                        daysManualEditCheckbox.checked = false; // افتراضياً غير يدوي
                        document.getElementById('days_count_edit').readOnly = true;

                        // Event listener for days manual checkbox in edit form
                        daysManualEditCheckbox.addEventListener('change', () => {
                            document.getElementById('days_count_edit').readOnly = !daysManualEditCheckbox.checked;
                            if (!daysManualEditCheckbox.checked) {
                                document.getElementById('days_count_edit').value = calculateDays(
                                    document.getElementById('start_date_edit').value,
                                    document.getElementById('end_date_edit').value
                                );
                            }
                        });

                        // Event listeners for dates in edit form
                        [document.getElementById('start_date_edit'), document.getElementById('end_date_edit')].forEach(input => {
                            input.addEventListener('change', () => {
                                if (!daysManualEditCheckbox.checked) {
                                    document.getElementById('days_count_edit').value = calculateDays(
                                        document.getElementById('start_date_edit').value,
                                        document.getElementById('end_date_edit').value
                                    );
                                }
                            });
                        });

                        // Event listener for companion checkbox in edit form
                        isCompanionEdit.addEventListener('change', () => {
                            document.querySelectorAll('.companion-fields-edit').forEach(field => {
                                field.classList.toggle('hidden-field', !isCompanionEdit.checked);
                                field.querySelector('input').toggleAttribute('required', isCompanionEdit.checked);
                            });
                        });

                        editLeaveModal.show();
                    } else {
                        showToast('لم يتم العثور على بيانات الإجازة للتعديل.', 'danger');
                    }
                }
            });


            // إرسال نموذج تعديل إجازة
            editLeaveForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!validateForm(editLeaveForm)) {
                    showToast('الرجاء تعبئة جميع الحقول المطلوبة بشكل صحيح.', 'danger');
                    return;
                }

                const formData = new FormData(editLeaveForm);
                const result = await sendAjaxRequest('edit_leave', formData);

                if (result.success) {
                    showToast(result.message, 'success');
                    editLeaveModal.hide();
                    // تحديث الصف في الجدول
                    const updatedLeave = result.leave;
                    const existingRow = leavesTable.querySelector(`tr[data-id="${updatedLeave.id}"]`);
                    if (existingRow) {
                        existingRow.outerHTML = generateLeaveRow(updatedLeave);
                    }
                    // تحديث البيانات في currentTableData
                    const index = currentTableData.leaves.findIndex(l => l.id == updatedLeave.id);
                    if (index !== -1) {
                        currentTableData.leaves[index] = updatedLeave;
                    }
                    updateStats(result.stats);
                }
            });

            // أرشفة إجازة
            leavesTable.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-delete-leave')) {
                    const row = e.target.closest('tr');
                    const leaveId = row.dataset.id;
                    confirmMessage.textContent = 'هل أنت متأكد من أرشفة هذه الإجازة؟ سيتم نقلها إلى الأرشيف.';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('delete_leave', {
                            leave_id: leaveId
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            // إزالة الصف من جدول الإجازات النشطة وإضافته لجدول الأرشيف
                            const leaveToArchive = currentTableData.leaves.find(l => l.id == leaveId);
                            if (leaveToArchive) {
                                leaveToArchive.deleted_at = result.deleted_at; // تحديث وقت الحذف
                                currentTableData.archived.unshift(leaveToArchive); // إضافة للأرشيف
                                currentTableData.leaves = currentTableData.leaves.filter(l => l.id != leaveId); // إزالتها من النشطة
                                updateTable(leavesTable, currentTableData.leaves, generateLeaveRow);
                                updateTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow);
                                updateStats(result.stats);
                            }
                        }
                    };
                    confirmModal.show();
                }
            });

            // استعادة إجازة من الأرشيف
            archivedTable.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-restore-leave')) {
                    const row = e.target.closest('tr');
                    const leaveId = row.dataset.id;
                    confirmMessage.textContent = 'هل أنت متأكد من استعادة هذه الإجازة؟';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('restore_leave', {
                            leave_id: leaveId
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            // إزالة الصف من جدول الأرشيف وإضافته لجدول الإجازات النشطة
                            const leaveToRestore = currentTableData.archived.find(l => l.id == leaveId);
                            if (leaveToRestore) {
                                leaveToRestore.is_deleted = 0;
                                leaveToRestore.deleted_at = null;
                                currentTableData.leaves.unshift(leaveToRestore); // إضافة للنشطة
                                currentTableData.archived = currentTableData.archived.filter(l => l.id != leaveId); // إزالتها من الأرشيف
                                updateTable(leavesTable, currentTableData.leaves, generateLeaveRow);
                                updateTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow);
                                updateStats(result.stats);
                            }
                        }
                    };
                    confirmModal.show();
                }
            });

            // حذف نهائي لإجازة من الأرشيف
            archivedTable.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-force-delete-leave')) {
                    const row = e.target.closest('tr');
                    const leaveId = row.dataset.id;
                    confirmMessage.textContent = 'تحذير! هل أنت متأكد من الحذف النهائي لهذه الإجازة؟ لا يمكن التراجع عن هذا الإجراء.';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('force_delete_leave', {
                            leave_id: leaveId
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            // إزالة الصف من جدول الأرشيف
                            currentTableData.archived = currentTableData.archived.filter(l => l.id != leaveId);
                            updateTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow);
                            updateStats(result.stats);
                        }
                    };
                    confirmModal.show();
                }
            });

            // حذف كل الإجازات المؤرشفة نهائيًا
            document.getElementById('btn-delete-all-archived').addEventListener('click', () => {
                confirmMessage.textContent = 'تحذير! هل أنت متأكد من حذف جميع الإجازات المؤرشفة نهائيًا؟ لا يمكن التراجع عن هذا الإجراء.';
                currentConfirmAction = async () => {
                    const result = await sendAjaxRequest('force_delete_all_archived', {});
                    if (result.success) {
                        showToast(result.message, 'success');
                        currentTableData.archived = []; // مسح جميع الإجازات المؤرشفة محليًا
                        updateTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow);
                        updateStats(result.stats);
                    }
                };
                confirmModal.show();
            });

            // تسجيل استعلام (علامة استعلام)
            leavesTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-view-queries')) {
                    const leaveId = e.target.dataset.leaveId;
                    queriesDetailsContainer.innerHTML = '<p class="text-center">جارٍ جلب البيانات...</p>';
                    viewQueriesModal.show();
                    currentConfirmId = leaveId; // حفظ معرف الإجازة لعرض سجلات الاستعلامات التفصيلية

                    const result = await sendAjaxRequest('fetch_queries', {
                        leave_id: leaveId
                    });
                    if (result.success) {
                        if (result.queries.length > 0) {
                            queriesDetailsContainer.innerHTML = `
                        <ul class="list-group" id="detailedQueriesList"></ul>
                    `;
                            const detailedQueriesList = document.getElementById('detailedQueriesList');
                            result.queries.forEach(q => {
                                const li = document.createElement('li');
                                li.className = 'list-group-item d-flex justify-content-between align-items-center';
                                li.setAttribute('data-id', q.id);
                                li.innerHTML = `
                            <span>${htmlspecialchars(q.queried_at)}</span>
                            <button class="btn btn-danger btn-sm btn-delete-detail-query" data-id="${q.id}"><i class="bi bi-trash-fill"></i> حذف</button>
                        `;
                                detailedQueriesList.appendChild(li);
                            });
                        } else {
                            queriesDetailsContainer.innerHTML = '<p class="text-center">لا توجد سجلات استعلام لهذه الإجازة.</p>';
                        }
                    } else {
                        queriesDetailsContainer.innerHTML = `<p class="text-center text-danger">${result.message}</p>`;
                    }
                }
            });


            // إضافة استعلام
            leavesTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-add-query')) { // هذا الزر غير موجود حالياً في HTML لكن تم تضمين المنطق
                    const row = e.target.closest('tr');
                    const leaveId = row.dataset.id;
                    const result = await sendAjaxRequest('add_query', {
                        leave_id: leaveId
                    });
                    if (result.success) {
                        showToast(result.message, 'success');
                        // تحديث عدد الاستعلامات في الصف مباشرة
                        const queriesCountCell = row.querySelector('.cell-queries-count');
                        if (queriesCountCell) {
                            queriesCountCell.textContent = result.new_count;
                        }
                        // تحديث سجل الاستعلامات في جدول QueriesTable
                        await fetchAllLeaves();
                    }
                }
            });

            // حذف استعلام من نافذة تفاصيل الاستعلام
            queriesDetailsContainer.addEventListener('click', (e) => {
                if (e.target.classList.contains('btn-delete-detail-query')) {
                    const queryId = e.target.dataset.id;
                    confirmMessage.textContent = 'هل أنت متأكد من حذف سجل الاستعلام هذا؟';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('delete_query', {
                            query_id: queryId
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            // إزالة الصف من قائمة التفاصيل
                            e.target.closest('li').remove();
                            // تحديث عدد الاستعلامات في جدول الإجازات الرئيسية
                            const leaveRow = leavesTable.querySelector(`tr[data-id="${currentConfirmId}"]`);
                            if (leaveRow) {
                                const queriesCountCell = leaveRow.querySelector('.cell-queries-count');
                                if (queriesCountCell) {
                                    queriesCountCell.textContent = parseInt(queriesCountCell.textContent) - 1;
                                }
                            }
                            // تحديث سجل الاستعلامات في جدول QueriesTable
                            await fetchAllLeaves();
                        }
                    };
                    confirmModal.show();
                }
            });

            // حذف كل الاستعلامات من نافذة تفاصيل الاستعلامات
            document.getElementById('btn-delete-all-queries').addEventListener('click', () => {
                const leaveId = currentConfirmId; // معرف الإجازة المحدد حالياً
                confirmMessage.textContent = 'هل أنت متأكد من حذف جميع سجلات الاستعلامات لهذه الإجازة؟';
                currentConfirmAction = async () => {
                    const result = await sendAjaxRequest('delete_all_queries_for_leave', { // تحتاج إلى إضافة هذا الإجراء في PHP
                        leave_id: leaveId
                    });
                    if (result.success) {
                        showToast(result.message || 'تم حذف جميع الاستعلامات لهذه الإجازة.', 'success');
                        queriesDetailsContainer.innerHTML = '<p class="text-center">لا توجد سجلات استعلام لهذه الإجازة.</p>';
                        // تحديث عدد الاستعلامات في جدول الإجازات الرئيسية
                        const leaveRow = leavesTable.querySelector(`tr[data-id="${leaveId}"]`);
                        if (leaveRow) {
                            const queriesCountCell = leaveRow.querySelector('.cell-queries-count');
                            if (queriesCountCell) {
                                queriesCountCell.textContent = 0;
                            }
                        }
                        // تحديث سجل الاستعلامات في جدول QueriesTable
                        await fetchAllLeaves();
                    }
                };
                confirmModal.show();
            });

            // ====== أحداث إدارة إشعارات المدفوعات ======

            // تحديث إشعارات المدفوعات (يمكن أن يتم استدعاؤها دورياً أو عند فتح المودال)
            document.getElementById('btn-payment-notifs').addEventListener('click', async () => {
                showLoading();
                const result = await sendAjaxRequest('fetch_notifications', {});
                hideLoading();
                if (result.success) {
                    updatePaymentNotifications(result.data);
                    currentTableData.notifications_payment = result.data;
                } else {
                    showToast(result.message || 'فشل في جلب الإشعارات.', 'danger');
                }
            });

            // زر تحديث الإشعارات داخل المودال
            document.getElementById('refreshNotifs').addEventListener('click', async () => {
                showLoading();
                const result = await sendAjaxRequest('fetch_notifications', {});
                hideLoading();
                if (result.success) {
                    updatePaymentNotifications(result.data);
                    currentTableData.notifications_payment = result.data;
                } else {
                    showToast(result.message || 'فشل في تحديث الإشعارات.', 'danger');
                }
            });

            // التعامل مع أزرار الإشعارات (تفاصيل، مدفوعة، حذف)
            notifPaymentsList.addEventListener('click', async (e) => {
                const leaveId = e.target.dataset.leave;
                const notificationId = e.target.dataset.id;
                const paymentAmount = e.target.closest('li').dataset.amount;

                if (e.target.classList.contains('btn-view-leave')) {
                    showLoading();
                    const result = await sendAjaxRequest('fetch_leave_details', { leave_id: leaveId }); // تحتاج لإضافة هذا الإجراء في PHP
                    hideLoading();
                    if (result.success && result.leave) {
                        const leave = result.leave;
                        leaveDetailsContainer.innerHTML = `
                    <p><strong>رمز الخدمة:</strong> ${htmlspecialchars(leave.service_code)}</p>
                    <p><strong>المريض:</strong> ${htmlspecialchars(leave.patient_name)} (${htmlspecialchars(leave.identity_number)})</p>
                    <p><strong>الطبيب:</strong> ${htmlspecialchars(leave.doctor_name)} (${htmlspecialchars(leave.doctor_title)})</p>
                    <p><strong>تاريخ الإصدار:</strong> ${htmlspecialchars(leave.issue_date)}</p>
                    <p><strong>بداية الإجازة:</strong> ${htmlspecialchars(leave.start_date)}</p>
                    <p><strong>نهاية الإجازة:</strong> ${htmlspecialchars(leave.end_date)}</p>
                    <p><strong>عدد الأيام:</strong> ${htmlspecialchars(leave.days_count)}</p>
                    <p><strong>نوع الإجازة:</strong> ${leave.is_companion == 1 ? 'مرافق: ' + htmlspecialchars(leave.companion_name) + ' (' + htmlspecialchars(leave.companion_relation) + ')' : 'أساسي'}</p>
                    <p><strong>مدفوعة:</strong> ${leave.is_paid == 1 ? 'نعم' : 'لا'}</p>
                    <p><strong>المبلغ:</strong> ${parseFloat(leave.payment_amount).toFixed(2)}</p>
                    <p><strong>تاريخ الإضافة:</strong> ${htmlspecialchars(leave.created_at)}</p>
                    <p><strong>تاريخ التعديل:</strong> ${htmlspecialchars(leave.updated_at || 'غير متوفر')}</p>
                    <p><strong>عدد الاستعلامات:</strong> ${leave.queries_count}</p>
                `;
                        leaveDetailsModal.show();
                    } else {
                        showToast(result.message || 'فشل في جلب تفاصيل الإجازة.', 'danger');
                    }
                } else if (e.target.classList.contains('btn-pay-notif')) {
                    confirmMessage.textContent = `هل أنت متأكد من تحديد هذه الإجازة كمدفوعة بمبلغ ${paymentAmount}؟`;
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('mark_leave_paid', {
                            leave_id: leaveId,
                            amount: paymentAmount
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            // إزالة الإشعار من القائمة
                            e.target.closest('li').remove();
                            // تحديث حالة "مدفوعة" في جدول الإجازات الرئيسية
                            const leaveRow = leavesTable.querySelector(`tr[data-id="${leaveId}"]`);
                            if (leaveRow) {
                                leaveRow.querySelector('.cell-is-paid').innerHTML = '<span class="badge bg-success">نعم</span>';
                                leaveRow.querySelector('.cell-amount').textContent = parseFloat(paymentAmount).toFixed(2);
                                leaveRow.querySelector('.btn-mark-paid').classList.add('d-none');
                            }
                            updateStats(result.stats);
                            // تحديث إشعارات المدفوعات مرة أخرى
                            await sendAjaxRequest('fetch_notifications', {}).then(res => {
                                if (res.success) updatePaymentNotifications(res.data);
                            });
                        }
                    };
                    confirmModal.show();
                } else if (e.target.classList.contains('btn-del-notif')) {
                    confirmMessage.textContent = 'هل أنت متأكد من حذف هذا الإشعار؟';
                    currentConfirmAction = async () => {
                        const result = await sendAjaxRequest('delete_notification', {
                            notification_id: notificationId
                        });
                        if (result.success) {
                            showToast(result.message, 'success');
                            e.target.closest('li').remove();
                            // تحديث إشعارات المدفوعات مرة أخرى
                            await sendAjaxRequest('fetch_notifications', {}).then(res => {
                                if (res.success) updatePaymentNotifications(res.data);
                            });
                        }
                    };
                    confirmModal.show();
                }
            });

            // زر تأكيد في مودال التأكيد
            confirmYesBtn.addEventListener('click', async () => {
                if (currentConfirmAction) {
                    await currentConfirmAction();
                }
                confirmModal.hide();
                currentConfirmAction = null; // إعادة تعيين
                currentConfirmId = null;
            });

            // ====== أحداث الفرز، البحث، والفلترة للجدول الرئيسي (الإجازات النشطة) ======

            // البحث
            document.getElementById('searchLeaves').addEventListener('input', () => {
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    search: document.getElementById('searchLeaves').value
                });
            });
            document.getElementById('btn-search-leaves').addEventListener('click', () => {
                document.getElementById('searchLeaves').dispatchEvent(new Event('input'));
            });

            // الفلترة حسب التاريخ
            document.getElementById('btn-filter-dates').addEventListener('click', () => {
                const fromDate = document.getElementById('filter_from_date').value;
                const toDate = document.getElementById('filter_to_date').value;
                if (!fromDate || !toDate) {
                    showToast('الرجاء اختيار نطاق تاريخ كامل للفلترة.', 'warning');
                    return;
                }
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    fromDate: fromDate,
                    toDate: toDate
                });
            });
            document.getElementById('btn-reset-dates').addEventListener('click', () => {
                document.getElementById('filter_from_date').value = '';
                document.getElementById('filter_to_date').value = '';
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {}); // إعادة تعيين الفلترة
            });

            // الفلترة حسب حالة الدفع
            document.getElementById('showPaidLeaves').addEventListener('click', () => {
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    typeFilter: 'paid'
                });
            });
            document.getElementById('showUnpaidLeaves').addEventListener('click', () => {
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    typeFilter: 'unpaid'
                });
            });
            document.getElementById('showAllLeaves').addEventListener('click', () => {
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {}); // عرض الكل
            });


            // الفرز
            let currentLeavesSortColumn = 'created_at';
            let currentLeavesSortOrder = 'desc';

            document.getElementById('sortLeavesNewest').addEventListener('click', () => {
                currentLeavesSortColumn = 'created_at';
                currentLeavesSortOrder = 'desc';
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    search: document.getElementById('searchLeaves').value
                }, currentLeavesSortColumn, currentLeavesSortOrder);
            });

            document.getElementById('sortLeavesOldest').addEventListener('click', () => {
                currentLeavesSortColumn = 'created_at';
                currentLeavesSortOrder = 'asc';
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {
                    search: document.getElementById('searchLeaves').value
                }, currentLeavesSortColumn, currentLeavesSortOrder);
            });

            document.getElementById('sortLeavesReset').addEventListener('click', () => {
                currentLeavesSortColumn = 'created_at'; // الافتراضي
                currentLeavesSortOrder = 'desc'; // الافتراضي
                filterAndSortTable(leavesTable, currentTableData.leaves, generateLeaveRow, {}, currentLeavesSortColumn, currentLeavesSortOrder);
                document.getElementById('searchLeaves').value = '';
                document.getElementById('filter_from_date').value = '';
                document.getElementById('filter_to_date').value = '';
            });

            // ====== أحداث الفرز، البحث، والفلترة لجدول الأرشيف ======

            // البحث
            document.getElementById('searchArchived').addEventListener('input', () => {
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    search: document.getElementById('searchArchived').value
                });
            });
            document.getElementById('btn-search-archived').addEventListener('click', () => {
                document.getElementById('searchArchived').dispatchEvent(new Event('input'));
            });

            // الفلترة حسب التاريخ
            document.getElementById('btn-filter-arch-dates').addEventListener('click', () => {
                const fromDate = document.getElementById('filter_arch_from_date').value;
                const toDate = document.getElementById('filter_arch_to_date').value;
                if (!fromDate || !toDate) {
                    showToast('الرجاء اختيار نطاق تاريخ كامل للفلترة.', 'warning');
                    return;
                }
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    fromDate: fromDate,
                    toDate: toDate
                });
            });
            document.getElementById('btn-reset-arch-dates').addEventListener('click', () => {
                document.getElementById('filter_arch_from_date').value = '';
                document.getElementById('filter_arch_to_date').value = '';
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {});
            });

            // الفلترة حسب حالة الدفع
            document.getElementById('showPaidArchived').addEventListener('click', () => {
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    typeFilter: 'paid'
                });
            });
            document.getElementById('showUnpaidArchived').addEventListener('click', () => {
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    typeFilter: 'unpaid'
                });
            });
            document.getElementById('showAllArchived').addEventListener('click', () => {
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {});
            });

            // الفرز
            let currentArchivedSortColumn = 'deleted_at';
            let currentArchivedSortOrder = 'desc';

            document.getElementById('sortArchivedNewest').addEventListener('click', () => {
                currentArchivedSortColumn = 'deleted_at';
                currentArchivedSortOrder = 'desc';
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    search: document.getElementById('searchArchived').value
                }, currentArchivedSortColumn, currentArchivedSortOrder);
            });

            document.getElementById('sortArchivedOldest').addEventListener('click', () => {
                currentArchivedSortColumn = 'deleted_at';
                currentArchivedSortOrder = 'asc';
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {
                    search: document.getElementById('searchArchived').value
                }, currentArchivedSortColumn, currentArchivedSortOrder);
            });

            document.getElementById('sortArchivedReset').addEventListener('click', () => {
                currentArchivedSortColumn = 'deleted_at'; // الافتراضي
                currentArchivedSortOrder = 'desc'; // الافتراضي
                filterAndSortTable(archivedTable, currentTableData.archived, generateArchivedLeaveRow, {}, currentArchivedSortColumn, currentArchivedSortOrder);
                document.getElementById('searchArchived').value = '';
                document.getElementById('filter_arch_from_date').value = '';
                document.getElementById('filter_arch_to_date').value = '';
            });

            // ====== أحداث الفرز، البحث، والفلترة لجدول سجل الاستعلامات ======

            // البحث
            document.getElementById('searchQueries').addEventListener('input', () => {
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {
                    search: document.getElementById('searchQueries').value
                });
            });
            document.getElementById('btn-search-queries').addEventListener('click', () => {
                document.getElementById('searchQueries').dispatchEvent(new Event('input'));
            });

            // الفلترة حسب التاريخ
            document.getElementById('btn-filter-queries-dates').addEventListener('click', () => {
                const fromDate = document.getElementById('filter_q_from_date').value;
                const toDate = document.getElementById('filter_q_to_date').value;
                if (!fromDate || !toDate) {
                    showToast('الرجاء اختيار نطاق تاريخ كامل للفلترة.', 'warning');
                    return;
                }
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {
                    fromDate: fromDate,
                    toDate: toDate
                });
            });
            document.getElementById('btn-reset-queries-dates').addEventListener('click', () => {
                document.getElementById('filter_q_from_date').value = '';
                document.getElementById('filter_q_to_date').value = '';
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {});
            });

            // الفرز
            let currentQueriesSortColumn = 'queried_at';
            let currentQueriesSortOrder = 'desc';

            document.getElementById('sortQueriesNewest').addEventListener('click', () => {
                currentQueriesSortColumn = 'queried_at';
                currentQueriesSortOrder = 'desc';
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {
                    search: document.getElementById('searchQueries').value
                }, currentQueriesSortColumn, currentQueriesSortOrder);
            });

            document.getElementById('sortQueriesOldest').addEventListener('click', () => {
                currentQueriesSortColumn = 'queried_at';
                currentQueriesSortOrder = 'asc';
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {
                    search: document.getElementById('searchQueries').value
                }, currentQueriesSortColumn, currentQueriesSortOrder);
            });

            document.getElementById('sortQueriesReset').addEventListener('click', () => {
                currentQueriesSortColumn = 'queried_at'; // الافتراضي
                currentQueriesSortOrder = 'desc'; // الافتراضي
                filterAndSortTable(queriesTable, currentTableData.queries, generateQueryRow, {}, currentQueriesSortColumn, currentQueriesSortOrder);
                document.getElementById('searchQueries').value = '';
                document.getElementById('filter_q_from_date').value = '';
                document.getElementById('filter_q_to_date').value = '';
            });

            // حذف كل الاستعلامات (من سجل الاستعلامات)
            document.getElementById('deleteAllQueries').addEventListener('click', () => {
                confirmMessage.textContent = 'تحذير! هل أنت متأكد من حذف جميع سجلات الاستعلامات نهائيًا؟ لا يمكن التراجع عن هذا الإجراء.';
                currentConfirmAction = async () => {
                    const result = await sendAjaxRequest('delete_all_queries', {});
                    if (result.success) {
                        showToast(result.message, 'success');
                        currentTableData.queries = [];
                        updateTable(queriesTable, currentTableData.queries, generateQueryRow);
                        // تحديث جميع عدادات الاستعلامات في جدول الإجازات الرئيسية إلى 0
                        leavesTable.querySelectorAll('.cell-queries-count').forEach(cell => cell.textContent = 0);
                    }
                };
                confirmModal.show();
            });

            // عرض تفاصيل إجازة من سجل الاستعلامات
            queriesTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-view-leave-from-query')) {
                    const leaveId = e.target.dataset.leaveId;
                    showLoading();
                    const result = await sendAjaxRequest('fetch_leave_details', { leave_id: leaveId });
                    hideLoading();
                    if (result.success && result.leave) {
                        const leave = result.leave;
                        leaveDetailsContainer.innerHTML = `
                    <p><strong>رمز الخدمة:</strong> ${htmlspecialchars(leave.service_code)}</p>
                    <p><strong>المريض:</strong> ${htmlspecialchars(leave.patient_name)} (${htmlspecialchars(leave.identity_number)})</p>
                    <p><strong>الطبيب:</strong> ${htmlspecialchars(leave.doctor_name)} (${htmlspecialchars(leave.doctor_title)})</p>
                    <p><strong>تاريخ الإصدار:</strong> ${htmlspecialchars(leave.issue_date)}</p>
                    <p><strong>بداية الإجازة:</strong> ${htmlspecialchars(leave.start_date)}</p>
                    <p><strong>نهاية الإجازة:</strong> ${htmlspecialchars(leave.end_date)}</p>
                    <p><strong>عدد الأيام:</strong> ${htmlspecialchars(leave.days_count)}</p>
                    <p><strong>نوع الإجازة:</strong> ${leave.is_companion == 1 ? 'مرافق: ' + htmlspecialchars(leave.companion_name) + ' (' + htmlspecialchars(leave.companion_relation) + ')' : 'أساسي'}</p>
                    <p><strong>مدفوعة:</strong> ${leave.is_paid == 1 ? 'نعم' : 'لا'}</p>
                    <p><strong>المبلغ:</strong> ${parseFloat(leave.payment_amount).toFixed(2)}</p>
                    <p><strong>تاريخ الإضافة:</strong> ${htmlspecialchars(leave.created_at)}</p>
                    <p><strong>تاريخ التعديل:</strong> ${htmlspecialchars(leave.updated_at || 'غير متوفر')}</p>
                    <p><strong>عدد الاستعلامات:</strong> ${leave.queries_count}</p>
                `;
                        leaveDetailsModal.show();
                    } else {
                        showToast(result.message || 'فشل في جلب تفاصيل الإجازة.', 'danger');
                    }
                }
            });

            // ====== أحداث الفرز، البحث لجدول المدفوعات لكل مريض ======

            // البحث
            document.getElementById('searchPayments').addEventListener('input', () => {
                filterAndSortTable(paymentsTable, currentTableData.payments, generatePaymentPatientRow, {
                    search: document.getElementById('searchPayments').value
                });
            });
            document.getElementById('btn-search-payments').addEventListener('click', () => {
                document.getElementById('searchPayments').dispatchEvent(new Event('input'));
            });

            // الفرز
            document.getElementById('sortPaymentsPaid').addEventListener('click', () => {
                filterAndSortTable(paymentsTable, currentTableData.payments, generatePaymentPatientRow, {}, 'paid_amount', 'desc');
            });
            document.getElementById('sortPaymentsUnpaid').addEventListener('click', () => {
                filterAndSortTable(paymentsTable, currentTableData.payments, generatePaymentPatientRow, {}, 'unpaid_amount', 'desc');
            });
            document.getElementById('sortPaymentsReset').addEventListener('click', () => {
                filterAndSortTable(paymentsTable, currentTableData.payments, generatePaymentPatientRow, {}, 'name', 'asc'); // فرز افتراضي بالاسم
                document.getElementById('searchPayments').value = '';
            });

            // توليد صف لجدول المدفوعات لكل مريض
            function generatePaymentPatientRow(p) {
                return `
            <tr data-id="${p.id}">
                <td class="row-num"></td>
                <td>${htmlspecialchars(p.name)}</td>
                <td>${p.total}</td>
                <td>${p.paid_count}</td>
                <td>${p.unpaid_count}</td>
                <td>${parseFloat(p.paid_amount).toFixed(2)}</td>
                <td>${parseFloat(p.unpaid_amount).toFixed(2)}</td>
                <td><button class="btn btn-info btn-sm btn-view-patient-leaves" data-patient-id="${p.id}"><i class="bi bi-eye-fill"></i> عرض</button></td>
            </tr>
        `;
            }

            // عرض إجازات مريض معين من جدول المدفوعات
            paymentsTable.addEventListener('click', async (e) => {
                if (e.target.classList.contains('btn-view-patient-leaves')) {
                    const patientId = e.target.dataset.patientId;
                    showLoading();
                    // جلب الإجازات لهذا المريض فقط
                    const result = await sendAjaxRequest('fetch_leaves_by_patient', { patient_id: patientId }); // تحتاج لإضافة هذا الإجراء في PHP
                    hideLoading();
                    if (result.success && result.leaves) {
                        // عرض هذه الإجازات في مودال جديد أو في جدول مؤقت
                        let tempModal = new bootstrap.Modal(document.getElementById('viewPatientLeavesModal') || createPatientLeavesModal());
                        const modalBody = document.getElementById('viewPatientLeavesModalBody');
                        modalBody.innerHTML = ''; // Clear previous content

                        if (result.leaves.length > 0) {
                            const tableHtml = `
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover text-center" id="patientLeavesDetailTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>رقم</th>
                                        <th>رمز الخدمة</th>
                                        <th>الطبيب</th>
                                        <th>من</th>
                                        <th>إلى</th>
                                        <th>الأيام</th>
                                        <th>مدفوعة؟</th>
                                        <th>المبلغ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${result.leaves.map((leave, index) => `
                                        <tr>
                                            <td>${index + 1}</td>
                                            <td>${htmlspecialchars(leave.service_code)}</td>
                                            <td>${htmlspecialchars(leave.doctor_name)}</td>
                                            <td>${htmlspecialchars(leave.start_date)}</td>
                                            <td>${htmlspecialchars(leave.end_date)}</td>
                                            <td>${htmlspecialchars(leave.days_count)}</td>
                                            <td>${leave.is_paid == 1 ? '<span class="badge bg-success">نعم</span>' : '<span class="badge bg-danger">لا</span>'}</td>
                                            <td>${parseFloat(leave.payment_amount).toFixed(2)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;
                            modalBody.innerHTML = tableHtml;
                        } else {
                            modalBody.innerHTML = '<p class="text-center">لا توجد إجازات لهذا المريض.</p>';
                        }
                        tempModal.show();
                    } else {
                        showToast(result.message || 'فشل في جلب إجازات المريض.', 'danger');
                    }
                }
            });

            // دالة لإنشاء مودال لعرض إجازات المريض إذا لم يكن موجودًا
            function createPatientLeavesModal() {
                const modalHtml = `
            <div class="modal fade" id="viewPatientLeavesModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">إجازات المريض</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>
                        <div class="modal-body" id="viewPatientLeavesModalBody">
                            </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                return document.getElementById('viewPatientLeavesModal');
            }

            // ====== وظائف الطباعة والتصدير ======
            document.getElementById('exportPDF').addEventListener('click', () => {
                exportTableToPdf(leavesTable, 'تقارير_الإجازات_النشطة.pdf', 'تقرير الإجازات المرضية النشطة');
            });

            document.getElementById('exportExcel').addEventListener('click', () => {
                exportTableToExcel(leavesTable, 'تقارير_الإجازات_النشطة.csv');
            });

            document.getElementById('printTable').addEventListener('click', () => {
                printTableContent(leavesTable, 'تقرير الإجازات المرضية النشطة');
            });

            // ====== وظائف HTML Sanitization ======
            function htmlspecialchars(str) {
                if (typeof str !== 'string') return str;
                return str.replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            // ====== جلب البيانات الأولية عند تحميل الصفحة ======
            // يجب أن تكون هذه البيانات متاحة بالفعل في PHP عند تحميل الصفحة، لكن إذا كانت AJAX ضرورية
            // يمكن تفعيلها هنا. حالياً، البيانات موجودة كـ PHP variables.
            // تحديث أرقام الصفوف للجداول الموجودة مسبقًا
            updateRowNumbers(leavesTable);
            updateRowNumbers(archivedTable);
            updateRowNumbers(doctorsTable);
            updateRowNumbers(patientsTable);
            updateRowNumbers(queriesTable);
            updateRowNumbers(paymentsTable);

            // Initial data load into currentTableData (from PHP generated HTML)
            // You should populate these based on the PHP output when the page loads
            currentTableData.leaves = Array.from(leavesTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    id: row.dataset.id,
                    service_code: row.querySelector('.cell-service').textContent,
                    patient_name: row.querySelector('.cell-patient').textContent,
                    identity_number: row.querySelector('.cell-identity').textContent,
                    doctor_name: row.querySelector('.cell-doctor').textContent,
                    doctor_title: row.cells[5].textContent, // Assuming fixed column index
                    doctor_note: row.cells[6].textContent,
                    issue_date: row.querySelector('.cell-issue').textContent,
                    start_date: row.cells[8].textContent,
                    end_date: row.cells[9].textContent,
                    days_count: parseInt(row.cells[10].textContent),
                    is_companion: row.querySelector('.badge.bg-warning') ? 1 : 0,
                    companion_name: row.dataset.compName,
                    companion_relation: row.dataset.compRel,
                    queries_count: parseInt(row.querySelector('.cell-queries-count').textContent),
                    created_at: row.querySelector('.cell-created').textContent,
                    is_paid: row.querySelector('.cell-is-paid .badge.bg-success') ? 1 : 0,
                    payment_amount: parseFloat(row.cells[15].textContent),
                };
            });

            currentTableData.archived = Array.from(archivedTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    id: row.dataset.id,
                    service_code: row.querySelector('.cell-service').textContent,
                    patient_name: row.querySelector('.cell-patient').textContent,
                    identity_number: row.querySelector('.cell-identity').textContent,
                    doctor_name: row.querySelector('.cell-doctor').textContent,
                    doctor_title: row.cells[5].textContent,
                    doctor_note: row.cells[6].textContent,
                    issue_date: row.querySelector('.cell-issue').textContent,
                    start_date: row.cells[8].textContent,
                    end_date: row.cells[9].textContent,
                    days_count: parseInt(row.cells[10].textContent),
                    is_companion: row.querySelector('.badge.bg-warning') ? 1 : 0,
                    companion_name: row.dataset.compName,
                    companion_relation: row.dataset.compRel,
                    queries_count: parseInt(row.querySelector('.cell-queries-count').textContent),
                    deleted_at: row.querySelector('.cell-deleted').textContent,
                    is_paid: row.cells[14].textContent.includes('نعم') ? 1 : 0,
                    payment_amount: parseFloat(row.cells[15].textContent),
                };
            });

            currentTableData.doctors = Array.from(doctorsTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    id: row.dataset.id,
                    name: row.cells[1].textContent,
                    title: row.cells[2].textContent,
                    note: row.cells[3].textContent,
                };
            });

            currentTableData.patients = Array.from(patientsTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    id: row.dataset.id,
                    name: row.cells[1].textContent,
                    identity_number: row.cells[2].textContent,
                };
            });

            currentTableData.queries = Array.from(queriesTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    qid: row.dataset.id,
                    leave_id: row.dataset.leaveId,
                    service_code: row.cells[1].textContent,
                    patient_name: row.cells[2].textContent,
                    identity_number: row.cells[3].textContent,
                    queried_at: row.cells[4].textContent,
                };
            });

            currentTableData.payments = Array.from(paymentsTable.querySelectorAll('tbody tr:not(.no-results)')).map(row => {
                return {
                    id: row.dataset.id,
                    name: row.cells[1].textContent,
                    total: parseInt(row.cells[2].textContent),
                    paid_count: parseInt(row.cells[3].textContent),
                    unpaid_count: parseInt(row.cells[4].textContent),
                    paid_amount: parseFloat(row.cells[5].textContent),
                    unpaid_amount: parseFloat(row.cells[6].textContent),
                };
            });


        });
    </script>
</body>

</html>