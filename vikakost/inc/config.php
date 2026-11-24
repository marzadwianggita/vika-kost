<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ====== PENGATURAN (ubah sesuai XAMPP/Laragon kamu) ====== */
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'vikakostdb');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SITE_NAME', 'Vika Kost');
define('SITE_WA', '6281234567890'); // nomor WhatsApp pemilik (format 62...)
/* ========================================================= */

// Deteksi otomatis URL folder aplikasi (mis. /kost)
$__doc = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$__app = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
define('BASE_URL', ($__doc !== '' && str_starts_with($__app, $__doc)) ? rtrim(substr($__app, strlen($__doc)), '/') : '');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Koneksi database gagal. Pastikan MySQL menyala, database "' . DB_NAME . '" sudah di-import, dan cek inc/config.php.');
        }
    }
    return $pdo;
}

/* ---------- Helper umum ---------- */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rupiah($n): string
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function tgl($d): string
{
    if (!$d) return '-';
    $t = strtotime((string)$d);
    $b = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return date('d', $t) . ' ' . $b[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function render_flash(): void
{
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Sesi tidak valid. Silakan kembali dan muat ulang halaman.');
    }
}

/* ---------- Password / kode akses (mendukung data lama plaintext) ---------- */
function is_hashed(string $s): bool
{
    return str_starts_with($s, '$2y$') || str_starts_with($s, '$argon2');
}

function check_secret(string $input, string $stored): bool
{
    return is_hashed($stored) ? password_verify($input, $stored) : hash_equals($stored, $input);
}

/* ---------- Otorisasi ---------- */
function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        redirect('login.php');
    }
}

function require_penghuni(): void
{
    if (empty($_SESSION['penghuni_id'])) {
        redirect('penghuni/login.php');
    }
}

/* ---------- Tampilan status ---------- */
function status_label(string $s): string
{
    return match ($s) {
        'mengungu' => 'Menunggu',
        default => ucfirst($s),
    };
}

function badge(string $s): string
{
    $cls = match ($s) {
        'tersedia', 'diterima', 'lunas', 'aktif' => 'ok',
        'mengungu', 'menunggu', 'menunggu verifikasi', 'belum bayar' => 'warn',
        'ditolak', 'dibatalkan', 'terlambat', 'perbaikan' => 'bad',
        default => 'info', // terisi, selesai
    };
    return '<span class="badge ' . $cls . '">' . e(status_label($s)) . '</span>';
}

/* Tandai tagihan yang lewat jatuh tempo sebagai terlambat */
function refresh_terlambat(): void
{
    db()->exec("UPDATE tagihan SET status_tagihan='terlambat'
                WHERE status_tagihan='belum bayar' AND tanggal_jatuh_tempo < CURDATE()");
}

function kode_booking_baru(): string
{
    $pdo = db();
    do {
        $kode = 'KST' . date('ymd') . random_int(1000, 9999);
        $st = $pdo->prepare('SELECT 1 FROM booking WHERE kode_booking = ?');
        $st->execute([$kode]);
    } while ($st->fetchColumn());
    return $kode;
}

/* Simpan upload bukti pembayaran, kembalikan nama file atau lempar Exception */
function simpan_bukti(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Pilih file bukti pembayaran terlebih dahulu.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Ukuran file maksimal 2 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Format file harus JPG, PNG, atau PDF.');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'bukti_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }
    return $name;
}

/* ================= Tambahan untuk desain baru ================= */

/* Pengaturan situs (tabel `pengaturan`, lihat migrasi.sql). Aman jika tabel belum ada. */
function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT kunci, nilai FROM pengaturan') as $r) {
                $cache[$r['kunci']] = (string)$r['nilai'];
            }
        } catch (Throwable $e) {
            // tabel belum dibuat -> pakai default
        }
    }
    return ($cache[$key] ?? '') !== '' ? $cache[$key] : $default;
}

function kamar_punya_foto(): bool
{
    static $r = null;
    if ($r === null) {
        try {
            $r = (bool)db()->query("SHOW COLUMNS FROM kamar LIKE 'foto'")->fetch();
        } catch (Throwable $e) {
            $r = false;
        }
    }
    return $r;
}

function simpan_foto(array $file, string $prefix = 'kamar_'): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload foto gagal.');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 3 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $prefix . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan foto.');
    }
    return $name;
}

/* Rating bintang (mis. 4.7 -> ★★★★☆ 4.7). Kosong kalau belum ada rating. */
function stars($rating): string
{
    if ($rating === null || $rating === '') return '';
    $r = max(0.0, min(5.0, (float)$rating));
    $txt = number_format($r, 1, '.', '');
    return '<span class="rating">'
        . '<span class="stars" style="--rating:' . e($txt) . '" role="img" aria-label="Rating ' . e($txt) . ' dari 5"></span>'
        . '<span class="rating-num">' . e($txt) . '</span></span>';
}

/* Kotak foto kamar; kalau belum ada foto tampil placeholder bergambar rumah */
function foto_box(array $k, string $cls = ''): string
{
    $f = basename((string)($k['foto'] ?? ''));
    if ($f !== '' && is_file(__DIR__ . '/../uploads/' . $f)) {
        return '<div class="ph ' . e($cls) . '"><img src="' . e(url('uploads/' . rawurlencode($f))) . '" alt="Kamar ' . e($k['nomer_kamar']) . '" loading="lazy"></div>';
    }
    return '<div class="ph empty ' . e($cls) . '">'
        . '<svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 22 24 7l18 15"/><path d="M10 20v21h28V20"/><path d="M20 41V29h8v12"/></svg>'
        . '<span>Kamar ' . e($k['nomer_kamar']) . '</span></div>';
}

/* "kamar mandi dalam, AC" -> ['kamar mandi dalam','AC'] */
function fas_list(string $t): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[,\n;]+/', $t))));
}

function bulan_pendek(int $m): string
{
    return ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$m - 1];
}

function short_rp($v): string
{
    $v = (float)$v;
    if ($v >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . ' jt';
    if ($v >= 1000) return round($v / 1000) . ' rb';
    return (string)round($v);
}

/* Grafik batang SVG sederhana: ['Nov' => 1200000, ...] */
function bar_svg(array $data, int $h = 230): string
{
    $n = count($data);
    if (!$n) return '';
    $w = 640; $padB = 28; $padT = 24;
    $max = max(1, max($data));
    $slot = $w / $n;
    $bw = min(54, $slot * 0.56);
    $o = '<svg class="bars" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Grafik pemasukan">';
    for ($i = 0; $i <= 3; $i++) {
        $y = $padT + ($h - $padT - $padB) * $i / 3;
        $o .= '<line class="gl" x1="0" x2="' . $w . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>';
    }
    $i = 0;
    foreach ($data as $label => $v) {
        $bh = ($h - $padT - $padB) * ($v / $max);
        $x = $slot * $i + ($slot - $bw) / 2;
        $y = $h - $padB - $bh;
        $o .= '<rect class="bar" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round(max($bh, $v > 0 ? 2 : 0), 1) . '" rx="7"/>';
        if ($v > 0) {
            $o .= '<text class="val" x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 7, 1) . '" text-anchor="middle">' . e(short_rp($v)) . '</text>';
        }
        $o .= '<text class="lbl" x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e((string)$label) . '</text>';
        $i++;
    }
    return $o . '</svg>';
}

/* Logo situs (diunggah di Admin > Pengaturan). Kosong jika belum ada. */
function logo_html(): string
{
    $f = basename(setting('logo'));
    if ($f !== '' && is_file(__DIR__ . '/../uploads/' . $f)) {
        return '<img class="logo-img" src="' . e(url('uploads/' . rawurlencode($f))) . '" alt="Logo ' . e(setting('nama_kost', SITE_NAME)) . '">';
    }
    return '';
}
