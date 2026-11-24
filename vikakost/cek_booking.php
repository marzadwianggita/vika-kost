<?php
require __DIR__ . '/inc/layout.php';

/* Kirim ulasan (hanya booking berstatus diterima, 1 booking = 1 ulasan) */
if (is_post() && ($_POST['aksi'] ?? '') === 'ulasan') {
    csrf_check();
    $kodeU = trim($_POST['kode'] ?? '');
    $hpU = trim($_POST['hp'] ?? '');
    $rating = (int)($_POST['rating'] ?? 0);
    $komentar = trim($_POST['komentar'] ?? '');
    $komentar = function_exists('mb_substr') ? mb_substr($komentar, 0, 500) : substr($komentar, 0, 500);
    $back = 'cek_booking.php?kode=' . rawurlencode($kodeU) . '&hp=' . rawurlencode($hpU);
    $pdo = db();

    $st = $pdo->prepare("SELECT * FROM booking WHERE kode_booking = ? AND no_hp = ? AND status_booking = 'diterima'");
    $st->execute([$kodeU, $hpU]);
    $b = $st->fetch();

    if (!$b) {
        flash('err', 'Booking tidak ditemukan atau belum diterima, jadi belum bisa memberi ulasan.');
    } elseif ($rating < 1 || $rating > 5) {
        flash('err', 'Pilih jumlah bintang 1 sampai 5 dulu ya.');
    } else {
        try {
            $pdo->prepare('INSERT INTO ulasan (id_booking, id_kamar, nama, rating, komentar, tanggal) VALUES (?,?,?,?,?,NOW())')
                ->execute([$b['id_booking'], $b['id_kamar'], $b['nama_pemesan'], $rating, $komentar !== '' ? $komentar : null]);
            /* rating kamar = rata-rata semua ulasan */
            $pdo->prepare('UPDATE kamar SET rating = (SELECT ROUND(AVG(rating), 1) FROM ulasan WHERE id_kamar = ?) WHERE id_kamar = ?')
                ->execute([$b['id_kamar'], $b['id_kamar']]);
            flash('ok', 'Terima kasih! Ulasanmu sudah tersimpan.');
        } catch (Throwable $e) {
            flash('err', 'Ulasan gagal disimpan. Mungkin kamu sudah pernah memberi ulasan untuk booking ini.');
        }
    }
    redirect($back);
}

$kode = trim($_GET['kode'] ?? '');
$hp = trim($_GET['hp'] ?? '');
$data = null;
$notFound = false;

if ($kode !== '' && $hp !== '') {
    $st = db()->prepare("SELECT b.*, k.nomer_kamar, k.fasilitas, k.harga_sewa
                         FROM booking b JOIN kamar k ON k.id_kamar = b.id_kamar
                         WHERE b.kode_booking = ? AND b.no_hp = ?");
    $st->execute([$kode, $hp]);
    $data = $st->fetch();
    $notFound = !$data;
}

page_header('Cek Booking');
?>
<h1 class="title">Cek Status Booking</h1>
<p class="sub">Masukkan kode booking dan nomor HP yang kamu gunakan saat booking.</p>

<?php if ($notFound): ?><div class="alert err">Data tidak ditemukan. Periksa kembali kode booking dan nomor HP.</div><?php endif; ?>

<div class="card form-card" style="margin-bottom:20px">
  <form class="f" method="get">
    <div class="row">
      <div><label>Kode Booking</label><input name="kode" value="<?= e($kode) ?>" required></div>
      <div><label>No. HP</label><input name="hp" value="<?= e($hp) ?>" required></div>
    </div>
    <div><button class="btn" type="submit">Cek</button></div>
  </form>
</div>

<?php
$ulasanSaya = null;
$ulasanOk = true;
if ($data && $data['status_booking'] === 'diterima') {
    try {
        $us = db()->prepare('SELECT * FROM ulasan WHERE id_booking = ?');
        $us->execute([$data['id_booking']]);
        $ulasanSaya = $us->fetch() ?: null;
    } catch (Throwable $e) {
        $ulasanOk = false; /* tabel ulasan belum dibuat (migrasi.sql belum dijalankan) */
    }
}
?>
<?php if ($data): ?>
<div class="card form-card">
  <div class="room-head"><h3>Booking <?= e($data['kode_booking']) ?></h3><?= badge($data['status_booking']) ?></div>
  <dl class="kv" style="margin-top:14px">
    <dt>Nama</dt><dd><?= e($data['nama_pemesan']) ?></dd>
    <dt>Kamar</dt><dd><?= e($data['nomer_kamar']) ?> — <?= e($data['fasilitas']) ?></dd>
    <dt>Harga</dt><dd><?= rupiah($data['harga_sewa']) ?> / bulan</dd>
    <dt>Tanggal Booking</dt><dd><?= tgl($data['tanggal_booking']) ?></dd>
    <dt>Tanggal Masuk</dt><dd><?= tgl($data['tanggal_masuk']) ?></dd>
    <dt>Durasi</dt><dd><?= (int)$data['durasi_sewa'] ?> bulan</dd>
  </dl>
  <?php if ($data['status_booking'] === 'diterima'): ?>
    <div class="alert ok" style="margin:16px 0 0">Booking diterima! Admin akan memberikan <b>kode akses</b> untuk login ke <a href="<?= url('penghuni/login.php') ?>">portal penghuni</a> (login pakai email booking + kode akses).</div>
  <?php elseif ($data['status_booking'] === 'mengungu'): ?>
    <div class="alert info" style="margin:16px 0 0">Booking sedang menunggu konfirmasi pengelola.</div>
  <?php endif; ?>
</div>

<?php if ($data['status_booking'] === 'diterima' && $ulasanOk): ?>
<div class="card form-card" style="margin-top:20px">
  <?php if ($ulasanSaya): ?>
    <h3>Ulasanmu</h3>
    <?= stars($ulasanSaya['rating']) ?>
    <?php if (!empty($ulasanSaya['komentar'])): ?><p style="margin:8px 0 0"><?= nl2br(e($ulasanSaya['komentar'])) ?></p><?php endif; ?>
    <small style="color:var(--muted)">Dikirim <?= tgl($ulasanSaya['tanggal']) ?></small>
  <?php else: ?>
    <h3>Beri Rating untuk Kamar <?= e($data['nomer_kamar']) ?></h3>
    <form class="f" method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="aksi" value="ulasan">
      <input type="hidden" name="kode" value="<?= e($data['kode_booking']) ?>">
      <input type="hidden" name="hp" value="<?= e($data['no_hp']) ?>">
      <div class="star-input" role="radiogroup" aria-label="Pilih rating">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <input type="radio" id="rate<?= $i ?>" name="rating" value="<?= $i ?>" required>
          <label for="rate<?= $i ?>" title="<?= $i ?> bintang">★</label>
        <?php endfor; ?>
      </div>
      <div><label>Komentar (boleh kosong)</label><textarea name="komentar" rows="3" maxlength="500" placeholder="Ceritakan pengalamanmu di kost ini"></textarea></div>
      <div><button class="btn" type="submit">Kirim Ulasan</button></div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php page_footer(); ?>
