<?php
require __DIR__ . '/../inc/layout.php';

if (!empty($_SESSION['penghuni_id'])) redirect('penghuni/index.php');

$error = '';
if (is_post()) {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $kode = (string)($_POST['kode_akses'] ?? '');

    $st = db()->prepare('SELECT * FROM penghuni WHERE email = ?');
    $st->execute([$email]);
    foreach ($st->fetchAll() as $p) {
        if (check_secret($kode, $p['kode_akses'])) {
            if (!is_hashed($p['kode_akses'])) {
                db()->prepare('UPDATE penghuni SET kode_akses=? WHERE id_penghuni=?')
                    ->execute([password_hash($kode, PASSWORD_DEFAULT), $p['id_penghuni']]);
            }
            session_regenerate_id(true);
            $_SESSION['penghuni_id'] = (int)$p['id_penghuni'];
            $_SESSION['penghuni_nama'] = $p['nama_penghuni'];
            redirect('penghuni/index.php');
        }
    }
    $error = 'Email atau kode akses salah.';
    usleep(400000);
}

page_header('Login Penghuni', 'auth');
?>
<div class="card">
  <a class="brand" href="<?= url('index.php') ?>"><?= logo_html() ?><?= e(setting('nama_kost', SITE_NAME)) ?></a>
  <h1>Login Penghuni</h1>
  <p class="sub">Gunakan email saat booking dan kode akses dari pengelola.</p>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <form class="f" method="post">
    <?= csrf_field() ?>
    <div><label>Email</label><input type="email" name="email" required autofocus></div>
    <div><label>Kode Akses</label><input type="password" name="kode_akses" required></div>
    <button class="btn lg block" type="submit">Masuk</button>
  </form>
  <a class="back" href="<?= url('index.php') ?>">← Kembali ke beranda</a>
</div>
<?php page_footer(); ?>
