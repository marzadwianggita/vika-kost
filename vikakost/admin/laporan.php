<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

$tahun = (int)($_GET['tahun'] ?? date('Y'));
if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y');
$bulan = (int)($_GET['bulan'] ?? 0); // 0 = setahun penuh
if ($bulan < 0 || $bulan > 12) $bulan = 0;

// Rincian pembayaran diterima
$sql = "SELECT pb.*, t.periode_tagihan, p.nama_penghuni, k.nomer_kamar FROM pembayaran pb
        JOIN tagihan t ON t.id_tagihan=pb.id_tagihan JOIN sewa s ON s.id_sewa=t.id_sewa
        JOIN penghuni p ON p.id_penghuni=s.id_penghuni JOIN kamar k ON k.id_kamar=s.id_kamar
        WHERE pb.status_pembayaran='diterima' AND YEAR(pb.tanggal_bayar)=?";
$args = [$tahun];
if ($bulan) {
    $sql .= ' AND MONTH(pb.tanggal_bayar)=?';
    $args[] = $bulan;
}
$st = $pdo->prepare($sql . ' ORDER BY pb.tanggal_bayar DESC, pb.id_pembayaran DESC');
$st->execute($args);
$rows = $st->fetchAll();

// Ekspor CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-pendapatan-' . $tahun . ($bulan ? '-' . str_pad((string)$bulan, 2, '0', STR_PAD_LEFT) : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tanggal', 'Penghuni', 'Kamar', 'Periode', 'Metode', 'Jumlah']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['tanggal_bayar'], $r['nama_penghuni'], $r['nomer_kamar'], $r['periode_tagihan'], $r['metode_pembayaran'], $r['jumlah_bayar']]);
    }
    exit;
}

// Total per bulan (setahun)
$perBulan = array_fill(1, 12, 0.0);
$st = $pdo->prepare("SELECT MONTH(tanggal_bayar) m, SUM(jumlah_bayar) total FROM pembayaran
                     WHERE status_pembayaran='diterima' AND YEAR(tanggal_bayar)=? GROUP BY m");
$st->execute([$tahun]);
foreach ($st as $r) $perBulan[(int)$r['m']] = (float)$r['total'];

$bars = [];
foreach ($perBulan as $m => $v) $bars[bulan_pendek($m)] = $v;

$totalTahun = array_sum($perBulan);
$totalTampil = array_sum(array_map(fn($r) => (float)$r['jumlah_bayar'], $rows));
$tahunList = $pdo->query("SELECT DISTINCT YEAR(tanggal_bayar) y FROM pembayaran ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($tahun, array_map('intval', $tahunList), true)) $tahunList[] = $tahun;
rsort($tahunList);
$namaBulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

page_header('Laporan Pendapatan', 'admin');
?>
<div class="toolbar">
  <div><h1 class="title">Laporan Pendapatan</h1><p class="sub" style="margin:0">Hanya pembayaran berstatus <b>diterima</b> yang dihitung.</p></div>
  <form method="get" class="actions">
    <select name="tahun" onchange="this.form.submit()" style="width:auto">
      <?php foreach ($tahunList as $y): ?><option value="<?= (int)$y ?>" <?= (int)$y === $tahun ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?>
    </select>
    <select name="bulan" onchange="this.form.submit()" style="width:auto">
      <option value="0">Setahun penuh</option>
      <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $bulan ? 'selected' : '' ?>><?= $namaBulan[$m] ?></option><?php endfor; ?>
    </select>
    <a class="btn ghost" href="?tahun=<?= $tahun ?>&bulan=<?= $bulan ?>&export=csv">⬇ Ekspor CSV</a>
  </form>
</div>

<div class="stats">
  <div class="stat"><i>Rp</i><div><b><?= rupiah($totalTahun) ?></b><span>Total tahun <?= $tahun ?></span></div></div>
  <div class="stat"><i>▲</i><div><b><?= rupiah($totalTampil) ?></b><span>Total <?= $bulan ? $namaBulan[$bulan] . ' ' . $tahun : 'tampilan ini' ?></span></div></div>
  <div class="stat"><i>✓</i><div><b><?= count($rows) ?></b><span>Transaksi</span></div></div>
</div>

<div class="card" style="margin-bottom:22px">
  <h3>Pendapatan per Bulan — <?= $tahun ?></h3>
  <?= bar_svg($bars, 250) ?>
</div>

<h2 class="sec" style="margin-top:6px">Rincian Transaksi<?= $bulan ? ' · ' . $namaBulan[$bulan] : '' ?></h2>
<div class="tablewrap"><table>
  <tr><th>Tanggal</th><th>Penghuni</th><th>Kamar</th><th>Periode</th><th>Metode</th><th>Jumlah</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= tgl($r['tanggal_bayar']) ?></td><td><b><?= e($r['nama_penghuni']) ?></b></td><td><?= e($r['nomer_kamar']) ?></td>
      <td><?= e($r['periode_tagihan']) ?></td><td><?= ucfirst(e($r['metode_pembayaran'])) ?></td><td><b><?= rupiah($r['jumlah_bayar']) ?></b></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada pemasukan pada periode ini.</td></tr>
  <?php else: ?><tr><td colspan="5" style="text-align:right"><b>Total</b></td><td><b><?= rupiah($totalTampil) ?></b></td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
