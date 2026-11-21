<?php

use App\Models\Mosque;
use Illuminate\Support\Facades\Http;

/**
 * DMI hanya bisa difilter per provinsi, sedangkan GEMMA449 hanya butuh masjid
 * di Sumedang. Opsi --city menyaring setelah parse agar 12.664 masjid Jawa
 * Barat tidak masuk seluruhnya.
 *
 * @param  array<int, array{0: string, 1: string, 2: string, 3: string}>  $rows
 */
function dmiMosquePage(array $rows): string
{
    $body = '';
    foreach ($rows as [$code, $name, $address, $region]) {
        $body .= "<tr><td>{$code}</td><td>{$name}</td><td>{$address}</td><td>{$region}</td><td></td></tr>";
    }

    return '<html><body><table class="masjid-dmi-table"><tbody>'.$body.'</tbody></table>'
        .'<div>Menampilkan '.count($rows).' dari '.count($rows).' masjid</div></body></html>';
}

beforeEach(function () {
    Http::fake([
        'https://dmi.or.id/*' => Http::response(dmiMosquePage([
            ['M-9101', 'Masjid Al Ikhlas', 'Jl. Raya Sumedang No. 1', 'Sumedang, Jawa Barat'],
            ['M-9102', 'Masjid Al Falah', 'Jl. Ahmad Yani No. 2', 'Bekasi, Jawa Barat'],
            ['M-9103', 'Masjid Nurul Huda', 'Jl. Cimalaka No. 3', 'Cimalaka, Sumedang, Jawa Barat'],
        ]), 200),
    ]);
});

test('city filter only persists rows mentioning the requested city', function () {
    $this->artisan('mosque:import', [
        '--province' => '9',
        '--city' => 'Sumedang',
        '--delay' => '0',
    ])
        ->expectsOutputToContain('Filter kota')
        ->assertSuccessful();

    expect(Mosque::query()->pluck('dmi_code')->sort()->values()->all())
        ->toBe(['M-9101', 'M-9103']);
});

test('without city filter every parsed row is persisted', function () {
    $this->artisan('mosque:import', [
        '--province' => '9',
        '--delay' => '0',
    ])->assertSuccessful();

    expect(Mosque::query()->count())->toBe(3);
});

test('dry run with city filter writes nothing', function () {
    $this->artisan('mosque:import', [
        '--province' => '9',
        '--city' => 'Sumedang',
        '--dry-run' => true,
        '--delay' => '0',
    ])
        ->expectsOutputToContain('Terbaca (dry-run)')
        ->assertSuccessful();

    expect(Mosque::query()->count())->toBe(0);
});
