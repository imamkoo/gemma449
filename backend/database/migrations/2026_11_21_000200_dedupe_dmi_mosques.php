<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Sumber DMI Jawa Barat memuat sebagian masjid dua sampai tiga kali dengan
     * kode berbeda (mis. M-51295 dan M-55174 untuk masjid, alamat, dan
     * kecamatan yang sama). Snapshot sudah digabung, tetapi baris yang sudah
     * terlanjur masuk lewat impor sebelumnya perlu dinonaktifkan agar tidak
     * muncul ganda di pilihan masjid saat pendaftaran.
     *
     * Baris tidak dihapus: pendaftaran yang sudah memakai baris duplikat tetap
     * utuh, dan admin dapat mengaktifkan kembali bila ternyata bukan duplikat.
     */
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $mosques = DB::table('mosques')
            ->select('id', 'dmi_code', 'name_normalized', 'address')
            ->where('source', 'dmi')
            ->get()
            ->sortBy(fn (object $mosque): int => $this->codeNumber($mosque->dmi_code))
            ->values();

        $seen = [];
        $duplicateIds = [];

        foreach ($mosques as $mosque) {
            $name = trim(mb_strtolower((string) $mosque->name_normalized));
            $address = Str::of((string) $mosque->address)->squish()->lower()->toString();

            if ($name === '' || $address === '') {
                continue;
            }

            $key = $name.'|'.$address;

            if (isset($seen[$key])) {
                $duplicateIds[] = $mosque->id;

                continue;
            }

            $seen[$key] = $mosque->id;
        }

        foreach (array_chunk($duplicateIds, 500) as $chunk) {
            DB::table('mosques')
                ->whereIn('id', $chunk)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    private function codeNumber(?string $code): int
    {
        return preg_match('/(\d+)/', (string) $code, $matches) === 1
            ? (int) $matches[1]
            : PHP_INT_MAX;
    }

    public function down(): void
    {
        // Sengaja no-op: pengaktifan kembali masjid nonaktif adalah keputusan admin.
    }
};
