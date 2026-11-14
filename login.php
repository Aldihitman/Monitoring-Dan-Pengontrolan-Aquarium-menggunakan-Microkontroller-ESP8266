<?php
require __DIR__ . '/db.php';
mulai_sesi();
kirim_header_aman();

if (user_sekarang()) {
    header('Location: index.php');
    exit;
}

$error = '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_periksa();
    $pdo = db();
    $batas = time() - cfg('login_blokir_menit') * 60;
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([$batas]);

    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ts >= ?');
    $st->execute([$ip, $batas]);
    $gagal = (int)$st->fetchColumn();

    if ($gagal >= cfg('login_maks_gagal')) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . cfg('login_blokir_menit') . ' menit.';
    } else {
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

        $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();

        // Jika user tidak ada, tetap verifikasi hash palsu agar waktu responsnya sama
        $hash = $u ? $u['password_hash'] : password_hash('dummy', PASSWORD_DEFAULT);
        $cocok = password_verify($password, $hash);

        if ($u && $cocok) {
            $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['aktif'] = time();
            header('Location: index.php');
            exit;
        }
        $pdo->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
        $error = 'Username atau password salah.';
    }
}
$flash = ambil_flash();
if ($flash && !$error) {
    $error = $flash['pesan'];
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= e(cfg('nama_sistem')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="off">
    <div class="brand">
      <div class="logo">🐠</div>
      <div><strong><?= e(cfg('nama_sistem')) ?></strong><small><?= e(cfg('tagline')) ?></small></div>
    </div>
    <?php if ($error): ?><div class="err" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label class="f">Username
      <input class="in" type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label class="f">Password
      <input class="in" type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn primary" type="submit">Masuk</button>
  </form>
</div>
</body>
</html>
