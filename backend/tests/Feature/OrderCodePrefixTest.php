<?php

use App\Models\District;
use App\Services\RegistrationService;

/**
 * GEMMA449 dan JEJAKA500 berbagi satu akun Midtrans (satu PT). Midtrans menyimpan
 * riwayat `order_id` secara global, jadi `registration_code` (yang dipakai sebagai
 * `order_id`) wajib punya namespace per produk.
 */
function makePrefixDistrict(string $name, string $code): District
{
    return District::query()->create([
        'name' => $name,
        'code' => $code,
    ]);
}

it('prefixes the smart code with the product prefix', function () {
    $district = makePrefixDistrict('Jatinangor', '3211010');

    $registration = RegistrationService::register([
        'district_id' => $district->id,
        'education_level' => 'SMP',
        'edition' => 'reguler',
        'name' => 'Uji Prefix',
        'phone_number' => '6281000099901',
        'school_name' => 'SMPN 1 Jatinangor',
    ]);

    expect($registration->registration_code)
        ->toStartWith(RegistrationService::ORDER_CODE_PREFIX.'-3211010-REGULER-SMP-');
});

it('keeps the random tail as the last segment so existing lookups still resolve', function () {
    $district = makePrefixDistrict('Cimanggung', '3211020');

    $registration = RegistrationService::register([
        'district_id' => $district->id,
        'education_level' => 'SMA',
        'edition' => 'reguler',
        'name' => 'Uji Ekor',
        'phone_number' => '6281000099902',
        'school_name' => 'SMAN 1 Cimanggung',
    ]);

    $code = $registration->registration_code;
    $tail = substr(strrchr($code, '-'), 1);

    expect($tail)->toHaveLength(4)
        ->and(preg_match('/^[A-Z0-9]{4}$/', $tail))->toBe(1);
});
