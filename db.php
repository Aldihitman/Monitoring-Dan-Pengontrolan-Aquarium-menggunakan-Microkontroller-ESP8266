<?php
/*
 * ALDIUM SISTEM - Inti sistem (database + login + keamanan)
 * Anda tidak perlu mengedit file ini. Database MySQL dibuat otomatis
 * saat halaman pertama dibuka (file aldium.sql hanya opsional).
 */

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['timezone']);

function cfg($key)
{
    global $CFG;
    return isset($CFG[$key]) ? $CFG[$key] : null;
}

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ---------------- DATABASE (MySQL) ---------------- */

function db()
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $host = cfg('db_host');
    $nama = cfg('db_nama');
    $opsi = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$nama;charset=utf8mb4", cfg('db_user'), cfg('db_pass'), $opsi);
        } catch (PDOException $ex) {
            $kode = isset($ex->errorInfo[1]) ? (int)$ex->errorInfo[1] : (int)$ex->getCode();
            if ($kode !== 1049) {   // 1049 = database belum ada -> kita buatkan
                throw $ex;
            }
            $tmp = new PDO("mysql:host=$host;charset=utf8mb4", cfg('db_user'), cfg('db_pass'), $opsi);
            $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $nama) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO("mysql:host=$host;dbname=$nama;charset=utf8mb4", cfg('db_user'), cfg('db_pass'), $opsi);
        }
    } catch (PDOException $ex) {
        error_log('Aldium DB error: ' . $ex->getMessage());
        http_response_code(500);
        exit('ERR|Tidak bisa terhubung ke MySQL. Pastikan MySQL menyala di XAMPP dan db_host/db_user/db_pass di config.php benar.');
    }
    buat_tabel($pdo);
    return $pdo;
}

function buat_tabel($pdo)
{
    $akhir = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        must_change TINYINT NOT NULL DEFAULT 0
    )' . $akhir);
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT)' . $akhir);
    $pdo->exec('CREATE TABLE IF NOT EXISTS state (k VARCHAR(64) PRIMARY KEY, v TEXT)' . $akhir);
    $pdo->exec('CREATE TABLE IF NOT EXISTS readings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ts BIGINT NOT NULL,
        temp DOUBLE NULL, level DOUBLE NULL, ph DOUBLE NULL, turb DOUBLE NULL,
        KEY idx_readings_ts (ts)
    )' . $akhir);
    $pdo->exec('CREATE TABLE IF NOT EXISTS feed_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ts BIGINT NOT NULL,
        source VARCHAR(100) NOT NULL,
        KEY idx_feed_ts (ts)
    )' . $akhir);
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL,
        ts BIGINT NOT NULL,
        KEY idx_login_ip_ts (ip, ts)
    )' . $akhir);

    // Akun admin pertama
    $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($n === 0) {
        $st = $pdo->prepare('INSERT INTO users (username, password_hash, must_change) VALUES (?, ?, 1)');
        $st->execute([cfg('admin_user'), password_hash(cfg('admin_pass_awal'), PASSWORD_DEFAULT)]);
    }
    // Status awal perangkat
    $awal = ['lamp' => '1', 'pump' => '1', 'autofeed' => '0', 'feed_pending' => '0', 'last_seen' => '0'];
    $st = $pdo->prepare('INSERT IGNORE INTO state (k, v) VALUES (?, ?)');
    foreach ($awal as $k => $v) {
        $st->execute([$k, $v]);
    }
}

function state_get($k, $default = null)
{
    $st = db()->prepare('SELECT v FROM state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function state_set($k, $v)
{
    $st = db()->prepare('REPLACE INTO state (k, v) VALUES (?, ?)');
    $st->execute([$k, (string)$v]);
}

function setting($k)
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    if ($v !== false) {
        return $v;
    }
    $d = cfg('default');
    return isset($d[$k]) ? $d[$k] : null;
}

function setting_set($k, $v)
{
    $st = db()->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
    $st->execute([$k, (string)$v]);
}

/* ---------------- SESI, LOGIN, KEAMANAN ---------------- */

function kirim_header_aman()
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function mulai_sesi()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('aldium_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function user_sekarang()
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT id, username, must_change FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $u ? $u : null;
}

function wajib_login()
{
    mulai_sesi();
    kirim_header_aman();
    $batas = cfg('sesi_menit') * 60;
    if (!empty($_SESSION['uid']) && isset($_SESSION['aktif']) && time() - $_SESSION['aktif'] > $batas) {
        $_SESSION = [];
        session_destroy();
        mulai_sesi();
        flash('Sesi berakhir karena tidak aktif. Silakan login lagi.', 'err');
    }
    $u = user_sekarang();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    $_SESSION['aktif'] = time();
    return $u;
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_periksa()
{
    $t = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('Permintaan tidak valid (token keamanan salah). Kembali dan muat ulang halaman.');
    }
}

function flash($pesan, $tipe = 'ok')
{
    $_SESSION['flash'] = ['pesan' => $pesan, 'tipe' => $tipe];
}

function ambil_flash()
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/* ---------------- STATUS & WAKTU ---------------- */

function lama_lalu($ts)
{
    if ($ts <= 0) {
        return 'belum pernah';
    }
    $d = time() - $ts;
    if ($d < 5) {
        return 'baru saja';
    }
    if ($d < 60) {
        return $d . ' detik lalu';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' menit lalu';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' jam lalu';
    }
    return floor($d / 86400) . ' hari lalu';
}

function esp_online()
{
    $last = (int)state_get('last_seen', 0);
    return $last > 0 && (time() - $last) <= (int)setting('offline_detik');
}

function awal_hari()
{
    return strtotime('today');
}
