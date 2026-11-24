<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

if (is_post()) {
    csrf_check();
    if (($_POST['aksi'] ?? '') === 'reset') {
        $id = (int)($_POST['id_penghuni'] ?? 0);
        $kode = (string)random_int(100000, 999999);
        $st = $pdo->prepare('UPDATE penghuni SET kode_akses = ? WHERE id_penghuni = ?');
        $st->execute([password_hash($kode, PASSWORD_DEFAULT), $id]);
        flash('ok', 'Kode akses baru: ' . $kode . ' — berikan ke penghuni, kode ini tidak ditampilkan lagi.');
    }
    redirect('admin/penghuni.php');
}

$rows = $pdo->query("SELECT p.*, b.kode_booking,
        (SELECT k.nomer_kamar FROM sewa s JOIN kamar k ON k.id_kamar=s.id_kamar
          WHERE s.id_penghuni=p.id_penghuni ORDER BY s.status_sewa='aktif' DESC, s.id_sewa DESC LIMIT 1) AS kamar
        FROM penghuni p JOIN booking b ON b.id_booking=p.id_booking
        ORDER BY p.tanggal_daftar DESC, p.id_penghuni DESC")->fetchAll();

page_header('Penghuni', 'admin');
?>
<h1 class="title">Data Penghuni</h1>
<p class="sub">Penghuni dibuat otomatis saat booking diterima. Login portal: email + kode akses.</p>

<div class="tablewrap"><table>
  <tr><th>Nama</th><th>No. HP</th><th>Email</th><th>Kamar</th><th>Kode Booking</th><th>Terdaftar</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><b><?= e($r['nama_penghuni']) ?></b></td><td><?= e($r['no_hp']) ?></td><td><?= e($r['email']) ?></td>
      <td><?= e($r['kamar'] ?? '-') ?></td><td><?= e($r['kode_booking']) ?></td><td><?= tgl($r['tanggal_daftar']) ?></td>
      <td>
        <form method="post" onsubmit="return confirm('Buat kode akses baru untuk <?= e($r['nama_penghuni']) ?>?')">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id_penghuni" value="<?= (int)$r['id_penghuni'] ?>">
          <button class="btn sm ghost">Reset Kode Akses</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada penghuni.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
