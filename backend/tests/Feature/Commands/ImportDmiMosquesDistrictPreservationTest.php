<?php

use App\Models\Mosque;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'https://dmi.or.id/*' => Http::response(
            '<html><body><table class="masjid-dmi-table"><tbody>'
            .'<tr><td>M-9701</td><td>Masjid Al Hidayah</td><td>Jl. Tanpa Nama Kecamatan No. 7</td>'
            .'<td>KAB. SUMEDANG, Jawa Barat</td><td></td></tr>'
            .'</tbody></table>'
            .'<div>Menampilkan 1 dari 1 masjid</div></body></html>',
            200
        ),
    ]);
});

test('an existing district is kept when the DMI row cannot be mapped to one', function () {
    Mosque::query()->create([
        'dmi_code' => 'M-9701',
        'name' => 'Masjid Al Hidayah',
        'address' => 'Jl. Tanpa Nama Kecamatan No. 7',
        'district_name' => 'Cimanggung',
        'city' => 'KAB. SUMEDANG',
        'province' => 'Jawa Barat',
        'source' => 'dmi',
    ]);

    $this->artisan('mosque:import', [
        '--province' => '9',
        '--delay' => '0',
    ])->assertSuccessful();

    expect(Mosque::query()->where('dmi_code', 'M-9701')->value('district_name'))
        ->toBe('Cimanggung');
});
