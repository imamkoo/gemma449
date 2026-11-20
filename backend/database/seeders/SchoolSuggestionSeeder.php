<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\SchoolSuggestion;
use Illuminate\Database\Seeder;

class SchoolSuggestionSeeder extends Seeder
{
    /**
     * Seed sekolah dari data JSON lokal Sumedang (inventaris DOCX resmi).
     *
     * Sumber: folder "DATA SUMEDANG" — 26 file Inventaris_Sekolah_Kecamatan_*.docx
     * (plus Daftar_26_Kecamatan & Inventaris_Desa_Kelurahan). Hasil parse:
     * 858 satuan pendidikan, mencakup 26/26 kecamatan (808 ber-NPSN).
     * SLB tidak dimasukkan agar setara cakupan jejaka500 (API sd/smp/sma).
     *
     * Sifat idempoten: aman dijalankan berulang (mis. setiap container boot
     * saat RUN_SEEDERS=true) karena memakai firstOrCreate dan tidak pernah
     * menghapus data yang sudah ada.
     *
     * district_code di JSON = kode BPS kecamatan, sama dengan District.code.
     */
    public function run(): void
    {
        $jsonPath = database_path('data/sumedang_schools.json');

        if (! file_exists($jsonPath)) {
            $this->command?->error("SchoolSuggestionSeeder: file tidak ditemukan — {$jsonPath}");

            return;
        }

        $schools = json_decode((string) file_get_contents($jsonPath), true);

        if (! is_array($schools)) {
            $this->command?->error('SchoolSuggestionSeeder: format JSON tidak valid.');

            return;
        }

        $districtMap = District::query()->get(['id', 'code'])->keyBy('code');

        if ($districtMap->isEmpty()) {
            $this->command?->warn('SchoolSuggestionSeeder: belum ada kecamatan. Jalankan DistrictSeeder lebih dulu.');

            return;
        }

        $this->command?->info('Seeding sekolah dari data lokal Sumedang...');

        /** Jenjang madrasah & kejuruan dipetakan ke level setara yang dipakai form. */
        $levelMap = [
            'SD' => 'SD',
            'SMP' => 'SMP',
            'SMA' => 'SMA',
            'MI' => 'SD',
            'MTS' => 'SMP',
            'MA' => 'SMA',
            'SMK' => 'SMA',
        ];

        $created = 0;
        $skippedNoDistrict = 0;
        $skippedLevel = 0;

        foreach ($schools as $item) {
            if (! is_array($item)) {
                continue;
            }

            $districtCode = trim((string) ($item['district_code'] ?? ''));
            $rawLevel = strtoupper(trim((string) ($item['education_level'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));

            if ($districtCode === '' || $rawLevel === '' || $name === '') {
                continue;
            }

            $educationLevel = $levelMap[$rawLevel] ?? null;

            if ($educationLevel === null) {
                $skippedLevel++;

                continue;
            }

            $district = $districtMap->get($districtCode);

            if ($district === null) {
                $skippedNoDistrict++;

                continue;
            }

            $suggestion = SchoolSuggestion::firstOrCreate([
                'district_id' => $district->id,
                'education_level' => $educationLevel,
                'name' => $name,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        $total = SchoolSuggestion::count();

        $this->command?->info(
            "SchoolSuggestionSeeder: {$created} sekolah baru disimpan, total {$total} di database, "
            ."{$skippedNoDistrict} tanpa kecamatan cocok, {$skippedLevel} jenjang tidak dikenal diabaikan."
        );
    }
}
