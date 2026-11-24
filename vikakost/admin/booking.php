<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();

if (is_post()) {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id_booking'] ?? 0);

    if (in_array($aksi, ['tolak', 'batal'], true)) {
        $new = $aksi === 'tolak' ? 'ditolak' : 'dibatalkan';
        $pdo->prepare("UPDATE booking SET status_booking=? WHERE id_booking=? AND status_booking='mengungu'")->execute([$new, $id]);
        flash('ok', 'Booking ditandai ' . $new . '.');
    } elseif ($aksi === 'terima') {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare('SELECT * FROM booking WHERE id_booking = ? FOR UPDATE');
            $st->execute([$id]);
            $b = $st->fetch();
            if (!$b || $b['status_booking'] !== 'mengungu') throw new RuntimeException('Booking sudah diproses sebelumnya.');

            $st = $pdo->prepare('SELECT * FROM kamar WHERE id_kamar = ? FOR UPDATE');
            $st->execute([$b['id_kamar']]);
            $k = $st->fetch();
            if (!$k || $k['status_kamar'] !== 'tersedia') throw new RuntimeException('Kamar ' . ($k['nomer_kamar'] ?? '?') . ' sedang tidak tersedia.');

            // 1) Penghuni + kode akses (disimpan sebagai hash)
            $kodeAkses = (string)random_int(100000, 999999);
            $pdo->prepare('INSERT INTO penghuni (id_booking, nama_penghuni, no_hp, email, kode_akses, tanggal_daftar) VALUES (?,?,?,?,?,NOW())')
                ->execute([$b['id_booking'], $b['nama_pemesan'], $b['no_hp'], $b['email'], password_hash($kodeAkses, PASSWORD_DEFAULT)]);
            $idPenghuni = (int)$pdo->lastInsertId();

            // 2) Sewa
            $mulai = $b['tanggal_masuk'];
            $selesai = (new DateTime($mulai))->modify('+' . (int)$b['durasi_sewa'] . ' month')->format('Y-m-d');
            $pdo->prepare("INSERT INTO sewa (id_penghuni, id_kamar, tanggal_mulai, tanggal_selesai, harga_sewa, tanggal_jatuh_tempo, status_sewa)
                           VALUES (?,?,?,?,?,?, 'aktif')")
                ->execute([$idPenghuni, $k['id_kamar'], $mulai, $selesai, $k['harga_sewa'], $mulai]);
            $idSewa = (int)$pdo->lastInsertId();

            // 3) Tagihan pertama
            $pdo->prepare("INSERT INTO tagihan (id_sewa, periode_tagihan, jumlah_tagihan, tanggal_jatuh_tempo, status_tagihan)
                           VALUES (?,?,?,?, 'belum bayar')")
                ->execute([$idSewa, date('Y-m', strtotime($mulai)), $k['harga_sewa'], $mulai]);

            // 4) Status kamar & booking
            $pdo->prepare("UPDATE kamar SET status_kamar='terisi' WHERE id_kamar=?")->execute([$k['id_kamar']]);
            $pdo->prepare("UPDATE booking SET status_booking='diterima' WHERE id_booking=?")->execute([$b['id_booking']]);

            $pdo->commit();
            flash('ok', 'Booking diterima. Kode akses untuk ' . $b['nama_pemesan'] . ' (login: ' . $b['email'] . '): ' . $kodeAkses . ' — catat & berikan ke penghuni, kode ini tidak ditampilkan lagi.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Gagal memproses booking: ' . $e->getMessage());
        }
    }
    redirect('admin/booking.php');
}

$filter = $_GET['status'] ?? '';
$valid = ['mengungu', 'diterima', 'ditolak', 'dibatalkan'];
$sql = 'SELECT b.*, k.nomer_kamar FROM booking b JOIN kamar k ON k.id_kamar=b.id_kamar';
$args = [];
if (in_array($filter, $valid, true)) {
    $sql .= ' WHERE b.status_booking = ?';
    $args[] = $filter;
}
$st = $pdo->prepare($sql . ' ORDER BY b.tanggal_booking DESC, b.id_booking DESC');
$st->execute($args);
$rows = $st->fetchAll();

page_header('Booking', 'admin');
?>
<h1 class="title">Data Booking</h1>
<p class="sub">Terima booking untuk otomatis membuat penghuni, sewa, dan tagihan pertama.</p>

<div class="filters">
  <a href="?" class="<?= $filter === '' ? 'on' : '' ?>">Semua</a>
  <?php foreach ($valid as $v): ?>
    <a href="?status=<?= $v ?>" class="<?= $filter === $v ? 'on' : '' ?>"><?= e(status_label($v)) ?></a>
  <?php endforeach; ?>
</div>

<div class="tablewrap"><table>
  <tr><th>Kode</th><th>Pemesan</th><th>Kontak</th><th>Kamar</th><th>Masuk</th><th>Durasi</th><th>Catatan</th><th>Status</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['kode_booking']) ?></td>
      <td><?= e($r['nama_pemesan']) ?></td>
      <td><?= e($r['no_hp']) ?><br><small style="color:var(--muted)"><?= e($r['email']) ?></small></td>
      <td><?= e($r['nomer_kamar']) ?></td>
      <td><?= tgl($r['tanggal_masuk']) ?></td>
      <td><?= (int)$r['durasi_sewa'] ?> bln</td>
      <td class="wrapcell" style="max-width:220px"><?= !empty($r['catatan']) ? nl2br(e($r['catatan'])) : '—' ?></td>
      <td><?= badge($r['status_booking']) ?></td>
      <td class="actions">
        <?php if ($r['status_booking'] === 'mengungu'): ?>
          <?php foreach ([['terima', 'Terima', ''], ['tolak', 'Tolak', 'danger'], ['batal', 'Batalkan', 'ghost']] as [$a, $lbl, $cls]): ?>
            <form method="post" <?= $a !== 'terima' ? 'onsubmit="return confirm(\'Yakin?\')"' : '' ?>>
              <?= csrf_field() ?><input type="hidden" name="id_booking" value="<?= (int)$r['id_booking'] ?>">
              <button class="btn sm <?= $cls ?>" name="aksi" value="<?= $a ?>"><?= $lbl ?></button>
            </form>
          <?php endforeach; ?>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">Tidak ada data.</td></tr><?php endif; ?>
</table></div>
<?php page_footer(); ?>
