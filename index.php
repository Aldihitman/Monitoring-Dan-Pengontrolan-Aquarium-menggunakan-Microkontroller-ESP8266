<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$user = wajib_login();

/* =========================================================
 * 1) PROSES TOMBOL KONTROL (lampu, pompa, jadwal pakan, pakan manual)
 * ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_periksa();
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';
    $nama = ['lamp' => 'Lampu akuarium', 'pump' => 'Pompa air', 'autofeed' => 'Jadwal pakan otomatis'];

    if ($aksi === 'toggle') {
        $dev = isset($_POST['device']) ? $_POST['device'] : '';
        if (isset($nama[$dev])) {
            $baru = state_get($dev, '0') === '1' ? '0' : '1';
            state_set($dev, $baru);
            flash($nama[$dev] . ($baru === '1' ? ' diaktifkan.' : ' dinonaktifkan.') .
                ($dev !== 'autofeed' ? ' Perangkat akan menyesuaikan dalam beberapa detik.' : ''));
        }
    } elseif ($aksi === 'feed') {
        $pdo = db();
        if (!esp_online()) {
            flash('Perangkat offline, perintah pakan tidak dikirim.', 'err');
        } elseif ((int)state_get('feed_pending', 0) > 0) {
            flash('Perintah pakan sebelumnya masih menunggu diambil perangkat.', 'err');
        } else {
            $terakhir = (int)$pdo->query('SELECT MAX(ts) FROM feed_log')->fetchColumn();
            $jeda = (int)setting('pakan_jeda_menit') * 60;
            $st = $pdo->prepare('SELECT COUNT(*) FROM feed_log WHERE ts >= ?');
            $st->execute([awal_hari()]);
            $hari_ini = (int)$st->fetchColumn();

            if ($terakhir && time() - $terakhir < $jeda) {
                $sisa = (int)ceil(($jeda - (time() - $terakhir)) / 60);
                flash('Pakan terakhir baru saja diberikan. Tunggu ' . $sisa . ' menit lagi agar ikan tidak kekenyangan.', 'err');
            } elseif ($hari_ini >= (int)setting('pakan_maks_per_hari')) {
                flash('Batas pemberian pakan hari ini (' . (int)setting('pakan_maks_per_hari') . 'x) sudah tercapai.', 'err');
            } else {
                state_set('feed_pending', time());
                $pdo->prepare('INSERT INTO feed_log (ts, source) VALUES (?, ?)')
                    ->execute([time(), 'Manual (' . $user['username'] . ')']);
                flash('Perintah pakan dikirim. Perangkat akan menjalankannya dalam beberapa detik.');
            }
        }
    }
    header('Location: index.php#kontrol');
    exit;
}

/* =========================================================
 * 2) AMBIL DATA
 * ========================================================= */
function nilai_state($k)
{
    $v = state_get($k, null);
    return ($v === null || $v === '') ? null : (float)$v;
}

function angka($v, $desimal = 1)
{
    return number_format((float)$v, $desimal, '.', '');
}

$d = [
    'temp'  => nilai_state('temp'),
    'level' => nilai_state('level'),
    'ph'    => nilai_state('ph'),
    'turb'  => nilai_state('turb'),
];
$last_seen = (int)state_get('last_seen', 0);
$online = esp_online();
$lamp = state_get('lamp', '1') === '1';
$pump = state_get('pump', '1') === '1';
$autofeed = state_get('autofeed', '0') === '1';
$pakan_menunggu = (int)state_get('feed_pending', 0) > 0;

$S = [
    'suhu_min'  => (float)setting('suhu_min'),  'suhu_max' => (float)setting('suhu_max'),
    'ph_min'    => (float)setting('ph_min'),    'ph_max'   => (float)setting('ph_max'),
    'level_min' => (float)setting('level_min'), 'keruh_max' => (float)setting('keruh_max'),
];

function status_suhu($v, $S)
{
    if ($v === null) return ['Belum ada data', 'warn'];
    return ($v < $S['suhu_min'] || $v > $S['suhu_max']) ? ['Periksa suhu', 'warn'] : ['Normal', ''];
}
function status_level($v, $S)
{
    if ($v === null) return ['Belum ada data', 'warn'];
    if ($v < $S['level_min'] - 20) return ['Air rendah', 'bad'];
    if ($v < $S['level_min']) return ['Periksa', 'warn'];
    return ['Aman', ''];
}
function status_ph($v, $S)
{
    if ($v === null) return ['Belum ada data', 'warn'];
    return ($v < $S['ph_min'] || $v > $S['ph_max']) ? ['Periksa pH', 'warn'] : ['Normal', ''];
}
function status_keruh($v, $S)
{
    if ($v === null) return ['Belum ada data', 'warn'];
    if ($v > $S['keruh_max'] * 1.5) return ['Keruh', 'bad'];
    if ($v > $S['keruh_max']) return ['Mulai keruh', 'warn'];
    return ['Jernih', ''];
}
$st_suhu  = status_suhu($d['temp'], $S);
$st_level = status_level($d['level'], $S);
$st_ph    = status_ph($d['ph'], $S);
$st_keruh = status_keruh($d['turb'], $S);

/* ---- Notifikasi (dihitung dari data terakhir) ---- */
$notif = [];
if ($last_seen === 0) {
    $notif[] = ['warning', 'ℹ', 'Belum ada data dari perangkat', 'Nyalakan ESP8266 dan pastikan alamat server serta API key sudah benar.'];
} else {
    if (!$online) {
        $notif[] = ['critical', '📡', 'Perangkat offline', 'ESP8266 tidak mengirim data sejak ' . lama_lalu($last_seen) . '. Periksa listrik dan WiFi.'];
    }
    $awalan = $online ? '' : '(data lama) ';
    if ($d['temp'] !== null && $st_suhu[1] !== '') {
        $notif[] = ['warning', '🌡', 'Suhu air di luar target', $awalan . 'Suhu ' . angka($d['temp']) . ' °C (target ' . angka($S['suhu_min'], 0) . '–' . angka($S['suhu_max'], 0) . ' °C).'];
    }
    if ($d['level'] !== null && $st_level[1] !== '') {
        $notif[] = ['critical', '💧', 'Ketinggian air rendah', $awalan . 'Level air ' . round($d['level']) . '%. Periksa volume air dan sensor.'];
    }
    if ($d['ph'] !== null && $st_ph[1] !== '') {
        $notif[] = ['warning', '🧪', 'pH air di luar target', $awalan . 'pH ' . angka($d['ph']) . ' (target ' . angka($S['ph_min']) . '–' . angka($S['ph_max']) . '). Verifikasi dengan alat ukur lain.'];
    }
    if ($d['turb'] !== null && $st_keruh[1] !== '') {
        $notif[] = ['warning', '〰', 'Air mulai keruh', $awalan . 'Kekeruhan ' . round($d['turb']) . ' NTU. Pertimbangkan memeriksa filter.'];
    }
    if ($online && count($notif) === 0) {
        $notif[] = ['', '✓', 'Semua parameter dalam rentang', 'Suhu, level air, pH, dan kekeruhan sesuai target.'];
    }
}
$ada_kritis = false;
$ada_warn = false;
foreach ($notif as $n) {
    if ($n[0] === 'critical') $ada_kritis = true;
    if ($n[0] === 'warning') $ada_warn = true;
}

/* ---- Data grafik: rata-rata per periode, 12 titik terakhir ---- */
$per = max(1, (int)cfg('grafik_menit_per_titik')) * 60;
$rows = db()->query('SELECT FLOOR(ts / ' . $per . ') AS bk, AVG(temp) AS temp, AVG(ph) AS ph, MAX(ts) AS ts
                     FROM readings GROUP BY bk ORDER BY bk DESC LIMIT 12')->fetchAll();
$titik = array_reverse($rows);
$mode = isset($_GET['grafik']) ? $_GET['grafik'] : 'both';
if (!in_array($mode, ['both', 'temp', 'ph'], true)) {
    $mode = 'both';
}

function grafik_svg($pts, $mode)
{
    $W = 560; $H = 250; $pl = 38; $pr = 38; $pt = 16; $pb = 28;
    $cw = $W - $pl - $pr; $ch = $H - $pt - $pb;
    $f = function ($x) { return number_format($x, 1, '.', ''); };
    $o = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Grafik tren suhu dan pH">';
    for ($i = 0; $i <= 6; $i++) {
        $y = $pt + $ch * $i / 6;
        $o .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $f($y) . '" y2="' . $f($y) . '" stroke="#20354b" stroke-width="1"/>';
        if ($mode !== 'ph') {
            $o .= '<text x="' . ($pl - 6) . '" y="' . $f($y + 3.5) . '" text-anchor="end" font-size="11" fill="#38bdf8">' . (32 - $i * 2) . '</text>';
        }
        if ($mode !== 'temp') {
            $o .= '<text x="' . ($W - $pr + 6) . '" y="' . $f($y + 3.5) . '" text-anchor="start" font-size="11" fill="#34d399">' . number_format(9 - $i * 0.5, 1, '.', '') . '</text>';
        }
    }
    $n = count($pts);
    if ($n < 2) {
        $o .= '<text x="' . ($W / 2) . '" y="' . ($H / 2) . '" text-anchor="middle" font-size="13" fill="#7890a8">Belum cukup data untuk grafik</text></svg>';
        return $o;
    }
    $series = [];
    if ($mode !== 'ph') $series[] = ['temp', '#38bdf8', 20, 32];
    if ($mode !== 'temp') $series[] = ['ph', '#34d399', 6, 9];
    foreach ($series as $s) {
        $p = [];
        foreach ($pts as $i => $row) {
            if ($row[$s[0]] === null) continue;
            $rasio = ($row[$s[0]] - $s[2]) / ($s[3] - $s[2]);
            $rasio = max(0, min(1, $rasio));
            $p[] = [$pl + $cw * $i / ($n - 1), $pt + $ch * (1 - $rasio)];
        }
        if (count($p) > 1) {
            $str = [];
            foreach ($p as $q) $str[] = $f($q[0]) . ',' . $f($q[1]);
            $o .= '<polyline points="' . implode(' ', $str) . '" fill="none" stroke="' . $s[1] . '" stroke-width="2.3" stroke-linejoin="round"/>';
        }
        foreach ($p as $q) {
            $o .= '<circle cx="' . $f($q[0]) . '" cy="' . $f($q[1]) . '" r="2.8" fill="' . $s[1] . '"/>';
        }
    }
    foreach ($pts as $i => $row) {
        if ($i % 2 === 0) {
            $o .= '<text x="' . $f($pl + $cw * $i / ($n - 1)) . '" y="' . ($H - 8) . '" text-anchor="middle" font-size="11" fill="#7890a8">' . date('H:i', (int)$row['ts']) . '</text>';
        }
    }
    return $o . '</svg>';
}

$riwayat = db()->query('SELECT * FROM readings ORDER BY ts DESC LIMIT 8')->fetchAll();
$log_pakan = db()->query('SELECT * FROM feed_log ORDER BY ts DESC LIMIT 6')->fetchAll();

/* =========================================================
 * 3) TAMPILAN
 * ========================================================= */
halaman_awal('Dashboard Monitoring', 'OVERVIEW', 'dashboard', $user, (int)cfg('refresh_detik'));

function tombol_toggle($dev, $aktif, $label)
{
    echo '<form method="post">' . csrf_field() .
        '<input type="hidden" name="aksi" value="toggle"><input type="hidden" name="device" value="' . e($dev) . '">' .
        '<button class="toggle' . ($aktif ? ' on' : '') . '" type="submit" aria-pressed="' . ($aktif ? 'true' : 'false') . '" aria-label="' . e($label) . '"><span></span></button></form>';
}
?>
    <?php if ($user['must_change']): ?>
    <div class="banner">⚠ Anda masih memakai password awal. <a href="pengaturan.php" style="text-decoration:underline">Ganti password sekarang</a> agar sistem aman.</div>
    <?php endif; ?>

    <section class="welcome">
      <div><h2>Selamat datang di <?= e(cfg('nama_sistem')) ?> 👋</h2><p>Pantau kualitas air dan kelola perangkat akuarium dari satu dashboard.</p></div>
      <div class="tank-badge"><?= e(cfg('nama_akuarium')) ?> &nbsp; <b style="<?= $online ? '' : 'color:#fb7185' ?>">● <?= $online ? 'ONLINE' : 'OFFLINE' ?></b></div>
    </section>

    <div class="section-head" id="monitoring"><h2>Parameter kualitas air</h2><span>Data terakhir: <?= e(lama_lalu($last_seen)) ?></span></div>
    <section class="metrics">
      <article class="metric"><div class="metric-top"><span>Suhu air</span><span class="metric-icon">🌡️</span></div>
        <div class="value"><?= $d['temp'] === null ? '--' : angka($d['temp']) ?><small> °C</small></div>
        <div class="metric-foot"><span>Target: <?= angka($S['suhu_min'], 0) ?>–<?= angka($S['suhu_max'], 0) ?> °C</span><span class="status <?= e($st_suhu[1]) ?>"><?= e($st_suhu[0]) ?></span></div></article>
      <article class="metric"><div class="metric-top"><span>Ketinggian air</span><span class="metric-icon">💧</span></div>
        <div class="value"><?= $d['level'] === null ? '--' : round($d['level']) ?><small> %</small></div>
        <div class="metric-foot"><span>Minimal: <?= angka($S['level_min'], 0) ?>%</span><span class="status <?= e($st_level[1]) ?>"><?= e($st_level[0]) ?></span></div></article>
      <article class="metric"><div class="metric-top"><span>pH air</span><span class="metric-icon">🧪</span></div>
        <div class="value"><?= $d['ph'] === null ? '--' : angka($d['ph']) ?></div>
        <div class="metric-foot"><span>Rentang: <?= angka($S['ph_min']) ?>–<?= angka($S['ph_max']) ?></span><span class="status <?= e($st_ph[1]) ?>"><?= e($st_ph[0]) ?></span></div></article>
      <article class="metric"><div class="metric-top"><span>Kekeruhan</span><span class="metric-icon">〰️</span></div>
        <div class="value"><?= $d['turb'] === null ? '--' : round($d['turb']) ?><small> NTU</small></div>
        <div class="metric-foot"><span>Semakin rendah semakin jernih</span><span class="status <?= e($st_keruh[1]) ?>"><?= e($st_keruh[0]) ?></span></div></article>
    </section>

    <div class="section-head"><h2>Tren kondisi akuarium</h2><span>Rata-rata tiap <?= (int)cfg('grafik_menit_per_titik') ?> menit, 12 titik terakhir</span></div>
    <section class="content-grid">
      <article class="card">
        <div class="card-head"><div><h3>Grafik monitoring</h3><p>Perubahan suhu dan pH dari waktu ke waktu</p></div>
          <div class="tabs">
            <a href="?grafik=both#monitoring" class="<?= $mode === 'both' ? 'on' : '' ?>">Suhu + pH</a>
            <a href="?grafik=temp#monitoring" class="<?= $mode === 'temp' ? 'on' : '' ?>">Suhu</a>
            <a href="?grafik=ph#monitoring" class="<?= $mode === 'ph' ? 'on' : '' ?>">pH</a>
          </div></div>
        <?= grafik_svg($titik, $mode) ?>
        <div class="legend">
          <?php if ($mode !== 'ph'): ?><span><i style="background:#38bdf8"></i>Suhu (°C, sumbu kiri)</span><?php endif; ?>
          <?php if ($mode !== 'temp'): ?><span><i style="background:#34d399"></i>pH (sumbu kanan)</span><?php endif; ?>
        </div>
      </article>

      <article class="card" id="kontrol">
        <div class="card-head"><div><h3>Kontrol perangkat</h3><p>Perintah dikirim ke ESP8266 saat perangkat menghubungi server</p></div><span class="status <?= $online ? '' : 'bad' ?>"><?= $online ? 'LIVE' : 'OFFLINE' ?></span></div>
        <div class="controls">
          <div class="control"><div class="control-top"><div><div class="control-title">💡 Lampu akuarium</div><div class="control-sub">Penerangan</div></div><?php tombol_toggle('lamp', $lamp, 'Nyalakan atau matikan lampu'); ?></div>
            <div class="control-state">Status: <b class="<?= $lamp ? '' : 'off' ?>"><?= $lamp ? 'Aktif' : 'Nonaktif' ?></b></div></div>
          <div class="control"><div class="control-top"><div><div class="control-title">🫧 Pompa air</div><div class="control-sub">Sirkulasi air</div></div><?php tombol_toggle('pump', $pump, 'Nyalakan atau matikan pompa'); ?></div>
            <div class="control-state">Status: <b class="<?= $pump ? '' : 'off' ?>"><?= $pump ? 'Aktif' : 'Nonaktif' ?></b></div></div>
          <div class="control"><div class="control-top"><div><div class="control-title">🍽️ Pakan otomatis</div><div class="control-sub">Jadwal: <?= e(setting('jadwal_pakan')) ?></div></div><?php tombol_toggle('autofeed', $autofeed, 'Aktifkan atau matikan jadwal pakan'); ?></div>
            <div class="control-state">Jadwal: <b class="<?= $autofeed ? '' : 'off' ?>"><?= $autofeed ? 'Aktif' : 'Nonaktif' ?></b></div></div>
          <div class="control"><div class="control-top"><div><div class="control-title">📡 Koneksi ESP8266</div><div class="control-sub">Status perangkat</div></div></div>
            <div class="control-state">Status: <b style="color:<?= $online ? '#34d399' : ($last_seen ? '#fb7185' : '#fbbf24') ?>"><?= $online ? 'Terhubung' : ($last_seen ? 'Offline' : 'Belum terhubung') ?></b></div></div>
        </div>
        <?php if ($pakan_menunggu): ?>
          <span class="feed-button disabled">⏳ Menunggu perangkat menjalankan pakan…</span>
        <?php elseif (!$online): ?>
          <span class="feed-button disabled">▶ Beri pakan sekarang (perangkat offline)</span>
        <?php else: ?>
          <a class="feed-button" href="#modal-pakan">▶ Beri pakan sekarang</a>
        <?php endif; ?>
        <div class="demo-note">Batas pakan manual: jeda <?= (int)setting('pakan_jeda_menit') ?> menit, maksimal <?= (int)setting('pakan_maks_per_hari') ?>x per hari.</div>
      </article>
    </section>

    <div class="section-head"><h2>Ringkasan akuarium</h2><span>Informasi tambahan</span></div>
    <section class="bottom-grid">
      <article class="card"><div class="card-head"><div><h3>Level air &amp; kejernihan</h3><p>Indikator visual</p></div></div><div class="water-row">
        <div class="water-box"><div class="label">Ketinggian air</div><div class="big"><?= $d['level'] === null ? '--' : round($d['level']) . '%' ?></div><div class="bar"><span style="width:<?= $d['level'] === null ? 0 : max(0, min(100, round($d['level']))) ?>%"></span></div></div>
        <div class="water-box"><div class="label">Kekeruhan air</div><div class="big"><?= $d['turb'] === null ? '--' : round($d['turb']) . ' NTU' ?></div><div class="bar"><span style="width:<?= $d['turb'] === null ? 0 : max(0, min(100, round($d['turb']))) ?>%"></span></div></div>
      </div></article>
      <article class="card" id="notifikasi"><div class="card-head"><div><h3>Notifikasi kondisi air</h3><p>Peringatan berdasarkan nilai sensor</p></div><span class="status <?= $ada_kritis ? 'bad' : ($ada_warn ? 'warn' : '') ?>"><?= count($notif) ?> info</span></div><div class="alerts">
        <?php foreach ($notif as $n): ?>
        <div class="alert <?= e($n[0]) ?>"><div class="alert-icon"><?= $n[1] ?></div><div><strong><?= e($n[2]) ?></strong><p><?= e($n[3]) ?></p></div></div>
        <?php endforeach; ?>
      </div></article>
    </section>

    <section class="bottom-grid" style="margin-top:14px">
      <article class="card"><div class="card-head"><div><h3>Pembacaan terakhir</h3><p>Disimpan tiap <?= (int)cfg('simpan_tiap_detik') ?> detik</p></div></div>
        <?php if (!$riwayat): ?><div class="empty">Belum ada data.</div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Suhu</th><th>Level</th><th>pH</th><th>Keruh</th></tr></thead><tbody>
          <?php foreach ($riwayat as $r): ?>
          <tr><td><?= date('d/m H:i:s', (int)$r['ts']) ?></td><td><?= $r['temp'] === null ? '-' : angka($r['temp']) . ' °C' ?></td><td><?= $r['level'] === null ? '-' : round($r['level']) . '%' ?></td><td><?= $r['ph'] === null ? '-' : angka($r['ph']) ?></td><td><?= $r['turb'] === null ? '-' : round($r['turb']) . ' NTU' ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </article>
      <article class="card"><div class="card-head"><div><h3>Riwayat pemberian pakan</h3><p>Manual dan terjadwal</p></div></div>
        <?php if (!$log_pakan): ?><div class="empty">Belum ada riwayat pakan.</div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Waktu</th><th>Sumber</th></tr></thead><tbody>
          <?php foreach ($log_pakan as $r): ?>
          <tr><td><?= date('d/m H:i', (int)$r['ts']) ?></td><td><?= e($r['source']) ?></td></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?>
      </article>
    </section>

<div class="modal-bg" id="modal-pakan" role="dialog" aria-modal="true" aria-labelledby="judulPakan">
  <a class="close-area" href="#kontrol" aria-label="Tutup"></a>
  <div class="modal">
    <h3 id="judulPakan">Beri pakan sekarang?</h3>
    <p>Ikan molly mudah kekenyangan. Pastikan ikan memang belum diberi makan, dan takaran pakan di alat sudah dibatasi.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="feed">
      <div class="modal-actions"><a class="btn" href="#kontrol">Batal</a><button class="btn primary" type="submit">Beri pakan</button></div>
    </form>
  </div>
</div>
<?php halaman_akhir(); ?>
