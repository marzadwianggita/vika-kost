<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

if (is_post()) {
    csrf_check();
    if (($_POST['aksi'] ?? '') === 'selesai') {
        $id = (int)($_POST['id_sewa'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT id_kamar FROM sewa WHERE id_sewa=? AND status_sewa='aktif' FOR UPDATE");
            $st->execute([$id]);
            $s = $st->fetch();
            if (!$s) throw new RuntimeException('Sewa tidak ditemukan / sudah selesai.');
            $pdo->prepare("UPDATE sewa SET status_sewa='selesai' WHERE id_sewa=?")->execute([$id]);
            $pdo->prepare("UPDATE kamar SET status_kamar='tersedia' WHERE id_kamar=?")->execute([$s['id_kamar']]);
            $pdo->commit();
            flash('ok', 'Sewa diselesaikan, kamar kembali tersedia.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', $e->getMessage());
        }
    }
    redirect('admin/sewa.php');
}

$rows = $pdo->query("SELECT s.*, p.nama_penghuni, k.nomer_kamar FROM sewa s
        JOIN penghuni p ON p.id_penghuni=s.id_penghuni JOIN kamar k ON k.id_kamar=s.id_kamar
        ORDER BY s.status_sewa='aktif' DESC, s.id_sewa DESC")->fetchAll();

page_header('Sewa', 'admin');
?>
<h1 class="title">Data Sewa</h1>
<p class="sub">Menyelesaikan sewa otomatis mengembalikan kamar menjadi tersedia.</p>

<div class="tablewrap"><table>
  <tr><th>Penghuni</th><th>Kamar</th><th>Mulai</th><th>Selesai</th><th>Harga/bln</th><th>Status</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><b><?= e($r['nama_penghuni']) ?></b></td><td><?= e($r['nomer_kamar']) ?></td>
      <td><?= tgl($r['tanggal_mulai']) ?></td><td><?= tgl($r['tanggal_selesai']) ?></td>
      <td><?= rupiah($r['harga_sewa']) ?></td><td><?= badge($r['status_sewa']) ?></td>
      <td>
        <?php if ($r['status_sewa'] === 'aktif'): ?>
        <form method="post" onsubmit="return confirm('Selesaikan sewa ini?')">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="selesai"><input type="hidden" name="id_sewa" value="<?= (int)$r['id_sewa'] ?>">
          <button class="btn sm ghost">Selesaikan</button>
        </form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Belum ada sewa.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
