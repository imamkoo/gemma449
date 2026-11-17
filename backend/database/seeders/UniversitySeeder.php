<?php

namespace Database\Seeders;

use App\Models\University;
use Illuminate\Database\Seeder;

class UniversitySeeder extends Seeder
{
    /**
     * GEMMA449 adalah program pendaftaran sekolah (SD/SMP/SMA) di
     * Kabupaten Sumedang — kategori universitas tidak diperlukan.
     */
    public function run(): void
    {
        $count = University::count();

        if ($count > 0) {
            $this->command->info("UniversitySeeder: {$count} data universitas sudah ada di database (dari seed sebelumnya). Tidak ada perubahan.");

            return;
        }

        $this->command->info('UniversitySeeder: GEMMA449 adalah program sekolah (bukan perguruan tinggi) — seeder ini dilewati. Jika suatu saat dibutuhkan, import data universitas dari file JSON lokal.');
    }
}
