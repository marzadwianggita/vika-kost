<?php
require __DIR__ . '/inc/layout.php';

$pdo = db();
$id_kamar = (int)($_GET['id'] ?? 0);

// Proses simpan ulasan baru (khusus penghuni atau yang pernah booking kamar ini)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim_ulasan'])) {
    $no_hp    = preg_replace('/\D+/', '', $_POST['no_hp'] ?? '');
    $rating   = (int)($_POST['rating'] ?? 5);
    $komentar = trim($_POST['komentar'] ?? '');

    if ($no_hp !== '' && strlen($no_hp) >= 9 && $rating >= 1 && $rating <= 5) {
        $digitAkhir = substr($no_hp, -9); // Ambil 9 digit terakhir agar cocok untuk format 08... maupun 628...
        $namaValid  = null;

        // 1. Cek di tabel booking untuk kamar ini
        try {
            $cekBooking = $pdo->prepare("SELECT * FROM booking WHERE id_kamar = ? ORDER BY id_booking DESC");
            $cekBooking->execute([$id_kamar]);
            foreach ($cekBooking->fetchAll() as $b) {
                $hpDb = preg_replace('/\D+/', '', $b['no_hp'] ?? $b['telepon'] ?? $b['no_wa'] ?? '');
                if ($hpDb !== '' && str_ends_with($hpDb, $digitAkhir)) {
                    $namaValid = $b['nama_pemesan'] ?? $b['nama'] ?? 'Pemesan';
                    break;
                }
            }
        } catch (Throwable $e) {}

        // 2. Jika belum ketemu di booking, cek di tabel penghuni untuk kamar ini
        if (!$namaValid) {
            try {
                $cekPenghuni = $pdo->prepare("SELECT * FROM penghuni WHERE id_kamar = ? ORDER BY id_penghuni DESC");
                $cekPenghuni->execute([$id_kamar]);
                foreach ($cekPenghuni->fetchAll() as $p) {
                    $hpDb = preg_replace('/\D+/', '', $p['no_hp'] ?? $p['telepon'] ?? $p['no_wa'] ?? '');
                    if ($hpDb !== '' && str_ends_with($hpDb, $digitAkhir)) {
                        $namaValid = $p['nama_penghuni'] ?? $p['nama'] ?? 'Penghuni';
                        break;
                    }
                }
            } catch (Throwable $e) {}
        }

        if ($namaValid) {
            $ins = $pdo->prepare('INSERT INTO ulasan (id_kamar, nama_pengulas, rating, komentar, tanggal_ulasan) VALUES (?, ?, ?, ?, CURDATE())');
            $ins->execute([$id_kamar, $namaValid, $rating, $komentar]);
            flash('ok', 'Terima kasih ' . $namaValid . '! Ulasan kamu berhasil dikirim.');
        } else {
            flash('err', 'Maaf, nomor HP tidak terdaftar sebagai pemesan atau penghuni kamar ini.');
        }
    } else {
        flash('err', 'Nomor HP yang valid dan rating bintang wajib diisi.');
    }
    redirect('detail_kamar.php?id=' . $id_kamar);
}

$st = $pdo->prepare('SELECT * FROM kamar WHERE id_kamar = ?');
$st->execute([$id_kamar]);
$k = $st->fetch();
if (!$k) {
    http_response_code(404);
    flash('err', 'Kamar tidak ditemukan.');
    redirect('kost.php');
}

$lain = $pdo->prepare("
    SELECT k.*, COALESCE(ROUND(AVG(u.rating), 1), k.rating) AS avg_rating
    FROM kamar k
    LEFT JOIN ulasan u ON k.id_kamar = u.id_kamar
    WHERE k.status_kamar='tersedia' AND k.id_kamar <> ?
    GROUP BY k.id_kamar
    ORDER BY k.harga_sewa
    LIMIT 3
");
$lain->execute([$k['id_kamar']]);
$lain = $lain->fetchAll();

$avg_rating = $k['rating'] ?? null;
try {
    $ul = $pdo->prepare('SELECT * FROM ulasan WHERE id_kamar = ? ORDER BY tanggal_ulasan DESC, id_ulasan DESC LIMIT 10');
    $ul->execute([$k['id_kamar']]);
    $ulasan = $ul->fetchAll();

    // Hitung rata-rata rating otomatis dari tabel ulasan jika ada data
    $stAvg = $pdo->prepare('SELECT ROUND(AVG(rating), 1) FROM ulasan WHERE id_kamar = ?');
    $stAvg->execute([$k['id_kamar']]);
    $rata = $stAvg->fetchColumn();
    if ($rata) {
        $avg_rating = $rata;
    }
} catch (Throwable $e) {
    $ulasan = [];
}

$wa = preg_replace('/\D+/', '', setting('whatsapp', SITE_WA));
$tersedia = $k['status_kamar'] === 'tersedia';

page_header('Kamar ' . $k['nomer_kamar']);
?>
<div class="crumb"><a href="<?= url('index.php') ?>">Beranda</a> › <a href="<?= url('kost.php') ?>">Daftar Kost</a> › Kamar <?= e($k['nomer_kamar']) ?></div>

<div class="detail">
  <div>
    <div class="big"><?= foto_box($k, 'wide') ?></div>
    <div class="card" style="margin-top:18px">
      <div class="room-head"><h1 class="title" style="margin:0">Kamar <?= e($k['nomer_kamar']) ?></h1><?= badge($k['status_kamar']) ?></div>
      <?= stars($avg_rating) ?>
      <h3 style="margin-top:18px">Fasilitas</h3>
      <div class="chips"><?php foreach (fas_list($k['fasilitas']) as $f): ?><span class="chip">✓ <?= e($f) ?></span><?php endforeach; ?></div>
      <dl class="kv" style="margin-top:16px">
        <dt>Harga sewa</dt><dd><?= rupiah($k['harga_sewa']) ?> / bulan</dd>
        <dt>Status</dt><dd><?= $tersedia ? 'Tersedia untuk dibooking' : e(ucfirst($k['status_kamar'])) ?></dd>
      </dl>
    </div>

    <!-- Form Tambah Ulasan (Khusus Penghuni / Pemesan) -->
    <div class="card" style="margin-top:18px">
      <h3>Tulis Ulasan untuk Kamar Ini</h3>
      <p class="sub" style="margin:4px 0 12px">Khusus penghuni atau yang pernah melakukan booking kamar ini.</p>
      <form method="post" style="display:grid;gap:12px">
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px">Nomor HP / WhatsApp (Sesuai Data Booking / Penghuni)</label>
          <input type="text" name="no_hp" required placeholder="Contoh: 081234567890" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px">
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px">Rating Bintang</label>
          <select name="rating" style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px">
            <option value="5">★★★★★ (5 - Sangat Nyaman)</option>
            <option value="4">★★★★☆ (4 - Nyaman)</option>
            <option value="3">★★★☆☆ (3 - Cukup)</option>
            <option value="2">★★☆☆☆ (2 - Kurang)</option>
            <option value="1">★☆☆☆☆ (1 - Buruk)</option>
          </select>
        </div>
        <div>
          <label style="display:block;font-weight:600;margin-bottom:4px">Komentar / Pengalaman</label>
          <textarea name="komentar" rows="3" placeholder="Ceritakan pengalamanmu nge-kost di kamar ini..." style="width:100%;padding:10px;border:1px solid var(--line);border-radius:8px"></textarea>
        </div>
        <div>
          <button type="submit" name="kirim_ulasan" class="btn">Kirim Ulasan</button>
        </div>
      </form>
    </div>

    <!-- Daftar Ulasan Penghuni -->
    <?php if ($ulasan): ?>
    <div class="card" style="margin-top:18px">
      <h3>Ulasan Penghuni (<?= count($ulasan) ?>)</h3>
      <?php foreach ($ulasan as $u): ?>
        <div class="ulasan-item" style="padding:12px 0;border-bottom:1px solid var(--line)">
          <b><?= e(explode(' ', trim($u['nama_pengulas']))[0]) ?></b> · <small style="color:var(--muted)"><?= tgl($u['tanggal_ulasan']) ?></small><br>
          <?= stars($u['rating']) ?>
          <?php if (!empty($u['komentar'])): ?><p style="margin:6px 0 0"><?= nl2br(e($u['komentar'])) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <aside class="card boxside">
    <div class="price" style="font-size:26px"><?= rupiah($k['harga_sewa']) ?> <small>/ bulan</small></div>
    <p class="sub" style="margin:6px 0 16px">Booking dikonfirmasi oleh pengelola setelah dikirim.</p>
    <?php if ($tersedia): ?>
      <a class="btn block lg" href="<?= url('booking.php?kamar=' . (int)$k['id_kamar']) ?>">Booking Kamar Ini</a>
    <?php else: ?>
      <button class="btn block lg" disabled>Tidak Tersedia</button>
    <?php endif; ?>
    <?php if ($wa): ?>
      <a class="btn wa block" style="margin-top:10px" target="_blank" rel="noopener"
         href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Halo, saya tertarik dengan Kamar ' . $k['nomer_kamar'] . ' di ' . setting('nama_kost', SITE_NAME)) ?>">Tanya via WhatsApp</a>
    <?php endif; ?>
    <a class="btn ghost block" style="margin-top:10px" href="<?= url('kost.php') ?>">← Kembali ke daftar</a>
  </aside>
</div>

<?php if ($lain): ?>
  <h2 class="sec">Kamar Lainnya</h2>
  <div class="grid">
    <?php foreach ($lain as $o): ?>
      <div class="rcard">
        <a href="<?= url('detail_kamar.php?id=' . (int)$o['id_kamar']) ?>"><?= foto_box($o) ?></a>
        <div class="body">
          <h3>Kamar <?= e($o['nomer_kamar']) ?></h3>
          <?= stars($o['avg_rating'] ?? null) ?>
          <div class="foot-row"><div class="price"><?= rupiah($o['harga_sewa']) ?> <small>/bln</small></div>
            <a class="btn sm" href="<?= url('detail_kamar.php?id=' . (int)$o['id_kamar']) ?>">Detail</a></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer(); ?>