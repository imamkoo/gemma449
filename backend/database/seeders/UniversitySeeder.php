<?php

namespace Database\Seeders;

use App\Models\University;
use Illuminate\Database\Seeder;

class UniversitySeeder extends Seeder
{
    /**
     * Seed perguruan tinggi di Kabupaten Sumedang dari data JSON lokal.
     *
     * Sumber: kampus yang berlokasi di Kabupaten Sumedang (mayoritas kawasan
     * Jatinangor plus Cimalaka dan pusat Sumedang). jejaka500 mengambil data
     * Hipolabs lalu memfilter kata kunci Jakarta; di sini API tersebut tidak
     * dipakai karena tidak menyediakan filter kabupaten dan hasilnya tidak
     * deterministik. Daftar lokal membuat seed dapat diulang tanpa jaringan.
     *
     * Sifat idempoten: memakai updateOrCreate berdasarkan nama, jadi aman
     * dijalankan berulang (mis. setiap container boot saat RUN_SEEDERS=true).
     */
    public function run(): void
    {
        $jsonPath = database_path('data/sumedang_universities.json');

        if (! file_exists($jsonPath)) {
            $this->command?->error("UniversitySeeder: file tidak ditemukan — {$jsonPath}");

            return;
        }

        $universities = json_decode((string) file_get_contents($jsonPath), true);

        if (! is_array($universities)) {
            $this->command?->error('UniversitySeeder: format JSON tidak valid.');

            return;
        }

        $created = 0;

        foreach ($universities as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $university = University::query()->updateOrCreate(
                ['name' => $name],
                [
                    'type' => strtoupper(trim((string) ($item['type'] ?? ''))) ?: null,
                    'city' => trim((string) ($item['city'] ?? '')) ?: 'Sumedang',
                ],
            );

            if ($university->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info("UniversitySeeder: {$created} universitas baru, total ".University::count().'.');
    }
}
