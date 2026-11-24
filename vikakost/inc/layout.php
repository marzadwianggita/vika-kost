<?php
require_once __DIR__ . '/config.php';

function page_header(string $title, string $area = 'public'): void
{
    $GLOBALS['__area'] = $area;
    $nama = setting('nama_kost', SITE_NAME);
    $cur = ltrim(substr($_SERVER['SCRIPT_NAME'], strlen(BASE_URL)), '/');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($nama) ?></title>
<link rel="stylesheet" href="<?= url('assets/style.css?v=2') ?>">
</head>
<body class="area-<?= e($area) ?>">
<?php
    if ($area === 'auth') {
        echo '<main class="auth">';
        render_flash();
        return;
    }

    if ($area === 'admin') {
        $nav = [
            'admin/index.php' => ['Dashboard', '▦'],
            'admin/kamar.php' => ['Data Kamar', '⌂'],
            'admin/booking.php' => ['Booking', '✎'],
            'admin/penghuni.php' => ['Penghuni', '☺'],
            'admin/sewa.php' => ['Sewa', '⌘'],
            'admin/tagihan.php' => ['Tagihan', '▤'],
            'admin/pembayaran.php' => ['Pembayaran', '✓'],
            'admin/laporan.php' => ['Laporan Pendapatan', '▲'],
            'admin/pengaturan.php' => ['Pengaturan', '⚙'],
        ];
        ?>
<div class="app">
  <aside class="side">
    <a class="brand" href="<?= url('admin/index.php') ?>"><?= logo_html() ?><span><?= e($nama) ?><small>Panel Admin</small></span></a>
    <nav>
      <?php foreach ($nav as $path => [$label, $ico]): ?>
        <a href="<?= url($path) ?>" class="<?= $cur === $path ? 'active' : '' ?>"><i><?= $ico ?></i><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="who"><b><?= e($_SESSION['admin_nama'] ?? 'Admin') ?></b><small>Administrator</small></div>
      <a href="<?= url('index.php') ?>" target="_blank">Lihat Website ↗</a>
      <a href="<?= url('logout.php') ?>">Keluar</a>
    </div>
  </aside>
  <section class="content">
<?php
        render_flash();
        return;
    }

    if ($area === 'rooms') {
        $brandLogo = logo_html();
        ?>
<header class="topbar rooms-topbar">
  <div class="wrap bar rooms-bar">
    <a class="brand rooms-brand" href="<?= url('index.php') ?>">
      <?= $brandLogo !== '' ? $brandLogo : '<span class="logo" aria-hidden="true">⌂</span>' ?>
      <?= e($nama) ?>
    </a>
    <form class="room-search" action="<?= url('kost.php') ?>" method="get" role="search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m16 16 4.2 4.2"/></svg>
      <input type="search" name="q" value="<?= e($GLOBALS['__room_query'] ?? '') ?>" placeholder="Cari kost di Purbalingga..." aria-label="Cari kamar">
    </form>
    <button class="rooms-home" id="room-view-toggle" type="button" aria-label="Tampilkan tampilan kisi" aria-pressed="false" title="Ubah tampilan">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>
    </button>
  </div>
</header>
<main class="wrap main rooms-main">
<?php
        render_flash();
        return;
    }

    if ($area === 'penghuni') {
        $nav = ['penghuni/index.php' => 'Tagihan Saya'];
        $right = '<a href="' . url('logout.php') . '" class="btn sm">Keluar</a>';
    } else {
        $nav = [
            'index.php' => 'Beranda',
            'kost.php' => 'Kamar',
            'index.php#tentang-kami' => 'Tentang Kami',
            'index.php#kontak' => 'Kontak',
        ];
        $right = '<a href="' . url('booking.php') . '" class="btn sm">Booking Sekarang <span aria-hidden="true">→</span></a>'
            . '<a href="' . url('login.php') . '" class="btn sm ghost">Login Admin</a>';
    }
    ?>
<header class="topbar">
  <div class="wrap bar">
    <a class="brand" href="<?= url($area === 'penghuni' ? 'penghuni/index.php' : 'index.php') ?>"><?php $logo = logo_html(); ?><?= $logo !== '' ? $logo : '<span class="logo" aria-hidden="true">⌂</span>' ?><?= e($nama) ?></a>
    <nav>
      <?php foreach ($nav as $path => $label): ?>
        <a href="<?= url($path) ?>" class="<?= $cur === $path ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="right"><?= $right ?></div>
  </div>
</header>
<main class="wrap main">
<?php
    render_flash();
}

function page_footer(): void
{
    $area = $GLOBALS['__area'] ?? 'public';
    if ($area === 'auth') {
        echo '</main></body></html>';
        return;
    }
    if ($area === 'admin') {
        echo '</section></div></body></html>';
        return;
    }
    $nama = setting('nama_kost', SITE_NAME);
    $wa = preg_replace('/\D+/', '', setting('whatsapp', SITE_WA));
    ?>
</main>
<footer class="foot">
  <div class="wrap foot-grid">
    <div id="tentang-kami">
      <h4>Tentang Kami</h4>
      <a class="brand" href="<?= url('index.php') ?>"><?= logo_html() ?><?= e($nama) ?></a>
      <p><?= e(setting('deskripsi', 'Kost nyaman, bersih, dan harga bersahabat. Booking online mudah dan cepat.')) ?></p>
    </div>
    <div id="kontak">
      <h4>Kontak</h4>
      <p><?= e(setting('alamat', 'Alamat belum diatur')) ?></p>
      <?php if ($wa): ?><p><a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp · +<?= e($wa) ?></a></p><?php endif; ?>
      <?php $email = setting('email'); ?>
      <?php if ($email): ?><p><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p><?php endif; ?>
    </div>
    <div>
      <h4>Menu</h4>
      <p><a href="<?= url('kost.php') ?>">Daftar Kost</a></p>
      <p><a href="<?= url('booking.php') ?>">Booking</a></p>
      <p><a href="<?= url('cek_booking.php') ?>">Cek Booking</a></p>
      <p><a href="<?= url('penghuni/login.php') ?>">Portal Penghuni</a></p>
    </div>
  </div>
  <div class="copy">© <?= date('Y') ?> <?= e($nama) ?> · <a href="<?= url('login.php') ?>">Login Admin</a></div>
</footer>
</body>
</html>
<?php
}
