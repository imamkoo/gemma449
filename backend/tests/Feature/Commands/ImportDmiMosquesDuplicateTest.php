<?php

use App\Models\Mosque;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $rows = [
        ['M-9501', 'Masjid Al Mukhlisin', 'Dsn. Cibeureum RT.01/02 Desa Cilopang', 'KAB. SUMEDANG, Jawa Barat'],
        ['M-9901', 'Masjid Al Mukhlisin', 'Dsn.   Cibeureum   RT.01/02 Desa Cilopang', 'KAB. SUMEDANG, Jawa Barat'],
    ];

    $body = '';
    foreach ($rows as [$code, $name, $address, $region]) {
        $body .= "<tr><td>{$code}</td><td>{$name}</td><td>{$address}</td><td>{$region}</td><td></td></tr>";
    }

    Http::fake([
        'https://dmi.or.id/*' => Http::response(
            '<html><body><table class="masjid-dmi-table"><tbody>'.$body.'</tbody></table>'
            .'<div>Menampilkan '.count($rows).' dari '.count($rows).' masjid</div></body></html>',
            200
        ),
    ]);
});

test('mosques duplicated under different dmi codes are stored once', function () {
    $this->artisan('mosque:import', [
        '--province' => '9',
        '--delay' => '0',
    ])->assertSuccessful();

    expect(Mosque::query()->count())->toBe(1)
        ->and(Mosque::query()->value('dmi_code'))->toBe('M-9501');
});
