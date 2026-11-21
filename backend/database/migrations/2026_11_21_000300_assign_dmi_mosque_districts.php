<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Satu-satunya baris snapshot Sumedang yang alamatnya dipotong oleh DMI
     * ("KEC. SUMEDA…") sehingga kecamatan tidak dapat ditebak dari teks:
     * Masjid AL-FURQON, Jl. Pangeran Geusan Ulun, Kel. Regol Wetan.
     * Kelurahan Regol Wetan berada di Kecamatan Sumedang Selatan
     * (Kode Kemendagri 32.11.17), jadi kecamatannya ditetapkan eksplisit.
     */
    private const DISTRICT_OVERRIDES = [
        'M-46430' => 'Sumedang Selatan',
    ];

    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        foreach (self::DISTRICT_OVERRIDES as $code => $district) {
            DB::table('mosques')
                ->where('dmi_code', $code)
                ->update([
                    'district_name' => $district,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Sengaja no-op: penetapan kecamatan adalah koreksi data, bukan skema.
    }
};
