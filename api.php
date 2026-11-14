<?php
/*
 * ALDIUM SISTEM - API untuk ESP8266
 *
 * ESP8266 memanggil alamat ini tiap beberapa detik, contoh:
 *   api.php?key=KUNCI&temp=27.4&level=82&ph=7.6&turb=18
 * Server membalas teks sederhana, contoh:
 *   OK|lamp=1|pump=1|feed=0|time=14:03:22
 *   lamp/pump : 1 = nyalakan, 0 = matikan
 *   feed      : 1 = jalankan pemberi pakan SEKALI, 0 = tidak
 * Parameter sensor boleh dikosongkan bila sensor tidak terbaca.
 */
require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function tolak($kode, $pesan)
{
    http_response_code($kode);
    exit('ERR|' . $pesan);
}

$kunci_server = (string)cfg('api_key');
if ($kunci_server === 'GANTI-DENGAN-KUNCI-RAHASIA' || strlen($kunci_server) < 12) {
    tolak(503, 'api_key di config.php belum diganti (min. 12 karakter)');
}
$kunci = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';
if (!hash_equals($kunci_server, $kunci)) {
    tolak(403, 'key salah');
}

$pdo = db();
$sekarang = time();

/* ---- 1) Simpan data sensor (hanya yang valid) ---- */
function baca_angka($nama, $min, $maks)
{
    if (!isset($_REQUEST[$nama])) {
        return null;
    }
    $v = str_replace(',', '.', trim((string)$_REQUEST[$nama]));
    if (!is_numeric($v)) {
        return null;
    }
    $v = (float)$v;
    return ($v < $min || $v > $maks) ? null : $v;
}

$baru = [
    'temp'  => baca_angka('temp', -5, 60),     // sensor error DS18B20 = -127, otomatis ditolak
    'level' => baca_angka('level', 0, 100),
    'ph'    => baca_angka('ph', 0, 14),
    'turb'  => baca_angka('turb', 0, 3000),
];
$ada_data = false;
foreach ($baru as $k => $v) {
    if ($v !== null) {
        state_set($k, $v);
        $ada_data = true;
    }
}
state_set('last_seen', $sekarang);

/* Simpan ke riwayat paling cepat tiap 'simpan_tiap_detik' */
if ($ada_data) {
    $terakhir = (int)state_get('last_saved', 0);
    if ($sekarang - $terakhir >= (int)cfg('simpan_tiap_detik')) {
        $st = $pdo->prepare('INSERT INTO readings (ts, temp, level, ph, turb) VALUES (?, ?, ?, ?, ?)');
        $nilai = function ($k) {
            $v = state_get($k, null);
            return ($v === null || $v === '') ? null : (float)$v;
        };
        $st->execute([$sekarang, $nilai('temp'), $nilai('level'), $nilai('ph'), $nilai('turb')]);
        state_set('last_saved', $sekarang);
    }
}

/* Bersihkan riwayat lama (maksimal sekali per jam) */
if ($sekarang - (int)state_get('last_prune', 0) > 3600) {
    $batas = $sekarang - (int)cfg('simpan_riwayat_hari') * 86400;
    $pdo->prepare('DELETE FROM readings WHERE ts < ?')->execute([$batas]);
    $pdo->prepare('DELETE FROM feed_log WHERE ts < ?')->execute([$batas]);
    state_set('last_prune', $sekarang);
}

/* ---- 2) Tentukan perintah pakan ---- */
$feed = 0;
$pending = (int)state_get('feed_pending', 0);
if ($pending > 0) {
    // Perintah manual hanya berlaku sebentar; jika kadaluarsa, dibuang (aman untuk ikan)
    if ($sekarang - $pending <= (int)cfg('perintah_kadaluarsa_menit') * 60) {
        $feed = 1;
    }
    state_set('feed_pending', 0);   // dikirim sekali saja, tidak diulang
}

if ($feed === 0 && state_get('autofeed', '0') === '1') {
    $toleransi = (int)cfg('jadwal_toleransi_menit') * 60;
    $hari = awal_hari();
    foreach (explode(',', (string)setting('jadwal_pakan')) as $jam) {
        $jam = trim($jam);
        if (!preg_match('/^(\d{2}):(\d{2})$/', $jam, $m)) {
            continue;
        }
        $waktu = $hari + (int)$m[1] * 3600 + (int)$m[2] * 60;
        if ($sekarang >= $waktu && $sekarang <= $waktu + $toleransi) {
            $sumber = 'Jadwal ' . $jam;
            $st = $pdo->prepare('SELECT COUNT(*) FROM feed_log WHERE source = ? AND ts >= ?');
            $st->execute([$sumber, $hari]);
            if ((int)$st->fetchColumn() === 0) {
                $pdo->prepare('INSERT INTO feed_log (ts, source) VALUES (?, ?)')->execute([$sekarang, $sumber]);
                $feed = 1;
                break;
            }
        }
    }
}

/* ---- 3) Balasan untuk ESP8266 ---- */
echo 'OK|lamp=' . (state_get('lamp', '1') === '1' ? 1 : 0)
   . '|pump=' . (state_get('pump', '1') === '1' ? 1 : 0)
   . '|feed=' . $feed
   . '|time=' . date('H:i:s');
