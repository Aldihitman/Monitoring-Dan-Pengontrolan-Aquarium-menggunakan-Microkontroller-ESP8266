<?php
/*
 * ALDIUM SISTEM - FILE PENGATURAN
 * Semua yang perlu Anda ubah secara manual ada di file ini.
 * Cukup edit nilai di sebelah kanan tanda =>
 */
return [

    // ---- Tampilan ----
    'nama_sistem'    => 'Aldium Sistem',
    'tagline'        => 'Smart Aquarium IoT',
    'nama_akuarium'  => 'Akuarium Molly',
    'timezone'       => 'Asia/Jakarta',
    'refresh_detik'  => 15,        // dashboard muat ulang otomatis tiap sekian detik (0 = mati)

    // ---- Database MySQL (bawaan XAMPP: user root tanpa password) ----
    // Database & tabel dibuat otomatis saat halaman pertama dibuka.
    'db_host' => '127.0.0.1',
    'db_nama' => 'aldium',
    'db_user' => 'root',
    'db_pass' => '',

    // ---- Login awal (hanya dipakai saat pertama kali dijalankan) ----
    // Setelah login pertama, sistem akan MEMAKSA Anda mengganti password ini.
    'admin_user'       => 'admin',
    'admin_pass_awal'  => 'admin123',
    'sesi_menit'       => 120,     // otomatis logout jika tidak aktif sekian menit
    'login_maks_gagal' => 5,       // salah password sekian kali => diblokir sementara
    'login_blokir_menit' => 10,

    // ---- Kunci rahasia untuk ESP8266 ----
    // WAJIB diganti! Isi dengan huruf/angka acak yang panjang (contoh: 24 karakter).
    // Kunci yang sama harus ditulis juga di kode ESP8266 (variabel API_KEY).
    'api_key'        => 'GANTI-DENGAN-KUNCI-RAHASIA',

    // ---- Pengiriman data dari ESP8266 ----
    'simpan_tiap_detik'     => 60,   // riwayat disimpan paling cepat tiap sekian detik
    'simpan_riwayat_hari'   => 30,   // data lebih lama dari ini dihapus otomatis
    'grafik_menit_per_titik'=> 30,   // 1 titik grafik = rata-rata sekian menit (12 titik)
    'perintah_kadaluarsa_menit' => 10, // perintah pakan dibatalkan jika ESP tidak mengambil
    'jadwal_toleransi_menit'    => 30, // pakan terjadwal dilewati jika ESP telat > sekian menit

    // ---- Nilai awal pengaturan (bisa diubah lewat menu Pengaturan di website) ----
    // Catatan: ini angka umum untuk ikan molly. Sesuaikan dengan kondisi ikan Anda.
    'default' => [
        'suhu_min'            => 24,
        'suhu_max'            => 28,
        'ph_min'              => 7.0,
        'ph_max'              => 8.5,
        'level_min'           => 60,    // persen
        'keruh_max'           => 30,    // NTU
        'jadwal_pakan'        => '07:00,17:00',
        'pakan_jeda_menit'    => 180,   // jarak minimal antar pakan manual
        'pakan_maks_per_hari' => 3,
        'offline_detik'       => 120,   // dianggap offline jika tidak kirim data selama ini
    ],
];
