<?php
require __DIR__ . '/inc/layout.php';

if (!empty($_SESSION['admin_id'])) redirect('admin/index.php');

$error = '';
if (is_post()) {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');

    $st = db()->prepare('SELECT * FROM akun WHERE username = ?');
    $st->execute([$u]);
    $akun = $st->fetch();

    if ($akun && check_secret($p, $akun['password'])) {
        // Upgrade password lama (plaintext) menjadi hash secara otomatis
        if (!is_hashed($akun['password'])) {
            db()->prepare('UPDATE akun SET password = ? WHERE id_akun = ?')
                ->execute([password_hash($p, PASSWORD_DEFAULT), $akun['id_akun']]);
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$akun['id_akun'];
        $_SESSION['admin_nama'] = $akun['nama'];
        redirect('admin/index.php');
    }
    $error = 'Username atau password salah.';
    usleep(400000);
}

page_header('Login Admin', 'auth');
?>
<div class="card">
  <a class="brand" href="<?= url('index.php') ?>"><?= logo_html() ?><?= e(setting('nama_kost', SITE_NAME)) ?></a>
  <h1>Login Admin</h1>
  <p class="sub">Masuk untuk mengelola kost.</p>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <form class="f" method="post">
    <?= csrf_field() ?>
    <div><label>Username</label><input name="username" required autofocus autocomplete="username"></div>
    <div><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn lg block" type="submit">Masuk</button>
  </form>
  <a class="back" href="<?= url('index.php') ?>">← Kembali ke beranda</a>
</div>
<?php page_footer(); ?>
