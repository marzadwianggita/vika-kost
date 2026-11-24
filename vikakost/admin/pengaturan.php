<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

// Pastikan tabel pengaturan ada (kalau migrasi.sql belum dijalankan)
$ada = true;
try {
    $pdo->query('SELECT 1 FROM pengaturan LIMIT 1');
} catch (Throwable $e) {
    $ada = false;
}

$keys = ['nama_kost', 'deskripsi', 'alamat', 'whatsapp', 'email', 'bank', 'no_rekening', 'atas_nama'];

if (is_post()) {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'profil' && $ada) {
        $stmt = $pdo->prepare('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)');
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($k === 'whatsapp') $v = preg_replace('/\D+/', '', $v);
            if ($k === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                flash('err', 'Format email tidak valid.');
                redirect('admin/pengaturan.php');
            }
            $stmt->execute([$k, mb_substr($v, 0, 1000)]);
        }

        // Logo: unggah baru atau hapus
        $hapusLogo = function () use ($pdo, $stmt) {
            $lama = basename(setting('logo'));
            if ($lama !== '' && str_starts_with($lama, 'logo_') && is_file(__DIR__ . '/../uploads/' . $lama)) {
                @unlink(__DIR__ . '/../uploads/' . $lama);
            }
        };
        if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $baru = simpan_foto($_FILES['logo'], 'logo_');
                $hapusLogo();
                $stmt->execute(['logo', $baru]);
            } catch (RuntimeException $e) {
                flash('err', 'Logo: ' . $e->getMessage());
                redirect('admin/pengaturan.php');
            }
        } elseif (!empty($_POST['hapus_logo'])) {
            $hapusLogo();
            $stmt->execute(['logo', '']);
        }
        flash('ok', 'Pengaturan disimpan.');
    } elseif ($aksi === 'password') {
        $lama = (string)($_POST['lama'] ?? '');
        $baru = (string)($_POST['baru'] ?? '');
        $ulang = (string)($_POST['ulang'] ?? '');
        $st = $pdo->prepare('SELECT * FROM akun WHERE id_akun=?');
        $st->execute([(int)$_SESSION['admin_id']]);
        $akun = $st->fetch();
        if (!$akun || !check_secret($lama, $akun['password'])) {
            flash('err', 'Password lama salah.');
        } elseif (strlen($baru) < 6) {
            flash('err', 'Password baru minimal 6 karakter.');
        } elseif ($baru !== $ulang) {
            flash('err', 'Konfirmasi password tidak sama.');
        } else {
            $pdo->prepare('UPDATE akun SET password=? WHERE id_akun=?')->execute([password_hash($baru, PASSWORD_DEFAULT), $akun['id_akun']]);
            flash('ok', 'Password berhasil diganti.');
        }
    }
    redirect('admin/pengaturan.php');
}

$def = ['nama_kost' => SITE_NAME, 'deskripsi' => 'Kost nyaman, bersih, dan harga bersahabat. Booking online mudah dan cepat.'];

page_header('Pengaturan', 'admin');
?>
<div class="toolbar"><div><h1 class="title">Pengaturan</h1><p class="sub" style="margin:0">Profil kost, informasi pembayaran, dan keamanan akun.</p></div></div>

<?php if (!$ada): ?>
  <div class="alert info">Tabel pengaturan belum ada. Jalankan <b>migrasi.sql</b> di phpMyAdmin (database <b>vikakostdb</b>) agar profil kost bisa disimpan.</div>
<?php endif; ?>

<div class="dash" style="grid-template-columns:1.4fr 1fr;align-items:start">
  <div class="card">
    <h3>Profil &amp; Pembayaran</h3>
    <form class="f" method="post" enctype="multipart/form-data" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="profil">
      <div><label>Logo</label>
        <?php if (logo_html() !== ''): ?>
          <div style="margin-bottom:8px"><?= logo_html() ?></div>
          <label class="check"><input type="checkbox" name="hapus_logo" value="1"> Hapus logo (tampilkan nama saja)</label>
        <?php endif; ?>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
        <div class="hint">JPG / PNG / WEBP, maks 3 MB. Disarankan PNG berlatar transparan.</div>
      </div>
      <div><label>Nama Kost</label><input name="nama_kost" value="<?= e(setting('nama_kost', $def['nama_kost'])) ?>" required maxlength="60"></div>
      <div><label>Deskripsi Singkat</label><textarea name="deskripsi" rows="2" maxlength="300"><?= e(setting('deskripsi', $def['deskripsi'])) ?></textarea></div>
      <div><label>Alamat</label><textarea name="alamat" rows="2"><?= e(setting('alamat')) ?></textarea></div>
      <div class="row">
        <div><label>WhatsApp (format 62…)</label><input name="whatsapp" value="<?= e(setting('whatsapp')) ?>" placeholder="6281234567890" inputmode="numeric"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= e(setting('email')) ?>"></div>
      </div>
      <div class="row">
        <div><label>Bank</label><input name="bank" value="<?= e(setting('bank')) ?>" placeholder="BCA / BRI / Mandiri"></div>
        <div><label>No. Rekening</label><input name="no_rekening" value="<?= e(setting('no_rekening')) ?>" inputmode="numeric"></div>
      </div>
      <div><label>Atas Nama</label><input name="atas_nama" value="<?= e(setting('atas_nama')) ?>"></div>
      <div><button class="btn" type="submit" <?= $ada ? '' : 'disabled' ?>>Simpan Pengaturan</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Ganti Password</h3>
    <form class="f" method="post" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="password">
      <div><label>Password Lama</label><input type="password" name="lama" required autocomplete="current-password"></div>
      <div><label>Password Baru</label><input type="password" name="baru" required minlength="6" autocomplete="new-password"></div>
      <div><label>Ulangi Password Baru</label><input type="password" name="ulang" required minlength="6" autocomplete="new-password"></div>
      <div><button class="btn" type="submit">Ganti Password</button></div>
    </form>
  </div>
</div>
<?php page_footer(); ?>
