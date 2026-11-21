<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Mosque;
use App\Support\DmiMosqueParser;
use App\Support\SchoolNameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ImportDmiMosques extends Command
{
    protected $signature = 'mosque:import
        {--province=6 : Kode provinsi DMI (6 = DKI Jakarta)}
        {--dry-run : Ambil dan parse data tanpa menulis ke database}
        {--max-pages=0 : Batasi jumlah halaman (0 = semua)}
        {--delay=0.5 : Jeda antar-request dalam detik}
        {--city= : Hanya proses baris yang menyebut kota/kabupaten ini (mis. Sumedang)}
        {--with-phone : Ikut menyimpan nomor telepon (default tidak, untuk mengurangi data pribadi)}';

    protected $description = 'Impor data masjid dari halaman publik DMI secara idempotent berdasarkan dmi_code';

    public function handle(): int
    {
        $province = (string) $this->option('province');
        $dryRun = (bool) $this->option('dry-run');
        $maxPages = max(0, (int) $this->option('max-pages'));
        $delay = max(0.0, (float) $this->option('delay'));
        $withPhone = (bool) $this->option('with-phone');
        $city = trim((string) $this->option('city'));

        $firstUrl = $this->pageUrl($province, 1);
        $firstResponse = $this->fetch($firstUrl);

        if ($firstResponse === null) {
            return self::FAILURE;
        }

        $total = $this->totalFromHtml($firstResponse);
        $pages = $total > 0 ? (int) ceil($total / 20) : 1;

        if ($maxPages > 0) {
            $pages = min($pages, $maxPages);
        }

        $this->info(sprintf(
            'DMI provinsi %s: %s masjid, %d halaman%s.',
            $province,
            $total > 0 ? number_format($total, 0, ',', '.') : 'tidak diketahui',
            $pages,
            $dryRun ? ' (dry-run)' : ''
        ));

        if ($city !== '') {
            $this->info(sprintf('Filter kota: hanya baris yang menyebut "%s".', $city));
        }

        $districts = District::query()
            ->get(['name', 'code'])
            ->sortByDesc(fn (District $district): int => strlen($district->name))
            ->values()
            ->all();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        if (! $dryRun) {
            $this->output->progressStart($pages);
        }

        for ($page = 1; $page <= $pages; $page++) {
            $html = $page === 1 ? $firstResponse : $this->fetch($this->pageUrl($province, $page));

            if ($html === null) {
                $failed++;

                if ($failed >= 3) {
                    $this->error('Terlalu banyak halaman gagal; impor dihentikan.');
                    break;
                }

                continue;
            }

            $rows = DmiMosqueParser::parse($html);

            if ($rows === [] && $page === 1) {
                $this->error('Tidak ada baris masjid yang bisa diparse. Struktur HTML DMI mungkin berubah.');

                return self::FAILURE;
            }

            if (! $dryRun) {
                [$pageCreated, $pageUpdated, $pageSkipped] = DB::transaction(
                    fn (): array => $this->persist($rows, $this->pageUrl($province, $page), $withPhone, $districts, $city)
                );

                $created += $pageCreated;
                $updated += $pageUpdated;
                $skipped += $pageSkipped;
                $this->output->progressAdvance();
            } else {
                $matching = $city === ''
                    ? $rows
                    : array_values(array_filter(
                        $rows,
                        fn (array $row): bool => $this->rowMatchesCity($row, $city)
                    ));

                $skipped += count($matching);
            }

            if ($page < $pages && $delay > 0) {
                usleep((int) round($delay * 1_000_000));
            }
        }

        if (! $dryRun) {
            $this->output->progressFinish();
        }

        $this->table(
            ['Hasil', 'Jumlah'],
            [
                ['Dibuat', $created],
                ['Diperbarui', $updated],
                [$dryRun ? 'Terbaca (dry-run)' : 'Dilewati', $skipped],
                ['Halaman gagal', $failed],
            ]
        );

        return self::SUCCESS;
    }

    private function pageUrl(string $province, int $page): string
    {
        return 'https://dmi.or.id/data-masjid/?'.http_build_query([
            'mdmi_prov' => $province,
            'mdmi_q' => '',
            'mdmi_page' => $page,
        ]);
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'SaveGoldenAge/1.0 (+https://savegoldenage.vercel.app)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(30)->get($url);
        } catch (Throwable $exception) {
            $this->error("Gagal mengambil {$url}: {$exception->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()} untuk {$url}");

            return null;
        }

        return $response->body();
    }

    private function totalFromHtml(string $html): int
    {
        if (! preg_match('/Menampilkan\s+\d+\s+dari\s+([\d.]+)\s+masjid/u', $html, $matches)) {
            return 0;
        }

        return (int) str_replace('.', '', $matches[1]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: int, 1: int, 2: int}
     */
    private function persist(array $rows, string $sourceUrl, bool $withPhone, array $districts, string $city = ''): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($city !== '' && ! $this->rowMatchesCity($row, $city)) {
                $skipped++;

                continue;
            }

            $code = $row['dmi_code'] ?? null;
            $name = $row['name'] ?? null;

            if (! is_string($code) || $code === '' || ! is_string($name) || $name === '') {
                $skipped++;

                continue;
            }

            $mosque = Mosque::query()->where('dmi_code', $code)->first();

            if ($mosque === null) {
                $mosque = $this->findDuplicate($name, $row['address'] ?? null);
            }

            $isNew = $mosque === null;
            $wasVerified = $isNew ? false : (bool) $mosque->is_verified;
            $mosque ??= new Mosque(['dmi_code' => $code]);

            $mosque->fill([
                'name' => $name,
                'address' => $row['address'],
                'village' => $row['village'],
                'district_name' => $this->resolveDistrictName($row, $districts),
                'city' => $row['city'],
                'province' => $row['province'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'source' => 'dmi',
                'source_url' => $sourceUrl,
                'last_synced_at' => now(),
            ]);

            if ($isNew || $withPhone) {
                $mosque->phone = $withPhone ? ($row['phone'] ?? null) : null;
            }

            if ($isNew) {
                $mosque->is_active = true;
                $mosque->is_verified = false;
            } elseif ($wasVerified && $mosque->isDirty([
                'name', 'address', 'village', 'district_name', 'city', 'province', 'latitude', 'longitude',
            ])) {
                // Perubahan data dari DMI membuat verifikasi lama tidak lagi berlaku.
                // Status aktif/nonaktif yang ditetapkan admin tetap dipertahankan.
                $mosque->is_verified = false;
            }

            $mosque->save();

            $isNew ? $created++ : $updated++;
        }

        return [$created, $updated, $skipped];
    }

    /**
     * Sebagian data DMI memuat satu masjid lebih dari sekali dengan kode
     * berbeda. Sebelum membuat baris baru, cari masjid DMI dengan nama dan
     * alamat yang sama agar katalog tidak berisi duplikat.
     */
    private function findDuplicate(string $name, ?string $address): ?Mosque
    {
        $needle = $this->normalizeAddress($address);

        if ($needle === '') {
            return null;
        }

        $normalizedName = SchoolNameNormalizer::normalize($name);

        if ($normalizedName === null) {
            return null;
        }

        return Mosque::query()
            ->where('source', 'dmi')
            ->where('name_normalized', $normalizedName)
            ->get()
            ->first(fn (Mosque $mosque): bool => $this->normalizeAddress($mosque->address) === $needle);
    }

    private function normalizeAddress(?string $address): string
    {
        return Str::of((string) $address)->squish()->lower()->toString();
    }

    /**
     * Sebagian baris DMI hanya memuat kota/provinsi, bukan kecamatan. Coba
     * temukan nama kecamatan yang dikenal dari alamat/kelurahan sebelum
     * menyerah ke fallback "Lainnya" di UI. Kandidat dibatasi ke kecamatan
     * di kota yang sama agar nama jalan tidak salah dipetakan.
     *
     * @param  array<int, District>  $districts
     */
    private function resolveDistrictName(array $row, array $districts): ?string
    {
        $district = $row['district_name'] ?? null;

        if (is_string($district) && trim($district) !== '') {
            return trim($district);
        }

        $haystack = $this->normalizeText(implode(' ', array_filter([
            $row['address'] ?? null,
            $row['village'] ?? null,
            $row['city'] ?? null,
            $row['province'] ?? null,
        ])));

        if ($haystack === '') {
            return null;
        }

        $cityPrefix = $this->resolveCityPrefix($haystack);

        foreach ($districts as $candidate) {
            // Bila kotanya dikenali (mis. Jakarta), batasi ke kecamatan di kota
            // itu agar nama jalan tidak salah petakan. Untuk wilayah lain
            // (mis. Sumedang) pencocokan langsung ke nama kecamatan, karena
            // peta kota di resolveCityPrefix hanya memuat DKI.
            if ($cityPrefix !== null && ! str_starts_with((string) $candidate->code, $cityPrefix)) {
                continue;
            }

            $needle = $this->normalizeText($candidate->name);

            if ($needle !== '' && str_contains(' '.$haystack.' ', ' '.$needle.' ')) {
                return $candidate->name;
            }
        }

        return null;
    }

    private function resolveCityPrefix(string $haystack): ?string
    {
        $cities = [
            'kepulauan seribu' => '3101',
            'kab adm kep seribu' => '3101',
            'jakarta selatan' => '3171',
            'jakarta timur' => '3172',
            'jakarta pusat' => '3173',
            'jakarta barat' => '3174',
            'jakarta utara' => '3175',
        ];

        foreach ($cities as $name => $prefix) {
            if (str_contains(' '.$haystack.' ', ' '.$name.' ')) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * DMI hanya menyediakan filter provinsi, jadi penyaringan kota dilakukan
     * setelah baris diparse. Nama masjid ikut diperiksa karena sebagian baris
     * hanya memuat alamat tanpa kabupaten.
     *
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesCity(array $row, string $city): bool
    {
        $haystack = $this->normalizeText(implode(' ', array_filter([
            $row['name'] ?? null,
            $row['address'] ?? null,
            $row['village'] ?? null,
            $row['district_name'] ?? null,
            $row['city'] ?? null,
            $row['province'] ?? null,
        ])));

        $needle = $this->normalizeText($city);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalizeText(?string $value): string
    {
        return trim((string) preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            Str::lower(Str::ascii((string) $value)),
        ));
    }
}
