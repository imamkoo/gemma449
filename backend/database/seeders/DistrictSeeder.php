<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DistrictSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = database_path('data/sumedang_districts.json');

        if (! file_exists($jsonPath)) {
            $this->command->error("DistrictSeeder: file not found — {$jsonPath}");

            return;
        }

        $districts = json_decode(file_get_contents($jsonPath), true);

        if (! is_array($districts)) {
            $this->command->error('DistrictSeeder: invalid JSON format.');

            return;
        }

        $this->command->info('Seeding 26 Kecamatan Kabupaten Sumedang dari data lokal...');

        foreach ($districts as $item) {
            if (empty($item['id']) || empty($item['name'])) {
                continue;
            }

            District::firstOrCreate(
                ['code' => $item['id']],
                [
                    'name' => trim($item['name']),
                    'slug' => Str::slug($item['name']),
                    'photo' => null,
                ]
            );
        }

        $count = District::count();
        $this->command->info("DistrictSeeder: {$count} kecamatan berhasil di-seed.");
    }
}
