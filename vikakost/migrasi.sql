-- Migrasi untuk desain baru (aman dijalankan berulang kali)
-- Jalankan di phpMyAdmin pada database `vikakostdb` (tab SQL).

-- 1) Foto kamar
ALTER TABLE `kamar` ADD COLUMN IF NOT EXISTS `foto` varchar(255) DEFAULT NULL;

-- 2) Catatan tambahan booking
ALTER TABLE `booking` ADD COLUMN IF NOT EXISTS `catatan` text DEFAULT NULL;

-- 3) Pengaturan website
CREATE TABLE IF NOT EXISTS `pengaturan` (
  `kunci` varchar(50) NOT NULL,
  `nilai` text NOT NULL,
  PRIMARY KEY (`kunci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `pengaturan` (`kunci`, `nilai`) VALUES
('nama_kost', 'Vika Kost'),
('deskripsi', 'Kost nyaman, bersih, dan harga bersahabat. Booking online mudah dan cepat.'),
('alamat', ''),
('whatsapp', ''),
('email', ''),
('bank', ''),
('no_rekening', ''),
('atas_nama', '');


-- 4) Rating kamar (skala 0-5, boleh kosong)
ALTER TABLE `kamar` ADD COLUMN IF NOT EXISTS `rating` decimal(2,1) DEFAULT NULL;

-- (Opsional) isi rating contoh biar bintangnya langsung kelihatan. Hapus baris ini kalau tidak mau.
UPDATE `kamar` SET `rating` = 4.7 WHERE `rating` IS NULL;


-- 5) Ulasan dari pembooking (1 booking = 1 ulasan)
CREATE TABLE IF NOT EXISTS `ulasan` (
  `id_ulasan` int(11) NOT NULL AUTO_INCREMENT,
  `id_booking` int(11) NOT NULL,
  `id_kamar` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `rating` tinyint(1) NOT NULL,
  `komentar` text DEFAULT NULL,
  `tanggal` datetime NOT NULL,
  PRIMARY KEY (`id_ulasan`),
  UNIQUE KEY `uniq_booking` (`id_booking`),
  KEY `idx_kamar` (`id_kamar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
