<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
refresh_terlambat();

$pdo = db();
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

$totalKamar = (int)$one("SELECT COUNT(*) FROM kamar");
$terisi = (int)$one("SELECT COUNT(*) FROM kamar WHERE status_kamar='terisi'");
$tersedia = (int)$one("SELECT COUNT(*) FROM kamar WHERE status_kamar='tersedia'");
$perbaikan = $totalKamar - $terisi - $tersedia;
$okupansi = $totalKamar ? (int)round($terisi / $totalKamar * 100) : 0;

$stat = [
    ['⌂', 'Kamar tersedia', $tersedia],
    ['☺', 'Kamar terisi', $terisi],
    ['✎', 'Booking menunggu', $one("SELECT COUNT(*) FROM booking WHERE status_booking='mengungu'")],
    ['✓', 'Pembayaran menunggu', $one("SELECT COUNT(*) FROM pembayaran WHERE status_pembayaran='menunggu'")],
    ['!', 'Tagihan terlambat', $one("SELECT COUNT(*) FROM tagihan WHERE status_tagihan='terlambat'")],
    ['Rp', 'Pemasukan bulan ini', rupiah($one("SELECT COALESCE(SUM(jumlah_bayar),0) FROM pembayaran
        WHERE status_pembayaran='diterima' AND YEAR(tanggal_bayar)=YEAR(CURDATE()) AND MONTH(tanggal_bayar)=MONTH(CURDATE())"))],
];

// Pemasukan 6 bulan terakhir
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $chart[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
$st = $pdo->query("SELECT DATE_FORMAT(tanggal_bayar,'%Y-%m') ym, SUM(jumlah_bayar) total FROM pembayaran
                   WHERE status_pembayaran='diterima' AND tanggal_bayar >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01')
                   GROUP BY ym");
foreach ($st as $r) {
    if (isset($chart[$r['ym']])) $chart[$r['ym']] = (float)$r['total'];
}
$bars = [];
foreach ($chart as $ym => $v) $bars[bulan_pendek((int)substr($ym, 5, 2))] = $v;

$recent = $pdo->query("SELECT b.*, k.nomer_kamar FROM booking b JOIN kamar k ON k.id_kamar=b.id_kamar
                       ORDER BY b.tanggal_booking DESC, b.id_booking DESC LIMIT 6")->fetchAll();

page_header('Dashboard', 'admin');
?>
<div class="toolbar">
  <div><h1 class="title">Halo, <?= e($_SESSION['admin_nama'] ?? 'Admin') ?> 👋</h1>
  <p class="sub" style="margin:0">Ringkasan kondisi kost hari ini, <?= tgl(date('Y-m-d')) ?>.</p></div>
  <a class="btn" href="<?= url('admin/booking.php?status=mengungu') ?>">Proses Booking</a>
</div>

<div class="stats">
  <?php foreach ($stat as [$ico, $label, $val]): ?>
    <div class="stat"><i><?= e($ico) ?></i><div><b><?= e($val) ?></b><span><?= e($label) ?></span></div></div>
  <?php endforeach; ?>
</div>

<div class="dash">
  <div class="card">
    <h3>Pemasukan 6 Bulan Terakhir</h3>
    <?= bar_svg($bars) ?>
  </div>
  <div class="card">
    <h3>Tingkat Hunian</h3>
    <div class="donut-wrap">
      <div class="donut" style="background:conic-gradient(var(--g) 0 <?= $okupansi ?>%, var(--sand) <?= $okupansi ?>% 100%)">
        <div><span><b><?= $okupansi ?>%</b><small>terisi</small></span></div>
      </div>
      <div class="legend">
        <span><i style="background:var(--g)"></i>Terisi <?= $terisi ?></span>
        <span><i style="background:var(--sand);border:1px solid var(--line)"></i>Lainnya <?= $totalKamar - $terisi ?></span>
      </div>
      <small style="color:var(--muted)"><?= $tersedia ?> tersedia · <?= $perbaikan ?> perbaikan</small>
    </div>
  </div>
</div>

<h2 class="sec" style="margin-top:6px">Booking Terbaru</h2>
<div class="tablewrap"><table>
  <tr><th>Kode</th><th>Pemesan</th><th>Kamar</th><th>Masuk</th><th>Status</th></tr>
  <?php foreach ($recent as $r): ?>
    <tr>
      <td><?= e($r['kode_booking']) ?></td><td><b><?= e($r['nama_pemesan']) ?></b></td>
      <td><?= e($r['nomer_kamar']) ?></td><td><?= tgl($r['tanggal_masuk']) ?></td><td><?= badge($r['status_booking']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$recent): ?><tr><td colspan="5" class="empty">Belum ada booking.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
