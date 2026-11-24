# Web Kost (PHP + MySQL) — tema hijau & krem

## Cara pasang (XAMPP / Laragon)
1. Salin folder `kost` ke `htdocs` (XAMPP) atau `www` (Laragon).
2. phpMyAdmin → buat database `vikakostdb` → Import `database.sql`.
3. **Jalankan `migrasi.sql`** (tab SQL di database `vikakostdb`) → menambah kolom foto kamar, catatan booking, & tabel pengaturan.
4. Cek `inc/config.php` (default XAMPP: user `root`, password kosong).
5. Buka `http://localhost/kost/`.

## Akun bawaan
- Admin (`/login.php`): `nisrina` / `nisrina1234` atau `restiana` / `restiana1234`
- Penghuni (`/penghuni/login.php`): `nisrina@gmail.com` + `1234`, atau `restiana@gmail.com` + `456`

## Halaman (mengikuti desain Figma)
Publik: Beranda, Daftar Kost (filter), Detail Kamar, Booking (Pilih Kamar → Detail Pemesan), Cek Booking, Login.
Admin: Dashboard (grafik + hunian), Data Kamar (+foto), Booking, Penghuni, Sewa, Tagihan, Pembayaran, Laporan Pendapatan (+CSV), Pengaturan.
Penghuni: tagihan, upload bukti transfer, riwayat pembayaran.

> Fitur rating bintang: jalankan ulang `migrasi.sql` (menambah kolom `rating` di tabel `kamar`), lalu isi rating lewat Admin → Data Kamar.

> Ulasan: pembooking yang bookingnya *diterima* memberi bintang lewat Cek Booking (kode booking + No. HP). Rating kamar = rata-rata ulasan. Jalankan ulang `migrasi.sql` (tabel `ulasan`).
