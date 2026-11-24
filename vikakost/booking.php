<?php
require __DIR__ . '/inc/layout.php';

$pdo = db();
$kamarList = $pdo->query("SELECT * FROM kamar WHERE status_kamar='tersedia' ORDER BY CAST(nomer_kamar AS UNSIGNED), nomer_kamar")->fetchAll();
$draftKey = 'booking_draft';
$step = $_GET['langkah'] ?? 'info';
$errors = [];
$steps = ['info', 'kamar', 'konfirmasi'];

if (!in_array($step, $steps, true)) {
    redirect('booking.php');
}

if (!is_post() && !isset($_GET['langkah'])) {
    unset($_SESSION[$draftKey]);
}

$draft = $_SESSION[$draftKey] ?? [];
if (!is_array($draft)) {
    $draft = [];
}

if (!is_post() && isset($_GET['kamar'])) {
    $requestedRoomId = (int)$_GET['kamar'];
    foreach ($kamarList as $room) {
        if ((int)$room['id_kamar'] === $requestedRoomId) {
            $draft['id_kamar'] = $requestedRoomId;
            $_SESSION[$draftKey] = $draft;
            break;
        }
    }
}

$defaults = [
    'nama' => '',
    'no_hp' => '',
    'email' => '',
    'tanggal_masuk' => date('Y-m-d', strtotime('+1 day')),
    'durasi' => 1,
    'catatan' => '',
];
$draft = array_merge($defaults, $draft);
if (!is_post() && $step !== 'info' && ($draft['nama'] === '' || $draft['email'] === '')) {
    redirect('booking.php');
}

if (is_post()) {
    csrf_check();
    $action = $_POST['booking_action'] ?? '';

    if ($action === 'save_info') {
        $draft['nama'] = trim($_POST['nama'] ?? '');
        $draft['no_hp'] = trim($_POST['no_hp'] ?? '');
        $draft['email'] = trim($_POST['email'] ?? '');
        $draft['tanggal_masuk'] = trim($_POST['tanggal_masuk'] ?? '');
        $draft['durasi'] = (int)($_POST['durasi'] ?? 0);
        $draft['catatan'] = trim($_POST['catatan'] ?? '');

        if (mb_strlen($draft['nama']) < 2 || mb_strlen($draft['nama']) > 100) {
            $errors[] = 'Nama harus 2–100 karakter.';
        }
        if (!preg_match('/^[0-9+\-\s]{8,20}$/', $draft['no_hp'])) {
            $errors[] = 'Nomor HP tidak valid (8–20 digit).';
        }
        if (!filter_var($draft['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format email tidak valid.';
        }
        $tanggal = DateTime::createFromFormat('Y-m-d', $draft['tanggal_masuk']);
        if (!$tanggal || $tanggal->format('Y-m-d') !== $draft['tanggal_masuk'] || $draft['tanggal_masuk'] < date('Y-m-d')) {
            $errors[] = 'Tanggal masuk tidak valid atau sudah lewat.';
        }
        if ($draft['durasi'] < 1 || $draft['durasi'] > 24) {
            $errors[] = 'Durasi sewa 1–24 bulan.';
        }
        if (mb_strlen($draft['catatan']) > 1000) {
            $errors[] = 'Catatan tambahan maksimal 1.000 karakter.';
        }

        if (!$errors) {
            $_SESSION[$draftKey] = $draft;
            redirect('booking.php?langkah=' . (!empty($draft['id_kamar']) ? 'konfirmasi' : 'kamar'));
        }
        $step = 'info';
    } elseif ($action === 'select_room') {
        if ($draft['nama'] === '' || $draft['email'] === '') {
            redirect('booking.php');
        }

        $roomId = (int)($_POST['id_kamar'] ?? 0);
        $found = false;
        foreach ($kamarList as $room) {
            if ((int)$room['id_kamar'] === $roomId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $errors[] = 'Kamar tersebut sudah tidak tersedia. Silakan pilih kamar lain.';
            $step = 'kamar';
        } else {
            $draft['id_kamar'] = $roomId;
            $_SESSION[$draftKey] = $draft;
            redirect('booking.php?langkah=konfirmasi');
        }
    } elseif ($action === 'confirm') {
        $roomId = (int)($draft['id_kamar'] ?? 0);
        if ($draft['nama'] === '' || $draft['email'] === '' || $roomId < 1) {
            redirect('booking.php');
        }

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT * FROM kamar WHERE id_kamar = ? FOR UPDATE');
            $st->execute([$roomId]);
            $room = $st->fetch();
            if (!$room || $room['status_kamar'] !== 'tersedia') {
                throw new RuntimeException('Kamar tersebut sudah tidak tersedia. Silakan pilih kamar lain.');
            }

            $kode = kode_booking_baru();
            $pdo->prepare("INSERT INTO booking (id_kamar, kode_booking, nama_pemesan, no_hp, email, tanggal_booking, tanggal_masuk, durasi_sewa, catatan, status_booking)
                           VALUES (?,?,?,?,?,NOW(),?,?,?, 'mengungu')")
                ->execute([
                    $roomId,
                    $kode,
                    $draft['nama'],
                    $draft['no_hp'],
                    $draft['email'],
                    $draft['tanggal_masuk'],
                    $draft['durasi'],
                    $draft['catatan'] !== '' ? $draft['catatan'] : null,
                ]);
            $pdo->commit();

            unset($_SESSION[$draftKey]);
            flash('ok', 'Booking terkirim! Simpan kode booking kamu: ' . $kode . ' — pengelola akan segera memprosesnya.');
            redirect('cek_booking.php?kode=' . urlencode($kode) . '&hp=' . urlencode($draft['no_hp']));
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
            unset($draft['id_kamar']);
            $_SESSION[$draftKey] = $draft;
            $step = 'kamar';
        }
    } else {
        http_response_code(400);
        exit('Aksi booking tidak valid.');
    }
}

$selectedRoom = null;
foreach ($kamarList as $room) {
    if ((int)$room['id_kamar'] === (int)($draft['id_kamar'] ?? 0)) {
        $selectedRoom = $room;
        break;
    }
}
if ($step === 'konfirmasi' && !$selectedRoom) {
    $errors[] = 'Pilih kamar yang masih tersedia untuk melanjutkan.';
    unset($draft['id_kamar']);
    $_SESSION[$draftKey] = $draft;
    $step = 'kamar';
}

$featuredRoom = $selectedRoom ?? ($kamarList[0] ?? null);
$hargaMulai = $kamarList ? min(array_column($kamarList, 'harga_sewa')) : 0;
$stepIndex = array_search($step, $steps, true);

page_header('Booking Kamar');
?>
<div class="steps booking-steps" aria-label="Langkah booking">
  <?php foreach (['Info Diri', 'Pilih Kamar', 'Konfirmasi'] as $index => $label): ?>
    <?php $state = $index < $stepIndex ? 'done' : ($index === $stepIndex ? 'on' : ''); ?>
    <div class="step <?= $state ?>"><b><?= $index < $stepIndex ? '✓' : $index + 1 ?></b><span><?= $label ?></span></div>
  <?php endforeach; ?>
</div>

<?php foreach ($errors as $error): ?><div class="alert err"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($step === 'info'): ?>
  <div class="bk booking-layout">
    <section class="card booking-card">
      <h1>Informasi Penyewa</h1>
      <form id="booking-info-form" class="f" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_action" value="save_info">
        <div>
          <label for="nama">Nama Lengkap</label>
          <input id="nama" name="nama" value="<?= e($draft['nama']) ?>" placeholder="Masukan nama anda" required maxlength="100" autocomplete="name">
        </div>
        <div class="row">
          <div>
            <label for="no_hp">Nomor HP</label>
            <input id="no_hp" name="no_hp" value="<?= e($draft['no_hp']) ?>" placeholder="0812-3456-7890" required maxlength="20" inputmode="tel" autocomplete="tel">
          </div>
          <div>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="<?= e($draft['email']) ?>" placeholder="nama@gmail.com" required maxlength="100" autocomplete="email">
          </div>
        </div>
        <div class="booking-period">
          <h2>Periode menginap</h2>
          <div class="row">
            <div>
              <label for="tanggal_masuk">Tanggal mulai</label>
              <input id="tanggal_masuk" type="date" name="tanggal_masuk" value="<?= e($draft['tanggal_masuk']) ?>" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
              <label for="durasi">Durasi Sewa (bulan)</label>
              <input id="durasi" type="number" name="durasi" value="<?= (int)$draft['durasi'] ?>" min="1" max="24" required>
            </div>
          </div>
        </div>
        <div>
          <label for="catatan">Catatan tambahan (opsional)</label>
          <textarea id="catatan" name="catatan" rows="3" maxlength="1000" placeholder="Contoh: dekat kampus, butuh kamar lantai bawah, atau permintaan khusus lainnya..."><?= e($draft['catatan']) ?></textarea>
        </div>
      </form>
    </section>

    <aside class="card sum booking-summary">
      <?php if ($featuredRoom): ?>
        <div class="booking-summary-photo">
          <?= foto_box($featuredRoom, 'wide') ?>
          <?= badge($featuredRoom['status_kamar']) ?>
        </div>
      <?php endif; ?>
      <h2><?= e(setting('nama_kost', SITE_NAME)) ?></h2>
      <p class="booking-summary-note"><?= $selectedRoom ? 'Kamar ' . e($selectedRoom['nomer_kamar']) . ' dipilih' : 'Kost nyaman dengan kamar siap huni' ?></p>
      <div class="booking-summary-price">
        <small><?= $selectedRoom ? 'Harga kamar' : 'Harga mulai dari' ?></small>
        <strong><?= rupiah($selectedRoom['harga_sewa'] ?? $hargaMulai) ?></strong><span>/bulan</span>
      </div>
      <?php if ($kamarList): ?>
        <button class="btn block" type="submit" form="booking-info-form"><?= $selectedRoom ? 'Lanjut ke konfirmasi' : 'Lanjut ke pilih kamar' ?> <span aria-hidden="true">→</span></button>
      <?php else: ?>
        <button class="btn block" type="button" disabled>Kamar belum tersedia</button>
      <?php endif; ?>
    </aside>
  </div>

<?php elseif ($step === 'kamar'): ?>
  <div class="bk booking-layout">
    <section class="card booking-card">
      <div class="booking-card-heading">
        <div><h1>Pilih Kamar</h1><p>Pilih kamar yang sesuai untuk melanjutkan booking.</p></div>
        <a class="btn ghost sm" href="<?= url('booking.php?langkah=info') ?>">Ubah informasi</a>
      </div>
      <?php if (!$kamarList): ?>
        <div class="empty">Mohon maaf, saat ini tidak ada kamar yang tersedia.</div>
      <?php else: ?>
        <div class="booking-room-list">
          <?php foreach ($kamarList as $room): ?>
            <article class="booking-room-option">
              <?= foto_box($room, 'wide') ?>
              <div class="booking-room-info">
                <div class="room-head"><h2>Kamar <?= e($room['nomer_kamar']) ?></h2><?= badge($room['status_kamar']) ?></div>
                <div class="chips"><?php foreach (array_slice(fas_list($room['fasilitas']), 0, 3) as $facility): ?><span class="chip"><?= e($facility) ?></span><?php endforeach; ?></div>
                <strong class="price"><?= rupiah($room['harga_sewa']) ?><small>/bulan</small></strong>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="booking_action" value="select_room">
                  <input type="hidden" name="id_kamar" value="<?= (int)$room['id_kamar'] ?>">
                  <button class="btn sm" type="submit">Pilih kamar</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <aside class="card sum booking-summary">
      <h2>Informasi Penyewa</h2>
      <dl class="booking-summary-details">
        <dt>Nama</dt><dd><?= e($draft['nama']) ?></dd>
        <dt>Mulai</dt><dd><?= e(tgl($draft['tanggal_masuk'])) ?></dd>
        <dt>Durasi</dt><dd><?= (int)$draft['durasi'] ?> bulan</dd>
      </dl>
      <a class="btn ghost block" href="<?= url('booking.php?langkah=info') ?>">← Kembali</a>
    </aside>
  </div>

<?php else: ?>
  <div class="bk booking-layout">
    <section class="card booking-card">
      <h1>Konfirmasi Booking</h1>
      <p class="booking-confirm-intro">Periksa kembali informasi penyewa dan kamar sebelum mengirim booking.</p>
      <dl class="booking-confirm-details">
        <dt>Nama Lengkap</dt><dd><?= e($draft['nama']) ?></dd>
        <dt>Nomor HP</dt><dd><?= e($draft['no_hp']) ?></dd>
        <dt>Email</dt><dd><?= e($draft['email']) ?></dd>
        <dt>Tanggal mulai</dt><dd><?= e(tgl($draft['tanggal_masuk'])) ?></dd>
        <dt>Durasi sewa</dt><dd><?= (int)$draft['durasi'] ?> bulan</dd>
        <?php if ($draft['catatan'] !== ''): ?><dt>Catatan</dt><dd><?= nl2br(e($draft['catatan'])) ?></dd><?php endif; ?>
      </dl>
      <a class="btn ghost" href="<?= url('booking.php?langkah=kamar') ?>">← Ganti kamar</a>
    </section>
    <aside class="card sum booking-summary">
      <div class="booking-summary-photo">
        <?= foto_box($selectedRoom, 'wide') ?>
        <?= badge($selectedRoom['status_kamar']) ?>
      </div>
      <h2>Kamar <?= e($selectedRoom['nomer_kamar']) ?></h2>
      <div class="chips"><?php foreach (fas_list($selectedRoom['fasilitas']) as $facility): ?><span class="chip"><?= e($facility) ?></span><?php endforeach; ?></div>
      <div class="booking-summary-price">
        <small><?= rupiah($selectedRoom['harga_sewa']) ?> × <?= (int)$draft['durasi'] ?> bulan</small>
        <strong><?= rupiah($selectedRoom['harga_sewa'] * $draft['durasi']) ?></strong><span>estimasi total</span>
      </div>
      <form id="booking-confirm-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_action" value="confirm">
      </form>
      <button class="btn block" type="submit" form="booking-confirm-form">Konfirmasi Booking <span aria-hidden="true">→</span></button>
    </aside>
  </div>
<?php endif; ?>
<?php page_footer(); ?>
