<?php
require __DIR__ . '/inc/layout.php';

$pdo = db();
$q = trim($_GET['q'] ?? '');
$hargaLegacy = max(0, (int)($_GET['harga'] ?? 0));
$facilityOptions = [
    'wifi' => 'Wi-Fi',
    'listrik' => 'Listrik',
    'kamar mandi' => 'Kamar Mandi',
    'kipas angin' => 'Kipas Angin',
    'meja belajar' => 'Meja Belajar',
];
$fas = array_values(array_intersect((array)($_GET['fas'] ?? []), array_keys($facilityOptions)));
$semua = isset($_GET['semua']);
$urut = $_GET['urut'] ?? 'populer';

$priceBounds = $pdo->query('SELECT MIN(harga_sewa), MAX(harga_sewa) FROM kamar')->fetch(PDO::FETCH_NUM);
$hargaMin = (int)($priceBounds[0] ?? 0);
$hargaMax = (int)($priceBounds[1] ?? 0);
$hargaMinControl = min(max((int)($_GET['harga_min'] ?? $hargaMin), $hargaMin), $hargaMax);
$hargaMaxRequest = (int)($_GET['harga_max'] ?? ($hargaLegacy > 0 ? $hargaLegacy : $hargaMax));
$hargaMaxControl = min(max($hargaMaxRequest, $hargaMin), $hargaMax);
if ($hargaMinControl > $hargaMaxControl) {
    $hargaMinControl = $hargaMaxControl;
}
$rangeMax = max($hargaMax, $hargaMin + 1);

$where = [];
$args = [];
if (!$semua) $where[] = "k.status_kamar = 'tersedia'";
if ($q !== '') {
    $where[] = '(k.nomer_kamar LIKE ? OR k.fasilitas LIKE ?)';
    $args[] = "%$q%";
    $args[] = "%$q%";
}
if ($hargaMinControl > $hargaMin || $hargaMaxControl < $hargaMax) {
    $where[] = 'k.harga_sewa BETWEEN ? AND ?';
    $args[] = $hargaMinControl;
    $args[] = $hargaMaxControl;
}
foreach ($fas as $f) {
    if ($f === 'wifi') {
        $where[] = "REPLACE(REPLACE(LOWER(k.fasilitas), '-', ''), ' ', '') LIKE ?";
        $args[] = '%wifi%';
    } else {
        $where[] = 'k.fasilitas LIKE ?';
        $args[] = "%$f%";
    }
}
$order = match ($urut) {
    'populer' => '(SELECT COUNT(*) FROM booking b WHERE b.id_kamar = k.id_kamar) DESC, CAST(k.nomer_kamar AS UNSIGNED), k.nomer_kamar',
    'termurah' => 'k.harga_sewa ASC',
    'termahal' => 'k.harga_sewa DESC',
    default => 'CAST(k.nomer_kamar AS UNSIGNED), k.nomer_kamar',
};
$sql = 'SELECT k.*, COALESCE(ROUND(AVG(u.rating), 1), k.rating) AS avg_rating
        FROM kamar k
        LEFT JOIN ulasan u ON k.id_kamar = u.id_kamar'
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
      . ' GROUP BY k.id_kamar ORDER BY ' . $order;
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$GLOBALS['__room_query'] = $q;

page_header('Daftar Kost', 'rooms');
?>
<div class="kost room-browser">
  <aside class="card filter room-filter">
    <form id="room-filter-form" method="get" action="<?= url('kost.php') ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <h2>Filter</h2>

      <div class="filter-group">
        <label for="room-location">Lokasi</label>
        <select id="room-location" disabled>
          <option>Purbalingga</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="harga">Harga per Bulan</label>
        <div class="price-range-label"><span id="harga-min-value"><?= rupiah($hargaMinControl) ?></span><span id="harga-max-value"><?= rupiah($hargaMaxControl) ?></span></div>
        <div class="price-range-control">
          <input id="harga-min" class="price-range" type="range" name="harga_min" min="<?= $hargaMin ?>" max="<?= $rangeMax ?>" value="<?= $hargaMinControl ?>" step="1" aria-label="Harga minimum per bulan">
          <input id="harga-max" class="price-range" type="range" name="harga_max" min="<?= $hargaMin ?>" max="<?= $rangeMax ?>" value="<?= $hargaMaxControl ?>" step="1" aria-label="Harga maksimum per bulan">
        </div>
      </div>

      <fieldset class="filter-group">
        <legend>Tipe Kost</legend>
        <label class="check"><input type="checkbox" checked disabled> Putri</label>
      </fieldset>

      <fieldset class="filter-group">
        <legend>Fasilitas</legend>
        <?php foreach ($facilityOptions as $value => $label): ?>
          <label class="check"><input type="checkbox" name="fas[]" value="<?= e($value) ?>" <?= in_array($value, $fas, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </fieldset>

      <label class="check filter-all"><input type="checkbox" name="semua" value="1" <?= $semua ? 'checked' : '' ?>> Tampilkan kamar terisi</label>
      <button class="btn block" type="submit">Terapkan Filter</button>
      <a class="btn ghost block" href="<?= url('kost.php') ?>">Reset Filter</a>
    </form>
  </aside>

  <section class="room-results" id="room-results" aria-label="Hasil kamar">
    <div class="room-results-head">
      <h1><?= count($rows) ?> hasil ditemukan</h1>
      <label class="room-sort">Urutkan:
        <select name="urut" form="room-filter-form" aria-label="Urutkan hasil" onchange="this.form.requestSubmit()">
          <option value="populer" <?= $urut === 'populer' ? 'selected' : '' ?>>Terpopuler</option>
          <option value="nomor" <?= $urut === 'nomor' ? 'selected' : '' ?>>Nomor kamar</option>
          <option value="termurah" <?= $urut === 'termurah' ? 'selected' : '' ?>>Harga terendah</option>
          <option value="termahal" <?= $urut === 'termahal' ? 'selected' : '' ?>>Harga tertinggi</option>
        </select>
      </label>
    </div>

    <?php foreach ($rows as $k): ?>
      <article class="room-result-row">
        <a class="room-result-photo" href="<?= url('detail_kamar.php?id=' . (int)$k['id_kamar']) ?>">
          <?= foto_box($k, 'wide') ?>
          <?= badge($k['status_kamar']) ?>
        </a>
        <div class="room-result-info">
          <h2>Kamar No. <?= e($k['nomer_kamar']) ?></h2>
          <p class="room-location">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
            <?= e(setting('alamat', 'Purbalingga')) ?>
          </p>
          <?= stars($k['avg_rating'] ?? null) ?>
          <p class="room-status">Kost khusus putri</p>
          <div class="chips">
            <?php foreach (array_slice(fas_list($k['fasilitas']), 0, 3) as $f): ?>
              <span class="chip"><?= e($f) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="room-result-actions">
          <div class="price"><?= rupiah($k['harga_sewa']) ?> <small>/bulan</small></div>
          <a class="btn sm" href="<?= url('detail_kamar.php?id=' . (int)$k['id_kamar']) ?>">Lihat Detail <span aria-hidden="true">→</span></a>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$rows): ?><div class="card empty">Tidak ada kamar yang cocok dengan filter. Coba ubah atau reset filter.</div><?php endif; ?>
  </section>
</div>
<script>
  const minRange = document.getElementById('harga-min');
  const maxRange = document.getElementById('harga-max');
  const minOutput = document.getElementById('harga-min-value');
  const maxOutput = document.getElementById('harga-max-value');
  const rangeControl = document.querySelector('.price-range-control');
  const viewToggle = document.getElementById('room-view-toggle');
  const roomResults = document.getElementById('room-results');
  const formatPrice = value => 'Rp ' + Number(value).toLocaleString('id-ID');
  const updatePriceRange = changedRange => {
    if (Number(minRange.value) > Number(maxRange.value)) {
      if (changedRange === minRange) maxRange.value = minRange.value;
      else minRange.value = maxRange.value;
    }
    minOutput.textContent = formatPrice(minRange.value);
    maxOutput.textContent = formatPrice(maxRange.value);
    const min = Number(minRange.min);
    const max = Number(maxRange.max);
    const span = max - min || 1;
    rangeControl.style.setProperty('--range-start', ((Number(minRange.value) - min) / span * 100) + '%');
    rangeControl.style.setProperty('--range-end', ((Number(maxRange.value) - min) / span * 100) + '%');
  };
  minRange.addEventListener('input', () => updatePriceRange(minRange));
  maxRange.addEventListener('input', () => updatePriceRange(maxRange));
  updatePriceRange(maxRange);
  if (viewToggle) {
    viewToggle.addEventListener('click', () => {
      const isGrid = roomResults.classList.toggle('grid-view');
      viewToggle.setAttribute('aria-pressed', String(isGrid));
      viewToggle.setAttribute('aria-label', isGrid ? 'Tampilkan tampilan daftar' : 'Tampilkan tampilan kisi');
    });
  }
</script>
<?php page_footer(); ?>