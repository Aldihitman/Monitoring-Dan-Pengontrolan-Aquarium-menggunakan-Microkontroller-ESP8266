<?php
/* Kerangka halaman bersama (sidebar + topbar). Dipakai index.php & pengaturan.php */

function halaman_awal($judul, $eyebrow, $aktif, $user, $refresh = 0)
{
    $online = esp_online();
    $last = (int)state_get('last_seen', 0);
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh > 0): ?><meta http-equiv="refresh" content="<?= (int)$refresh ?>"><?php endif; ?>
<title><?= e($judul) ?> — <?= e(cfg('nama_sistem')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div class="logo">🐠</div><div><strong><?= e(cfg('nama_sistem')) ?></strong><small><?= e(cfg('tagline')) ?></small></div></div>
    <div><div class="nav-label">MENU UTAMA</div><nav class="nav">
      <a href="index.php#dashboard" class="<?= $aktif === 'dashboard' ? 'active' : '' ?>"><span class="ico">▦</span>Dashboard</a>
      <a href="index.php#monitoring"><span class="ico">◉</span>Monitoring</a>
      <a href="index.php#kontrol"><span class="ico">⌁</span>Kontrol Perangkat</a>
      <a href="index.php#notifikasi"><span class="ico">♧</span>Notifikasi</a>
      <a href="pengaturan.php" class="<?= $aktif === 'pengaturan' ? 'active' : '' ?>"><span class="ico">⚙</span>Pengaturan</a>
    </nav></div>
    <div class="side-bottom">
      <div><span class="dot" style="<?= $online ? '' : 'background:#fb7185;box-shadow:0 0 12px #fb718588' ?>"></span><b style="font-size:12px">ESP8266 <?= $online ? 'online' : 'offline' ?></b></div>
      <small>Data terakhir: <?= e(lama_lalu($last)) ?></small>
      <div class="side-user">👤 <?= e($user['username']) ?></div>
    </div>
  </aside>
  <main id="dashboard">
    <header class="topbar">
      <div><div class="eyebrow"><?= e(strtoupper(cfg('nama_sistem'))) ?> / <?= e($eyebrow) ?></div><h1><?= e($judul) ?></h1></div>
      <div class="top-actions">
        <span class="pill"><span class="dot" style="<?= $online ? '' : 'background:#fb7185;box-shadow:none' ?>"></span><?= $online ? 'Perangkat online' : 'Perangkat offline' ?></span>
        <a class="btn" href="<?= e(basename($_SERVER['SCRIPT_NAME'])) ?>">↻ Perbarui</a>
        <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn danger" type="submit">Keluar</button></form>
      </div>
    </header>
<?php
    $f = ambil_flash();
    if ($f) {
        echo '<div class="toast show flash ' . ($f['tipe'] === 'err' ? 'err' : '') . '" role="status">' . e($f['pesan']) . '</div>';
    }
}

function halaman_akhir()
{
    ?>
    <div class="footer"><?= e(cfg('nama_sistem')) ?> · <?= e(cfg('nama_akuarium')) ?></div>
  </main>
</div>
</body>
</html>
<?php
}
