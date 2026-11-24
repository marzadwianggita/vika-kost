<?php
require __DIR__ . '/inc/layout.php';

$pdo = db();
$tersedia = (int)$pdo->query("SELECT COUNT(*) FROM kamar WHERE status_kamar='tersedia'")->fetchColumn();
$rekom = $pdo->query("
    SELECT k.*, COALESCE(ROUND(AVG(u.rating), 1), k.rating) AS avg_rating
    FROM kamar k
    LEFT JOIN ulasan u ON k.id_kamar = u.id_kamar
    WHERE k.status_kamar='tersedia'
    GROUP BY k.id_kamar
    ORDER BY CAST(k.nomer_kamar AS UNSIGNED), k.nomer_kamar
    LIMIT 16
")->fetchAll();
$nama = setting('nama_kost', SITE_NAME);

page_header('Beranda');
?>
<section class="hero2 hero-home">
  <div>
    <span class="pill">🌿 Kost nyaman &amp; terjangkau</span>
<h1>Temukan Kost Nyaman, Harga Bersahabat di <em><?= e($nama) ?></em> <span style="color:#ffdf20;">♡</span></h1>
    <p><?= e(setting('deskripsi', 'Kost nyaman, bersih, dan harga bersahabat. Booking online mudah dan cepat.')) ?> Saat ini ada <b><?= $tersedia ?></b> kamar tersedia.</p>
    <form class="search" action="<?= url('kost.php') ?>" method="get">
      <input name="q" placeholder="Cari nomor kamar / fasilitas…">
      <button class="btn" type="submit">Cari</button>
    </form>
  </div>
  <div class="hero-img">
    <img class="hero-photo" src="<?= url('uploads/halamankost.jpg') ?>" alt="Suasana kamar Vika Kost">
  </div>
</section>

<section class="features">
  <div class="feat"><i>📍</i><b>Lokasi Strategis</b><span>Pusat kota Purbalingga, dekat dengan kampus 2 UIN SAIZU</span></div>
  <div class="feat"><i>✅</i><b>Fasilitas Lengkap</b><span>kamar mandi dalam, kamar mandi luar, lemari, kasur, meja kursi, kipas angin, free air, listrik, bebas bawa peralatan elektronik </span></div>
  <div class="feat"><i>💰</i><b>Harga Terjangkau</b><span>Transparan, tanpa biaya tersembunyi</span></div>
  <div class="feat"><i>👩🏻</i><b>Khusus Putri</b><span>Only putri</span></div>
  <div class="feat"><i>🗓️</i><b>Booking Mudah</b><span>Pilih kamar dan ajukan booking secara online</span></div>
</section>

<div class="head-row">
  <div><h2>Rekomendasi Kamar</h2><span class="sub" style="margin:0">Semua kamar yang tersedia saat ini.</span></div>
  <a class="btn ghost" href="<?= url('kost.php') ?>">Lihat Semua →</a>
</div>

<?php if (!$rekom): ?>
  <div class="card empty">Belum ada kamar tersedia saat ini.</div>
<?php else: ?>
<div class="grid rooms">
  <?php foreach ($rekom as $k): ?>
    <div class="rcard">
      <a href="<?= url('detail_kamar.php?id=' . (int)$k['id_kamar']) ?>"><?= foto_box($k) ?></a>
      <div class="body">
        <div class="room-head"><h3>Kamar <?= e($k['nomer_kamar']) ?></h3><?= badge($k['status_kamar']) ?></div>
        <?= stars($k['avg_rating'] ?? null) ?>
        <div class="chips"><?php foreach (array_slice(fas_list($k['fasilitas']), 0, 3) as $f): ?><span class="chip"><?= e($f) ?></span><?php endforeach; ?></div>
        <div class="foot-row">
          <div class="price"><?= rupiah($k['harga_sewa']) ?> <small>/bln</small></div>
          <a class="btn sm" href="<?= url('detail_kamar.php?id=' . (int)$k['id_kamar']) ?>">Detail</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="hero2 hero-cta">
  <div><h2 style="margin:0 0 6px;font-size:25px">Siap pindah ke kost impianmu?</h2><p style="margin:0">Booking kamar sekarang dan pantau statusnya kapan saja.</p></div>
  <a class="btn light lg" href="<?= url('booking.php') ?>" style="position:relative;z-index:1">Booking Sekarang</a>
</section>
<?php page_footer(); ?>