<?php

use App\Models\University;
use Database\Seeders\UniversitySeeder;

it('seeds Sumedang universities from the local dataset', function () {
    expect(University::count())->toBe(0);

    $this->seed(UniversitySeeder::class);

    $unpad = University::where('name', 'Universitas Padjadjaran')->first();

    expect(University::count())->toBeGreaterThanOrEqual(8);
    expect($unpad)->not->toBeNull();
    expect($unpad->type)->toBe('PTN');
    expect($unpad->city)->toBe('Sumedang');
});

it('is idempotent when run twice', function () {
    $this->seed(UniversitySeeder::class);
    $firstCount = University::count();

    $this->seed(UniversitySeeder::class);

    expect(University::count())->toBe($firstCount);
});

it('keeps one row per name and restores canonical fields on re-run', function () {
    University::create([
        'name' => 'Universitas Padjadjaran',
        'type' => 'PTS',
        'city' => 'Jatinangor',
    ]);

    $this->seed(UniversitySeeder::class);

    $records = University::where('name', 'Universitas Padjadjaran')->get();

    expect($records)->toHaveCount(1);
    expect($records->first()->type)->toBe('PTN');
    expect($records->first()->city)->toBe('Sumedang');
});
