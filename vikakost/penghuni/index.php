<?php
require __DIR__ . '/../inc/layout.php';
require_penghuni();
refresh_terlambat();

$pdo = db();
$idp = (int)$_SESSION['penghuni_id'];

if (is_post() && ($_POST['aksi'] ?? '') === 'bayar') {
    csrf_check();
    $idt = (int)($_POST['id_tagihan'] ?? 0);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT t.* FROM tagihan t JOIN sewa s ON s.id_sewa=t.id_sewa
                             WHERE t.id_tagihan=? AND s.id_penghuni=? AND t.status_tagihan IN ('belum bayar','terlambat') FOR UPDATE");
        $st->execute([$idt, $idp]);
        $t = $st->fetch();
        if (!$t) throw new RuntimeException('Tagihan tidak ditemukan atau sudah dibayar.');

        $file = simpan_bukti($_FILES['bukti'] ?? []);
        $pdo->prepare("INSERT INTO pembayaran (id_tagihan, jumlah_bayar, tanggal_bayar, metode_pembayaran, bukti_pembayaran, status_pembayaran)
                       VALUES (?,?,NOW(),'transfer',?, 'menunggu')")->execute([$idt, $t['jumlah_tagihan'], $file]);
        $pdo->prepare("UPDATE tagihan SET status_tagihan='menunggu verifikasi' WHERE id_tagihan=?")->execute([$idt]);
        $pdo->commit();
        flash('ok', 'Bukti pembayaran terkirim. Menunggu verifikasi admin.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Terjadi kesalahan saat menyimpan pembayaran.');
    }
    redirect('penghuni/index.php');
}

$st = $pdo->prepare('SELECT * FROM penghuni WHERE id_penghuni = ?');
$st->execute([$idp]);
$me = $st->fetch();
if (!$me) redirect('logout.php');

$st = $pdo->prepare("SELECT s.*, k.nomer_kamar, k.fasilitas FROM sewa s JOIN kamar k ON k.id_kamar=s.id_kamar
                     WHERE s.id_penghuni=? ORDER BY s.status_sewa='aktif' DESC, s.id_sewa DESC");
$st->execute([$idp]);
$sewa = $st->fetchAll();

$st = $pdo->prepare("SELECT t.*, k.nomer_kamar FROM tagihan t JOIN sewa s ON s.id_sewa=t.id_sewa JOIN kamar k ON k.id_kamar=s.id_kamar
                     WHERE s.id_penghuni=? ORDER BY t.tanggal_jatuh_tempo DESC, t.id_tagihan DESC");
$st->execute([$idp]);
$tagihan = $st->fetchAll();

$st = $pdo->prepare("SELECT pb.*, t.periode_tagihan FROM pembayaran pb JOIN tagihan t ON t.id_tagihan=pb.id_tagihan
                     JOIN sewa s ON s.id_sewa=t.id_sewa WHERE s.id_penghuni=? ORDER BY pb.tanggal_bayar DESC, pb.id_pembayaran DESC");
$st->execute([$idp]);
$bayar = $st->fetchAll();

page_header('Portal Penghuni', 'penghuni');
?>
<h1 class="title">Halo, <?= e($me['nama_penghuni']) ?> 👋</h1>
<p class="sub">Lihat sewa, tagihan, dan upload bukti pembayaran di sini.</p>

<h2 class="sec">Sewa Saya</h2>
<div class="grid">
  <?php foreach ($sewa as $s): ?>
    <div class="card">
      <div class="room-head"><h3>Kamar <?= e($s['nomer_kamar']) ?></h3><?= badge($s['status_sewa']) ?></div>
      <div class="price"><?= rupiah($s['harga_sewa']) ?> <small>/ bulan</small></div>
      <p style="color:var(--muted);margin:0 0 6px"><?= e($s['fasilitas']) ?></p>
      <small>Masa sewa: <?= tgl($s['tanggal_mulai']) ?> – <?= tgl($s['tanggal_selesai']) ?></small>
    </div>
  <?php endforeach; ?>
  <?php if (!$sewa): ?><div class="card empty">Belum ada data sewa.</div><?php endif; ?>
</div>

<h2 class="sec">Tagihan</h2>
<?php if (setting('no_rekening') !== ''): ?>
  <div class="alert info">Transfer pembayaran ke <b><?= e(setting('bank', 'Bank')) ?> <?= e(setting('no_rekening')) ?></b><?= setting('atas_nama') !== '' ? ' a.n. ' . e(setting('atas_nama')) : '' ?>, lalu upload bukti transfer di bawah.</div>
<?php endif; ?>
<div class="tablewrap"><table>
  <tr><th>Periode</th><th>Kamar</th><th>Jumlah</th><th>Jatuh Tempo</th><th>Status</th><th>Bayar (transfer)</th></tr>
  <?php foreach ($tagihan as $t): ?>
    <tr>
      <td><?= e($t['periode_tagihan']) ?></td><td><?= e($t['nomer_kamar']) ?></td><td><b><?= rupiah($t['jumlah_tagihan']) ?></b></td>
      <td><?= tgl($t['tanggal_jatuh_tempo']) ?></td><td><?= badge($t['status_tagihan']) ?></td>
      <td>
        <?php if (in_array($t['status_tagihan'], ['belum bayar', 'terlambat'], true)): ?>
          <form method="post" enctype="multipart/form-data" class="actions">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="bayar"><input type="hidden" name="id_tagihan" value="<?= (int)$t['id_tagihan'] ?>">
            <input type="file" name="bukti" accept="image/jpeg,image/png,application/pdf" required style="width:200px">
            <button class="btn sm">Kirim Bukti</button>
          </form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$tagihan): ?><tr><td colspan="6" class="empty">Belum ada tagihan.</td></tr><?php endif; ?>
</table></div>

<h2 class="sec">Riwayat Pembayaran</h2>
<div class="tablewrap"><table>
  <tr><th>Tanggal</th><th>Periode</th><th>Jumlah</th><th>Metode</th><th>Status</th></tr>
  <?php foreach ($bayar as $b): ?>
    <tr><td><?= tgl($b['tanggal_bayar']) ?></td><td><?= e($b['periode_tagihan']) ?></td><td><?= rupiah($b['jumlah_bayar']) ?></td>
        <td><?= ucfirst(e($b['metode_pembayaran'])) ?></td><td><?= badge($b['status_pembayaran']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$bayar): ?><tr><td colspan="5" class="empty">Belum ada pembayaran.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
