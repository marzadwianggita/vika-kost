<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

if (is_post()) {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id_pembayaran'] ?? 0);

    if (in_array($aksi, ['terima', 'tolak'], true)) {
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM pembayaran WHERE id_pembayaran=? AND status_pembayaran='menunggu' FOR UPDATE");
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) throw new RuntimeException('Pembayaran sudah diproses sebelumnya.');

            if ($aksi === 'terima') {
                $pdo->prepare("UPDATE pembayaran SET status_pembayaran='diterima' WHERE id_pembayaran=?")->execute([$id]);
                $pdo->prepare("UPDATE tagihan SET status_tagihan='lunas' WHERE id_tagihan=?")->execute([$p['id_tagihan']]);
                flash('ok', 'Pembayaran diterima, tagihan lunas.');
            } else {
                $pdo->prepare("UPDATE pembayaran SET status_pembayaran='ditolak' WHERE id_pembayaran=?")->execute([$id]);
                // Kembalikan tagihan agar penghuni bisa upload ulang
                $pdo->prepare("UPDATE tagihan SET status_tagihan = IF(tanggal_jatuh_tempo < CURDATE(), 'terlambat', 'belum bayar') WHERE id_tagihan=?")
                    ->execute([$p['id_tagihan']]);
                flash('ok', 'Pembayaran ditolak, tagihan dibuka kembali.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', $e->getMessage());
        }
    }
    redirect('admin/pembayaran.php');
}

$filter = $_GET['status'] ?? '';
$valid = ['menunggu', 'diterima', 'ditolak'];
$sql = "SELECT pb.*, t.periode_tagihan, p.nama_penghuni, k.nomer_kamar FROM pembayaran pb
        JOIN tagihan t ON t.id_tagihan=pb.id_tagihan JOIN sewa s ON s.id_sewa=t.id_sewa
        JOIN penghuni p ON p.id_penghuni=s.id_penghuni JOIN kamar k ON k.id_kamar=s.id_kamar";
$args = [];
if (in_array($filter, $valid, true)) {
    $sql .= ' WHERE pb.status_pembayaran = ?';
    $args[] = $filter;
}
$st = $pdo->prepare($sql . ' ORDER BY pb.status_pembayaran=\'menunggu\' DESC, pb.tanggal_bayar DESC, pb.id_pembayaran DESC');
$st->execute($args);
$rows = $st->fetchAll();

page_header('Pembayaran', 'admin');
?>
<h1 class="title">Verifikasi Pembayaran</h1>
<p class="sub">Cek bukti transfer dari penghuni, lalu terima atau tolak.</p>

<div class="filters">
  <a href="?" class="<?= $filter === '' ? 'on' : '' ?>">Semua</a>
  <?php foreach ($valid as $v): ?>
    <a href="?status=<?= $v ?>" class="<?= $filter === $v ? 'on' : '' ?>"><?= ucfirst($v) ?></a>
  <?php endforeach; ?>
</div>

<div class="tablewrap"><table>
  <tr><th>Penghuni</th><th>Kamar</th><th>Periode</th><th>Jumlah</th><th>Tanggal</th><th>Metode</th><th>Bukti</th><th>Status</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <?php $bukti = basename($r['bukti_pembayaran']); $ada = $bukti !== '' && is_file(__DIR__ . '/../uploads/' . $bukti); ?>
    <tr>
      <td><b><?= e($r['nama_penghuni']) ?></b></td><td><?= e($r['nomer_kamar']) ?></td><td><?= e($r['periode_tagihan']) ?></td>
      <td><?= rupiah($r['jumlah_bayar']) ?></td><td><?= tgl($r['tanggal_bayar']) ?></td><td><?= ucfirst(e($r['metode_pembayaran'])) ?></td>
      <td><?= $ada ? '<a target="_blank" rel="noopener" href="' . url('uploads/' . rawurlencode($bukti)) . '">Lihat</a>' : e($r['bukti_pembayaran']) ?></td>
      <td><?= badge($r['status_pembayaran']) ?></td>
      <td class="actions">
        <?php if ($r['status_pembayaran'] === 'menunggu'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id_pembayaran" value="<?= (int)$r['id_pembayaran'] ?>">
            <button class="btn sm" name="aksi" value="terima">Terima</button></form>
          <form method="post" onsubmit="return confirm('Tolak pembayaran ini?')"><?= csrf_field() ?><input type="hidden" name="id_pembayaran" value="<?= (int)$r['id_pembayaran'] ?>">
            <button class="btn sm danger" name="aksi" value="tolak">Tolak</button></form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">Tidak ada pembayaran.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
