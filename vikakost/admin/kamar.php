<?php
require __DIR__ . '/../inc/layout.php';
require_admin();
$pdo = db();
$punyaFoto = kamar_punya_foto();

function hapus_file_foto(?string $f): void
{
    $f = basename((string)$f);
    if ($f !== '' && str_starts_with($f, 'kamar_') && is_file(__DIR__ . '/../uploads/' . $f)) {
        @unlink(__DIR__ . '/../uploads/' . $f);
    }
}

if (is_post()) {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id_kamar'] ?? 0);

    if ($aksi === 'simpan') {
        $no = trim($_POST['nomer_kamar'] ?? '');
        $fas = trim($_POST['fasilitas'] ?? '');
        $harga = (float)($_POST['harga_sewa'] ?? 0);
        $ratingIn = trim($_POST['rating'] ?? '');
        $rating = $ratingIn === '' ? null : max(0, min(5, round((float)$ratingIn, 1)));
        $stt = $_POST['status_kamar'] ?? '';
        $back = 'admin/kamar.php' . ($id ? '?edit=' . $id : '');

        if ($no === '' || $fas === '' || $harga <= 0 || !in_array($stt, ['tersedia', 'terisi', 'perbaikan'], true)) {
            flash('err', 'Data kamar belum lengkap / tidak valid.');
            redirect($back);
        }
        $dup = $pdo->prepare('SELECT id_kamar FROM kamar WHERE nomer_kamar = ? AND id_kamar <> ?');
        $dup->execute([$no, $id]);
        if ($dup->fetch()) {
            flash('err', 'Nomor kamar sudah dipakai.');
            redirect($back);
        }

        $fotoBaru = null;
        if ($punyaFoto && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $fotoBaru = simpan_foto($_FILES['foto']);
            } catch (RuntimeException $e) {
                flash('err', $e->getMessage());
                redirect($back);
            }
        }

        if ($id) {
            $pdo->prepare('UPDATE kamar SET nomer_kamar=?, fasilitas=?, harga_sewa=?, status_kamar=?, rating=? WHERE id_kamar=?')
                ->execute([$no, $fas, $harga, $stt, $rating, $id]);
            if ($fotoBaru) {
                $lama = $pdo->prepare('SELECT foto FROM kamar WHERE id_kamar=?');
                $lama->execute([$id]);
                hapus_file_foto($lama->fetchColumn());
                $pdo->prepare('UPDATE kamar SET foto=? WHERE id_kamar=?')->execute([$fotoBaru, $id]);
            }
            flash('ok', 'Kamar diperbarui.');
        } else {
            $pdo->prepare('INSERT INTO kamar (nomer_kamar, fasilitas, harga_sewa, status_kamar, rating) VALUES (?,?,?,?,?)')
                ->execute([$no, $fas, $harga, $stt, $rating]);
            if ($fotoBaru) {
                $pdo->prepare('UPDATE kamar SET foto=? WHERE id_kamar=?')->execute([$fotoBaru, (int)$pdo->lastInsertId()]);
            }
            flash('ok', 'Kamar ditambahkan.');
        }
    } elseif ($aksi === 'hapus_foto' && $id && $punyaFoto) {
        $lama = $pdo->prepare('SELECT foto FROM kamar WHERE id_kamar=?');
        $lama->execute([$id]);
        hapus_file_foto($lama->fetchColumn());
        $pdo->prepare('UPDATE kamar SET foto=NULL WHERE id_kamar=?')->execute([$id]);
        flash('ok', 'Foto dihapus.');
        redirect('admin/kamar.php?edit=' . $id);
    } elseif ($aksi === 'hapus' && $id) {
        try {
            $lama = $pdo->prepare('SELECT * FROM kamar WHERE id_kamar=?');
            $lama->execute([$id]);
            $row = $lama->fetch();
            $pdo->prepare('DELETE FROM kamar WHERE id_kamar = ?')->execute([$id]);
            if ($row) hapus_file_foto($row['foto'] ?? '');
            flash('ok', 'Kamar dihapus.');
        } catch (PDOException $e) {
            flash('err', 'Kamar tidak bisa dihapus karena sudah punya riwayat booking/sewa. Ubah statusnya menjadi "perbaikan" jika tidak dipakai.');
        }
    }
    redirect('admin/kamar.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM kamar WHERE id_kamar = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $pdo->query('SELECT * FROM kamar ORDER BY CAST(nomer_kamar AS UNSIGNED), nomer_kamar')->fetchAll();

page_header('Data Kamar', 'admin');
?>
<div class="toolbar">
  <div><h1 class="title">Data Kamar</h1><p class="sub" style="margin:0">Tambah, ubah, atau hapus data kamar.</p></div>
  <?php if (!$edit): ?><a class="btn" href="#form">+ Tambah Kamar</a><?php endif; ?>
</div>

<?php if (!$punyaFoto): ?>
  <div class="alert info">Fitur foto kamar belum aktif. Jalankan <b>migrasi.sql</b> di phpMyAdmin (database <b>vikakostdb</b>) untuk mengaktifkannya.</div>
<?php endif; ?>

<div class="tablewrap"><table>
  <tr><th>Foto</th><th>No.</th><th>Fasilitas</th><th>Harga/bulan</th><th>Status</th><th>Aksi</th></tr>
  <?php foreach ($rows as $r): ?>
    <?php $f = basename((string)($r['foto'] ?? '')); ?>
    <tr>
      <td><?= ($f !== '' && is_file(__DIR__ . '/../uploads/' . $f)) ? '<img class="thumb" src="' . e(url('uploads/' . rawurlencode($f))) . '" alt="">' : '<span class="thumb"></span>' ?></td>
      <td><b><?= e($r['nomer_kamar']) ?></b></td>
      <td class="wrapcell"><?= e($r['fasilitas']) ?></td>
      <td><?= rupiah($r['harga_sewa']) ?></td>
      <td><?= badge($r['status_kamar']) ?></td>
      <td class="actions">
        <a class="btn sm ghost" href="?edit=<?= (int)$r['id_kamar'] ?>#form">Edit</a>
        <form method="post" onsubmit="return confirm('Hapus kamar <?= e($r['nomer_kamar']) ?>?')">
          <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id_kamar" value="<?= (int)$r['id_kamar'] ?>">
          <button class="btn sm danger">Hapus</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada kamar.</td></tr><?php endif; ?>
</table></div>

<div class="card form-card" id="form">
  <h3><?= $edit ? 'Edit Kamar ' . e($edit['nomer_kamar']) : 'Tambah Kamar' ?></h3>
  <form class="f" method="post" enctype="multipart/form-data" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="aksi" value="simpan">
    <input type="hidden" name="id_kamar" value="<?= (int)($edit['id_kamar'] ?? 0) ?>">
    <div class="row">
      <div><label>Nomor Kamar</label><input name="nomer_kamar" value="<?= e($edit['nomer_kamar'] ?? '') ?>" required maxlength="20"></div>
      <div><label>Harga Sewa / bulan (Rp)</label><input type="number" step="1" min="0" name="harga_sewa" value="<?= e($edit ? (int)$edit['harga_sewa'] : '') ?>" required></div>
    </div>
    <div><label>Rating (0 - 5, boleh kosong)</label><input type="number" step="0.1" min="0" max="5" name="rating" value="<?= e($edit['rating'] ?? '') ?>" placeholder="mis. 4.7"></div>
    <div><label>Fasilitas</label><textarea name="fasilitas" rows="2" required placeholder="Pisahkan dengan koma, mis: kamar mandi dalam, AC, WiFi"><?= e($edit['fasilitas'] ?? '') ?></textarea></div>
    <div><label>Status</label>
      <select name="status_kamar">
        <?php foreach (['tersedia', 'terisi', 'perbaikan'] as $s): ?>
          <option value="<?= $s ?>" <?= ($edit['status_kamar'] ?? 'tersedia') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($punyaFoto): ?>
      <div><label>Foto Kamar</label>
        <?php if ($edit): ?><div style="max-width:240px;margin-bottom:8px;border-radius:12px;overflow:hidden"><?= foto_box($edit) ?></div><?php endif; ?>
        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp">
        <div class="hint">JPG / PNG / WEBP, maks 3 MB.<?= $edit ? ' Kosongkan jika tidak ingin mengganti.' : '' ?></div>
      </div>
    <?php endif; ?>
    <div class="actions">
      <button class="btn" type="submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Kamar' ?></button>
      <?php if ($edit): ?><a class="btn ghost" href="<?= url('admin/kamar.php') ?>">Batal</a><?php endif; ?>
    </div>
  </form>
  <?php if ($edit && $punyaFoto && !empty($edit['foto'])): ?>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('Hapus foto kamar ini?')">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus_foto"><input type="hidden" name="id_kamar" value="<?= (int)$edit['id_kamar'] ?>">
      <button class="btn sm danger">Hapus Foto</button>
    </form>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
