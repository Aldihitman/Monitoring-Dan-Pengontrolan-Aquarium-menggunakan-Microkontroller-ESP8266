<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
$user = wajib_login();

function ke_angka($s)
{
    $s = str_replace(',', '.', trim((string)$s));
    return is_numeric($s) ? (float)$s : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_periksa();
    $aksi = isset($_POST['aksi']) ? $_POST['aksi'] : '';

    if ($aksi === 'ambang') {
        $f = [
            'suhu_min'  => [ke_angka($_POST['suhu_min'] ?? ''), 0, 50],
            'suhu_max'  => [ke_angka($_POST['suhu_max'] ?? ''), 0, 50],
            'ph_min'    => [ke_angka($_POST['ph_min'] ?? ''), 0, 14],
            'ph_max'    => [ke_angka($_POST['ph_max'] ?? ''), 0, 14],
            'level_min' => [ke_angka($_POST['level_min'] ?? ''), 0, 100],
            'keruh_max' => [ke_angka($_POST['keruh_max'] ?? ''), 0, 3000],
        ];
        $ok = true;
        foreach ($f as $k => $v) {
            if ($v[0] === null || $v[0] < $v[1] || $v[0] > $v[2]) {
                $ok = false;
            }
        }
        if ($ok && ($f['suhu_min'][0] >= $f['suhu_max'][0] || $f['ph_min'][0] >= $f['ph_max'][0])) {
            $ok = false;
        }
        if (!$ok) {
            flash('Nilai tidak valid. Pastikan semua diisi angka, dan nilai minimal lebih kecil dari maksimal.', 'err');
        } else {
            foreach ($f as $k => $v) {
                setting_set($k, $v[0]);
            }
            flash('Batas peringatan disimpan.');
        }
    } elseif ($aksi === 'pakan') {
        $jadwal = [];
        $valid = true;
        foreach (explode(',', (string)($_POST['jadwal_pakan'] ?? '')) as $j) {
            $j = trim($j);
            if ($j === '') continue;
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $j, $m)) {
                $valid = false;
                break;
            }
            $jadwal[] = sprintf('%02d:%s', (int)$m[1], $m[2]);
        }
        $jadwal = array_values(array_unique($jadwal));
        sort($jadwal);
        $jeda = ke_angka($_POST['pakan_jeda_menit'] ?? '');
        $maks = ke_angka($_POST['pakan_maks_per_hari'] ?? '');
        if (!$valid || count($jadwal) > 6 || $jeda === null || $jeda < 10 || $jeda > 1440 || $maks === null || $maks < 1 || $maks > 10) {
            flash('Pengaturan pakan tidak valid. Contoh jadwal: 07:00,17:00 (maks. 6 jam). Jeda 10–1440 menit, maks. per hari 1–10.', 'err');
        } else {
            setting_set('jadwal_pakan', implode(',', $jadwal));
            setting_set('pakan_jeda_menit', (int)$jeda);
            setting_set('pakan_maks_per_hari', (int)$maks);
            flash('Pengaturan pakan disimpan.');
        }
    } elseif ($aksi === 'password') {
        $lama = (string)($_POST['lama'] ?? '');
        $baru = (string)($_POST['baru'] ?? '');
        $ulang = (string)($_POST['ulang'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $hash = $st->fetchColumn();
        if (!password_verify($lama, (string)$hash)) {
            flash('Password lama salah.', 'err');
        } elseif (strlen($baru) < 8) {
            flash('Password baru minimal 8 karakter.', 'err');
        } elseif ($baru !== $ulang) {
            flash('Konfirmasi password baru tidak sama.', 'err');
        } elseif ($baru === $lama) {
            flash('Password baru harus berbeda dari yang lama.', 'err');
        } else {
            db()->prepare('UPDATE users SET password_hash = ?, must_change = 0 WHERE id = ?')
                ->execute([password_hash($baru, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('Password berhasil diganti.');
        }
    }
    header('Location: pengaturan.php');
    exit;
}

$skema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'IP-SERVER';
$folder = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$url_api = $skema . '://' . $host . $folder . '/api.php';

halaman_awal('Pengaturan', 'PENGATURAN', 'pengaturan', $user);
?>
    <?php if ($user['must_change']): ?>
    <div class="banner">⚠ Wajib: ganti password awal Anda di bagian "Ganti password" di bawah.</div>
    <?php endif; ?>
    <?php if (cfg('api_key') === 'GANTI-DENGAN-KUNCI-RAHASIA' || strlen((string)cfg('api_key')) < 12): ?>
    <div class="banner">⚠ <code>api_key</code> di <code>config.php</code> belum diganti (atau terlalu pendek). ESP8266 akan ditolak sampai Anda menggantinya dengan kunci acak minimal 12 karakter.</div>
    <?php endif; ?>

    <div class="settings-grid">
      <article class="card">
        <div class="card-head"><div><h3>Batas peringatan air</h3><p>Di luar rentang ini, status berubah dan notifikasi muncul</p></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="ambang">
          <div class="form-grid">
            <label class="f">Suhu minimal (°C)<input class="in" name="suhu_min" inputmode="decimal" value="<?= e(setting('suhu_min')) ?>" required></label>
            <label class="f">Suhu maksimal (°C)<input class="in" name="suhu_max" inputmode="decimal" value="<?= e(setting('suhu_max')) ?>" required></label>
            <label class="f">pH minimal<input class="in" name="ph_min" inputmode="decimal" value="<?= e(setting('ph_min')) ?>" required></label>
            <label class="f">pH maksimal<input class="in" name="ph_max" inputmode="decimal" value="<?= e(setting('ph_max')) ?>" required></label>
            <label class="f">Level air minimal (%)<input class="in" name="level_min" inputmode="decimal" value="<?= e(setting('level_min')) ?>" required></label>
            <label class="f">Kekeruhan maksimal (NTU)<input class="in" name="keruh_max" inputmode="decimal" value="<?= e(setting('keruh_max')) ?>" required></label>
          </div>
          <button class="btn primary" type="submit">Simpan batas</button>
          <p class="help">Angka bawaan adalah patokan umum untuk ikan molly; sesuaikan dengan kondisi ikan dan alat ukur Anda.</p>
        </form>
      </article>

      <article class="card">
        <div class="card-head"><div><h3>Pengaturan pakan</h3><p>Pembatas agar ikan tidak diberi makan berlebihan</p></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="pakan">
          <div class="form-grid">
            <label class="f" style="grid-column:1/-1">Jadwal pakan otomatis (jam:menit, pisahkan koma)<input class="in" name="jadwal_pakan" value="<?= e(setting('jadwal_pakan')) ?>" placeholder="07:00,17:00"></label>
            <label class="f">Jeda minimal pakan (menit)<input class="in" name="pakan_jeda_menit" inputmode="numeric" value="<?= e(setting('pakan_jeda_menit')) ?>" required></label>
            <label class="f">Maks. pakan manual per hari<input class="in" name="pakan_maks_per_hari" inputmode="numeric" value="<?= e(setting('pakan_maks_per_hari')) ?>" required></label>
          </div>
          <button class="btn primary" type="submit">Simpan pakan</button>
          <p class="help">Jadwal hanya berjalan bila tombol "Pakan otomatis" di dashboard aktif.</p>
        </form>
      </article>
    </div>

    <div class="settings-grid">
      <article class="card">
        <div class="card-head"><div><h3>Ganti password</h3><p>Login: <b><?= e($user['username']) ?></b></p></div></div>
        <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="aksi" value="password">
          <div class="form-grid">
            <label class="f">Password lama<input class="in" type="password" name="lama" required autocomplete="current-password"></label>
            <label class="f">Password baru (min. 8)<input class="in" type="password" name="baru" required minlength="8" autocomplete="new-password"></label>
            <label class="f">Ulangi password baru<input class="in" type="password" name="ulang" required minlength="8" autocomplete="new-password"></label>
          </div>
          <button class="btn primary" type="submit">Ganti password</button>
        </form>
      </article>

      <article class="card">
        <div class="card-head"><div><h3>Hubungkan ESP8266</h3><p>Isi data ini di kode Arduino</p></div></div>
        <p class="help" style="margin-top:0">Alamat server (SERVER_URL):</p>
        <p><code><?= e($url_api) ?></code></p>
        <p class="help">Jika ESP8266 tidak bisa membuka alamat di atas, ganti bagian <code>localhost</code> / nama host dengan <b>alamat IP komputer server</b> di jaringan WiFi Anda (cek dengan <code>ipconfig</code>).</p>
        <p class="help">API key ada di <code>config.php</code> (baris <code>api_key</code>) dan harus sama persis dengan <code>API_KEY</code> di kode ESP8266.</p>
        <p class="help">Status: ESP8266 <b><?= esp_online() ? 'online' : 'offline' ?></b> · data terakhir <?= e(lama_lalu((int)state_get('last_seen', 0))) ?></p>
      </article>
    </div>
<?php halaman_akhir(); ?>
