<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();
refresh_terlambat();

if (is_post()) {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'buat') {
        $idSewa = (int)($_POST['id_sewa'] ?? 0);
        $periode = trim($_POST['periode'] ?? '');
        $jumlah = (float)($_POST['jumlah'] ?? 0);
        $tempo = trim($_POST['jatuh_tempo'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $tempo);
        if (!$idSewa || !preg_match('/^\d{4}-\d{2}$/', $periode) || $jumlah <= 0 || !$d || $d->format('Y-m-d') !== $tempo) {
            flash('err', 'Data tagihan tidak valid.');
        } else {
            $cek = $pdo->prepare('SELECT 1 FROM tagihan WHERE id_sewa=? AND periode_tagihan=?');
            $cek->execute([$idSewa, $periode]);
            if ($cek->fetch()) {
                flash('err', 'Tagihan periode ' . $periode . ' untuk sewa ini sudah ada.');
            } else {
                $pdo->prepare("INSERT INTO tagihan (id_sewa, periode_tagihan, jumlah_tagihan, tanggal_jatuh_tempo, status_tagihan) VALUES (?,?,?,?, 'belum bayar')")
                    ->execute([$idSewa, $periode, $jumlah, $tempo]);
                flash('ok', 'Tagihan dibuat.');
            }
        }
    } elseif ($aksi === 'tunai') {
        $id = (int)($_POST['id_tagihan'] ?? 0);
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare("SELECT * FROM tagihan WHERE id_tagihan=? AND status_tagihan IN ('belum bayar','terlambat') FOR UPDATE");
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) throw new RuntimeException('Tagihan tidak ditemukan atau sudah dibayar / menunggu verifikasi.');
            $pdo->prepare("INSERT INTO pembayaran (id_tagihan, jumlah_bayar, tanggal_bayar, metode_pembayaran, bukti_pembayaran, status_pembayaran)
                           VALUES (?,?,NOW(),'tunai','-','diterima')")->execute([$id, $t['jumlah_tagihan']]);
            $pdo->prepare("UPDATE tagihan SET status_tagihan='lunas' WHERE id_tagihan=?")->execute([$id]);
            $pdo->commit();
            flash('ok', 'Pembayaran tunai dicatat, tagihan lunas.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', $e->getMessage());
        }
    }
    redirect('admin/tagihan.php');
}

$filter = $_GET['status'] ?? '';
$valid = ['belum bayar', 'menunggu verifikasi', 'lunas', 'terlambat'];
$sql = "SELECT t.*, p.nama_penghuni, k.nomer_kamar FROM tagihan t
        JOIN sewa s ON s.id_sewa=t.id_sewa JOIN penghuni p ON p.id_penghuni=s.id_penghuni JOIN kamar k ON k.id_kamar=s.id_kamar";
$args = [];
if (in_array($filter, $valid, true)) {
    $sql .= ' WHERE t.status_tagihan = ?';
    $args[] = $filter;
}
$st = $pdo->prepare($sql . ' ORDER BY t.tanggal_jatuh_tempo DESC, t.id_tagihan DESC');
$st->execute($args);
$rows = $st->fetchAll();

$sewaAktif = $pdo->query("SELECT s.id_sewa, s.harga_sewa, p.nama_penghuni, k.nomer_kamar FROM sewa s
        JOIN penghuni p ON p.id_penghuni=s.id_penghuni JOIN kamar k ON k.id_kamar=s.id_kamar
        WHERE s.status_sewa='aktif' ORDER BY p.nama_penghuni")->fetchAll();

page_header('Tagihan', 'admin');
?>
<h1 class="title">Tagihan</h1>
<p class="sub">Buat tagihan bulanan dan catat pembayaran tunai. Tagihan lewat jatuh tempo otomatis berstatus terlambat.</p>

<div class="card form-card" style="margin-bottom:24px">
  <h3>Buat Tagihan Baru</h3>
  <?php if (!$sewaAktif): ?>
    <p class="sub" style="margin:8px 0 0">Belum ada sewa aktif.</p>
  <?php else: ?>
  <form class="f" method="post" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="buat">
    <div><label>Sewa / Penghuni</label>
      <select name="id_sewa" required>
        <?php foreach ($sewaAktif as $s): ?>
          <option value="<?= (int)$s['id_sewa'] ?>" data-harga="<?= (int)$s['harga_sewa'] ?>">
            <?= e($s['nama_penghuni']) ?> — Kamar <?= e($s['nomer_kamar']) ?> (<?= rupiah($s['harga_sewa']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <div><label>Periode (bulan)</label><input type="month" name="periode" value="<?= date('Y-m') ?>" required></div>
      <div><label>Jatuh Tempo</label><input type="date" name="jatuh_tempo" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required></div>
    </div>
    <div><label>Jumlah (Rp)</label><input type="number" name="jumlah" id="jumlah" min="1" step="1" value="<?= (int)$sewaAktif[0]['harga_sewa'] ?>" required></div>
    <div><button class="btn" type="submit">Buat Tagihan</button></div>
  </form>
  <script>
    const sel = document.querySelector('select[name=id_sewa]');
    sel.addEventListener('change', () => document.getElementById('jumlah').value = sel.selectedOptions[0].dataset.harga);
  </script>
  <?php endif; ?>
</div>

<div class="filters">
  <a href="?" class="<?= $filter === '' ? 'on' : '' ?>">Semua</a>
  <?php foreach ($valid as $v): ?>
    <a href="?status=<?= urlencode($v) ?>" class="<?= $filter === $v ? 'on' : '' ?>"><?= e(status_label($v)) ?></a>
  <?php endforeach; ?>
</div>

<div class="tablewrap"><table>
  <tr><th>Penghuni</th><th>Kamar</th><th>Periode</th><th>Jumlah</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><b><?= e($r['nama_penghuni']) ?></b></td><td><?= e($r['nomer_kamar']) ?></td><td><?= e($r['periode_tagihan']) ?></td>
      <td><?= rupiah($r['jumlah_tagihan']) ?></td><td><?= tgl($r['tanggal_jatuh_tempo']) ?></td><td><?= badge($r['status_tagihan']) ?></td>
      <td>
        <?php if (in_array($r['status_tagihan'], ['belum bayar', 'terlambat'], true)): ?>
          <form method="post" onsubmit="return confirm('Catat pembayaran tunai sebesar <?= rupiah($r['jumlah_tagihan']) ?>?')">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="tunai"><input type="hidden" name="id_tagihan" value="<?= (int)$r['id_tagihan'] ?>">
            <button class="btn sm ghost">Bayar Tunai</button>
          </form>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Tidak ada tagihan.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
