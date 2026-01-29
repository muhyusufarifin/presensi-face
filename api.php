<?php
// api.php - Backend Presensi Lengkap (QR & Face Recognition)
// Fitur: Login, Scan (WA & GSheet), Face Recog (via Python), CRUD, Export Excel

session_start();
include 'db.php'; // Pastikan koneksi database benar
date_default_timezone_set('Asia/Jakarta');

// --- HELPER FUNCTIONS ---

function sendJson($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Ambil action dari parameter URL
$action = isset($_GET['action']) ? $_GET['action'] : '';

// --- 1. OTENTIKASI (LOGIN & LOGOUT) ---

if ($action == 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    // Menggunakan MD5 sesuai database lama
    $hashed = md5($password); 

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->bind_param("ss", $username, $hashed);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $user = $res->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['name'] = $user['name']; 
        $_SESSION['role'] = $user['role']; 
        sendJson(['status' => 'success', 'message' => 'Login berhasil']);
    } else {
        sendJson(['status' => 'error', 'message' => 'Username atau password salah']);
    }
}

if ($action == 'logout') {
    session_destroy();
    sendJson(['status' => 'success']);
}

if ($action == 'check_session') {
    if (isset($_SESSION['username'])) {
        sendJson([
            'status' => 'success', 
            'data' => [
                'username' => $_SESSION['username'], 
                'name' => $_SESSION['name'] ?? $_SESSION['username'],
                'role' => $_SESSION['role']
            ]
        ]);
    } else {
        sendJson(['status' => 'error', 'message' => 'Not authenticated']);
    }
}

// --- MIDDLEWARE: CEK LOGIN ---
// Bypass untuk login, scan, dan check_session
$public_actions = ['login', 'scan_process', 'scan_face_process', 'check_session'];
if (!isset($_SESSION['username']) && !in_array($action, $public_actions)) {
    // Izinkan akses langsung untuk export (browser download)
    if (strpos($action, 'export') === false) {
        sendJson(['status' => 'error', 'message' => 'Unauthorized']);
    }
}

// --- 2. DASHBOARD DATA ---

if ($action == 'dashboard_stats') {
    $date = date('Y-m-d');
    
    // Hitung statistik
    $total = $conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
    $hadir = $conn->query("SELECT COUNT(*) as c FROM attendance WHERE status='Hadir' AND date='$date'")->fetch_assoc()['c'];
    $telat = $conn->query("SELECT COUNT(*) as c FROM attendance WHERE status='Terlambat Hadir' AND date='$date'")->fetch_assoc()['c'];
    $alpha = $total - ($hadir + $telat); 
    
    // Cek Jadwal Hari Ini & Status Saat Ini
    $day = date('N'); // 1 (Senin) - 7 (Minggu)
    $time = date('H:i:s');
    
    $schedQuery = $conn->query("SELECT * FROM schedules WHERE day_of_week='$day' AND is_active=1 ORDER BY start_time");
    $schedules = [];
    $current_status = "Lewat Waktu Absen"; // Default
    
    while($r = $schedQuery->fetch_assoc()) {
        $schedules[] = $r;
        if ($time >= $r['start_time'] && $time <= $r['end_time']) {
            $current_status = $r['status_label'];
        }
    }

    // Data 5 Scan Terakhir
    $recentQuery = $conn->query("SELECT name, class, created_at FROM attendance ORDER BY created_at DESC LIMIT 5");
    $recents = [];
    while($r = $recentQuery->fetch_assoc()) {
        $recents[] = $r;
    }

    sendJson([
        'status' => 'success',
        'stats' => [
            'total' => $total,
            'hadir' => $hadir,
            'telat' => $telat,
            'alpha' => $alpha
        ],
        'schedules' => $schedules,
        'current_status' => $current_status,
        'recents' => $recents
    ]);
}

// --- 3. PROSES SCAN (QR CODE & MANUAL) ---

if ($action == 'scan_process') {
    $input = json_decode(file_get_contents('php://input'), true);
    $nis = $input['nis'] ?? '';
    $method = $input['method'] ?? 'QR'; // Default QR, bisa 'Manual'
    
    if (empty($nis)) sendJson(['status' => 'error', 'message' => 'NIS kosong']);

    processAttendance($conn, $nis, $method);
}

// --- 3B. PROSES SCAN WAJAH (FACE RECOGNITION) ---
// Endpoint ini menerima Gambar Base64, kirim ke Python, dapat NIS, lalu absen.

if ($action == 'scan_face_process') {
    // 1. Ambil data JSON (Base64 Image) dari Frontend
    $input = json_decode(file_get_contents('php://input'), true);
    $imgBase64 = $input['image'] ?? '';

    if (empty($imgBase64)) sendJson(['status' => 'error', 'message' => 'Gambar kosong']);

    // 2. Kirim ke Python API (Flask)
    // Pastikan file face_api.py sudah jalan di terminal
    $pythonApiUrl = 'http://localhost:5000/recognize';
    
    $ch = curl_init($pythonApiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['image' => $imgBase64]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Timeout cepat agar tidak loading lama jika python mati
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 10000); 
    
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        sendJson(['status' => 'error', 'message' => 'Server AI Offline. Cek Terminal Python.']);
    }

    $result = json_decode($response, true);

    // 3. Jika Python mengenali wajah
    if (isset($result['status']) && $result['status'] == 'success') {
        $nis = $result['nis'];
        
        // Panggil fungsi utama absensi dengan metode 'FACE'
        processAttendance($conn, $nis, 'FACE');

    } else {
        // Wajah tidak dikenali atau tidak ada wajah
        sendJson(['status' => 'error', 'message' => 'Wajah tidak dikenali / belum terdaftar']);
    }
}

// --- FUNGSI UTAMA ABSENSI (Agar tidak duplikat kode) ---
function processAttendance($conn, $nis, $method) {
    // A. Cek Data Siswa
    $stuQuery = $conn->query("SELECT * FROM students WHERE nis = '$nis'");
    $stu = $stuQuery->fetch_assoc();
    
    if (!$stu) {
        sendJson(['status' => 'error', 'message' => 'NIS ' . $nis . ' tidak terdaftar']);
    }

    // B. Tentukan Status Kehadiran berdasarkan Jadwal
    $day = date('N'); 
    $time = date('H:i:s');
    $date = date('Y-m-d');
    $created_at = date('Y-m-d H:i:s'); 
    
    // Cari jadwal yang cocok
    $schedQ = $conn->query("SELECT * FROM schedules WHERE day_of_week='$day' AND is_active=1 AND '$time' BETWEEN start_time AND end_time LIMIT 1");
    
    if ($schedQ->num_rows > 0) {
        $schedule = $schedQ->fetch_assoc();
        $status = $schedule['status_label'];
    } else {
        $status = 'Lewat Waktu Absen'; 
    }

    // C. Cek Duplikasi Harian
    $cekDuplikasi = $conn->query("SELECT * FROM attendance WHERE nis='$nis' AND date='$date' AND status='$status'");
    if ($cekDuplikasi->num_rows > 0) {
        sendJson(['status' => 'warning', 'message' => 'Sudah Absen (' . $status . ')', 'data' => $stu]);
    }

    // D. Simpan ke Database
    $stmt = $conn->prepare("INSERT INTO attendance (nis, date, status, method, name, class, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $nis, $date, $status, $method, $stu['name'], $stu['class'], $created_at);
    
    if ($stmt->execute()) {
        
        // E. INTEGRASI 1: KIRIM KE GOOGLE SPREADSHEET (Background)
        // Ganti URL ini dengan URL Web App Google Apps Script Anda
        $urlGS = 'https://script.google.com/macros/s/AKfycbw-OTRvVhP3jSYg6Joekpu6kLDqnI-DclkRFcAet78iDOVc0a4x52zo5PNM2XJksztycA/exec';
        
        $postDataGS = [
            'nis' => $nis,
            'date' => $date,
            'status' => $status,
            'method' => $method, // QR / FACE
            'name' => $stu['name'],
            'class' => $stu['class']
        ];

        // Kirim tanpa menunggu response (Fire and Forget)
        $ch = curl_init($urlGS);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postDataGS));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200); 
        curl_exec($ch);
        curl_close($ch);

        // F. INTEGRASI 2: KIRIM NOTIF WA (Local Node.js)
        if (!empty($stu['phone'])) {
            $urlNode = 'http://localhost:3000/send'; 
            
            // Format Nomor 08 -> 628
            $targetPhone = preg_replace('/[^0-9]/', '', $stu['phone']);
            if (substr($targetPhone, 0, 2) === '08') {
                $targetPhone = '62' . substr($targetPhone, 1);
            }

            $waMessage = "*PESAN OTOMATIS SISTEM PRESENSI MAN 2 SURAKARTA*\n\n"
                . "_Assalamu'alaikum wr.wb._\n"
                . "Mohon ijin bapak/ibu wali murid menyampaikan rekap kehadiran ananda:\n\n"
                . "Nama        : *{$stu['name']}*\n"
                . "Kelas       : *{$stu['class']}*\n"
                . "Tanggal     : *$date*\n"
                . "Pukul       : *$time*\n\n"
                . "Telah *$status* pada hari ini.\n"
                . "Atas perhatiannya kami ucapkan Terima Kasih\n"
                . "_Wassalamualaikum wr.wb._";
            
            $waData = json_encode([
                'phoneNumber' => $targetPhone, 
                'message' => $waMessage,
                'key' => 'mandaska308' 
            ]);
            
            $ch2 = curl_init($urlNode);
            curl_setopt($ch2, CURLOPT_POST, 1);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $waData);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT_MS, 200); 
            curl_exec($ch2);
            curl_close($ch2);
        }

        sendJson([
            'status' => 'success', 
            'message' => "Hadir: {$stu['name']}", 
            'data' => $stu
        ]);
    } else {
        sendJson(['status' => 'error', 'message' => 'Gagal menyimpan DB: ' . $conn->error]);
    }
}

// --- 4. MANAJEMEN SISWA (CRUD) ---

if ($action == 'get_students') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['rows']) ? (int)$_GET['rows'] : 10;
    $offset = ($page - 1) * $limit;
    
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $filterClass = isset($_GET['class']) ? $_GET['class'] : '';
    $filterSub = isset($_GET['subclass']) ? $_GET['subclass'] : '';

    $where = "WHERE 1=1";
    if (!empty($search)) $where .= " AND (name LIKE '%$search%' OR nis LIKE '%$search%')";
    if (!empty($filterClass)) $where .= " AND class LIKE '$filterClass%'";
    if (!empty($filterSub)) $where .= " AND class = '$filterSub'";

    $countSql = "SELECT COUNT(*) as c FROM students $where";
    $total = $conn->query($countSql)->fetch_assoc()['c'];

    $sql = "SELECT * FROM students $where ORDER BY class ASC, name ASC LIMIT $offset, $limit";
    $res = $conn->query($sql);
    
    $data = [];
    while ($r = $res->fetch_assoc()) $data[] = $r;

    sendJson(['rows' => $data, 'total' => $total, 'pages' => ceil($total / $limit)]);
}

if ($action == 'save_student') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);

    $nis = $_POST['nis'];
    $name = $_POST['name'];
    $class = $_POST['class'];
    $phone = $_POST['phone'];
    $old_nis = $_POST['old_nis'] ?? '';

    if (!empty($old_nis)) {
        $sql = "UPDATE students SET nis='$nis', name='$name', class='$class', phone='$phone' WHERE nis='$old_nis'";
    } else {
        $sql = "INSERT INTO students (nis, name, class, phone) VALUES ('$nis', '$name', '$class', '$phone')";
    }
    
    if ($conn->query($sql)) sendJson(['status' => 'success']);
    else sendJson(['status' => 'error', 'message' => $conn->error]);
}

if ($action == 'delete_student') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);
    
    $nis = $_POST['nis'];
    $conn->query("DELETE FROM students WHERE nis='$nis'");
    sendJson(['status' => 'success']);
}

if ($action == 'delete_all_students') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Access Denied']);
    $conn->query("DELETE FROM students");
    sendJson(['status' => 'success']);
}

// --- 5. UPLOAD CSV ---

if ($action == 'upload_csv') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle); 

            $successCount = 0;
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($row) < 3) continue;

                $nis = $conn->real_escape_string(trim($row[0]));
                $name = $conn->real_escape_string(trim($row[1]));
                $class = $conn->real_escape_string(trim($row[2]));
                $phone = isset($row[3]) ? $conn->real_escape_string(trim($row[3])) : '';

                if (empty($nis) || empty($name)) continue;

                $conn->query("DELETE FROM students WHERE nis='$nis'");
                $sql = "INSERT INTO students (nis, name, class, phone) VALUES ('$nis', '$name', '$class', '$phone')";
                if ($conn->query($sql)) $successCount++;
            }
            fclose($handle);
            sendJson(['status' => 'success', 'message' => "$successCount data berhasil diimpor."]);
        }
    }
    sendJson(['status' => 'error', 'message' => 'Gagal membaca file CSV.']);
}

// --- 6. DATA ABSENSI ---

if ($action == 'get_attendance') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['rows']) ? (int)$_GET['rows'] : 10;
    $offset = ($page - 1) * $limit;
    
    $dateStart = $_GET['start'] ?? '';
    $dateEnd = $_GET['end'] ?? '';
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $class = $_GET['class'] ?? '';
    $subclass = $_GET['subclass'] ?? '';

    $where = "WHERE 1=1";
    if ($dateStart) $where .= " AND DATE(attendance.created_at) >= '$dateStart'";
    if ($dateEnd) $where .= " AND DATE(attendance.created_at) <= '$dateEnd'";
    if ($status) $where .= " AND attendance.status = '$status'";
    
    if ($class) {
        if ($class == 'X') $where .= " AND students.class LIKE 'X%' AND students.class NOT LIKE 'XI%'";
        elseif ($class == 'XI') $where .= " AND students.class LIKE 'XI%' AND students.class NOT LIKE 'XII%'";
        else $where .= " AND students.class LIKE '$class%'";
    }
    
    if ($subclass) $where .= " AND students.class = '$subclass'";
    if ($search) $where .= " AND (students.name LIKE '%$search%' OR students.nis LIKE '%$search%')";

    $baseSql = "FROM attendance JOIN students ON attendance.nis = students.nis $where";
    $total = $conn->query("SELECT COUNT(*) as c $baseSql")->fetch_assoc()['c'];

    $sql = "SELECT attendance.*, students.name, students.class $baseSql ORDER BY attendance.created_at DESC LIMIT $offset, $limit";
    $res = $conn->query($sql);
    
    $data = [];
    while ($r = $res->fetch_assoc()) $data[] = $r;

    sendJson(['rows' => $data, 'total' => $total, 'pages' => ceil($total / $limit)]);
}

if ($action == 'delete_attendance') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);
    $id = $_POST['id'];
    $conn->query("DELETE FROM attendance WHERE id='$id'");
    sendJson(['status' => 'success']);
}

if ($action == 'delete_all_attendance') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Access Denied']);
    $conn->query("DELETE FROM attendance");
    sendJson(['status' => 'success']);
}

// --- 7. DETAIL & EXPORT SISWA ---

if ($action == 'get_student_detail') {
    if ($_SESSION['role'] == 'user') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);

    $nis = $_GET['nis'];
    $stu = $conn->query("SELECT * FROM students WHERE nis='$nis'")->fetch_assoc();
    
    $statsRes = $conn->query("SELECT status, COUNT(*) as count FROM attendance WHERE nis='$nis' GROUP BY status");
    $stats = [];
    while($r = $statsRes->fetch_assoc()) $stats[$r['status']] = $r['count'];

    $histRes = $conn->query("SELECT * FROM attendance WHERE nis='$nis' ORDER BY created_at DESC");
    $history = [];
    while($r = $histRes->fetch_assoc()) $history[] = $r;

    sendJson(['status' => 'success', 'student' => $stu, 'stats' => $stats, 'history' => $history]);
}

if ($action == 'export_student_detail') {
    if ($_SESSION['role'] == 'user') die("Akses Ditolak");

    $nis = $_GET['nis'] ?? '';
    $stu = $conn->query("SELECT name FROM students WHERE nis='$nis'")->fetch_assoc();
    if (!$stu) die("Siswa tidak ditemukan");

    $cleanName = preg_replace('/[^A-Za-z0-9]/', '_', $stu['name']);
    $filename = "Riwayat_{$cleanName}_" . date('d-m-Y') . ".xls";

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<table border="1">';
    echo '<thead><tr style="background-color: #f0f0f0;"><th>Tanggal</th><th>Jam</th><th>Status</th><th>Metode</th></tr></thead>';
    echo '<tbody>';
    $res = $conn->query("SELECT * FROM attendance WHERE nis='$nis' ORDER BY created_at DESC");
    while($r = $res->fetch_assoc()) {
        $dateIndo = date('d-m-Y', strtotime($r['date']));
        $timeOnly = date('H:i:s', strtotime($r['created_at']));
        echo "<tr><td>{$dateIndo}</td><td>'{$timeOnly}</td><td>{$r['status']}</td><td>{$r['method']}</td></tr>";
    }
    echo '</tbody></table>';
    exit;
}

// --- 8. MANAJEMEN USER ---

if ($action == 'get_users') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Access Denied']);
    $res = $conn->query("SELECT id, username, name, role FROM users");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    sendJson(['status' => 'success', 'data' => $data]);
}

if ($action == 'save_user') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Access Denied']);
    
    $id = $_POST['id'] ?? '';
    $username = $_POST['username'];
    $name = $_POST['name']; 
    $role = $_POST['role'];
    $password = $_POST['password']; 

    if (!empty($id)) {
        $sql = "UPDATE users SET username='$username', name='$name', role='$role' WHERE id='$id'";
        if (!empty($password)) {
            $md5 = md5($password);
            $sql = "UPDATE users SET username='$username', name='$name', role='$role', password='$md5' WHERE id='$id'";
        }
        $conn->query($sql);
    } else {
        $md5 = md5($password);
        $conn->query("INSERT INTO users (username, name, password, role) VALUES ('$username', '$name', '$md5', '$role')");
    }
    sendJson(['status' => 'success']);
}

if ($action == 'delete_user') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Access Denied']);
    $id = $_POST['id'];
    if ($id == $_SESSION['user_id']) sendJson(['status' => 'error', 'message' => 'Tidak bisa hapus diri sendiri']);
    $conn->query("DELETE FROM users WHERE id='$id'");
    sendJson(['status' => 'success']);
}

// --- 9. EXPORT GLOBAL ---

if ($action == 'export_attendance') {
    if ($_SESSION['role'] == 'user') die("Akses Ditolak");
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"rekap_absensi_".date('d-m-Y').".xls\"");
    
    echo '<table border="1"><thead><tr style="background-color: #4472C4; color: #fff;"><th>NIS</th><th>Nama</th><th>Kelas</th><th>Tanggal</th><th>Waktu</th><th>Status</th><th>Metode</th></tr></thead><tbody>';
    $res = $conn->query("SELECT attendance.*, students.name, students.class FROM attendance JOIN students ON attendance.nis = students.nis ORDER BY attendance.created_at DESC");
    while($r = $res->fetch_assoc()) {
        echo "<tr><td>'{$r['nis']}</td><td>{$r['name']}</td><td>{$r['class']}</td><td>{$r['date']}</td><td>{$r['created_at']}</td><td>{$r['status']}</td><td>{$r['method']}</td></tr>";
    }
    echo '</tbody></table>';
    exit;
}

// --- 10. JADWAL ---

if ($action == 'get_all_schedules') {
    $res = $conn->query("SELECT * FROM schedules ORDER BY day_of_week ASC, start_time ASC");
    $data = [];
    while($r = $res->fetch_assoc()) $data[] = $r;
    sendJson(['status' => 'success', 'data' => $data]);
}

if ($action == 'save_schedule') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'];
    $days = $_POST['day'] ?? []; 
    $start = $_POST['start'];
    $end = $_POST['end'];
    $status = $_POST['status']; 
    $active = isset($_POST['is_active']) ? 1 : 0;

    if (!is_array($days)) $days = [$days];
    if (empty($days)) sendJson(['status' => 'error', 'message' => 'Pilih hari']);

    if (!empty($id)) $conn->query("DELETE FROM schedules WHERE id='$id'");

    $stmt = $conn->prepare("INSERT INTO schedules (schedule_name, day_of_week, start_time, end_time, status_label, is_active) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($days as $d) {
        $stmt->bind_param("sisssi", $name, $d, $start, $end, $status, $active);
        $stmt->execute();
    }
    sendJson(['status' => 'success']);
}

if ($action == 'delete_schedule') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);
    $id = $_POST['id'];
    $conn->query("DELETE FROM schedules WHERE id='$id'");
    sendJson(['status' => 'success']);
}

if ($action == 'delete_all_schedules') {
    if ($_SESSION['role'] != 'admin') sendJson(['status' => 'error', 'message' => 'Akses Ditolak']);
    $conn->query("DELETE FROM schedules");
    sendJson(['status' => 'success']);
}

// --- 11. OPTIONS ---

if ($action == 'get_status_options') {
    $statuses = [];
    $q1 = $conn->query("SELECT DISTINCT status FROM attendance");
    while($r = $q1->fetch_assoc()) if($r['status']) $statuses[] = $r['status'];
    
    $q2 = $conn->query("SELECT DISTINCT status_label FROM schedules");
    while($r = $q2->fetch_assoc()) if($r['status_label']) $statuses[] = $r['status_label'];
    
    $statuses = array_values(array_unique($statuses));
    sort($statuses);
    sendJson(['status' => 'success', 'data' => $statuses]);
}
?>