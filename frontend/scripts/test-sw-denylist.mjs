import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

test('navigateFallbackDenylist excludes both /api/ and /admin routes from SPA fallback', () => {
  const content = readFileSync(new URL('../vite.config.ts', import.meta.url), 'utf-8');
  const match = content.match(/navigateFallbackDenylist:\s*(\[[^\]]+\])/);
  assert.ok(match, 'navigateFallbackDenylist should be defined in vite.config.ts');

  const patterns = [...match[1].matchAll(/\/((?:\\.|[^/])*)\/([a-z]*)/g)].map(
    ([, source, flags]) => new RegExp(source, flags)
  );
  assert.ok(patterns.length > 0, 'at least one denylist pattern should be configured');

  const shouldBeExcluded = [
    '/api',
    '/api/price-categories',
    '/api/register',
    '/api/midtrans/notification',
    '/admin',
    '/admin/login',
    '/admin/registrations',
    '/livewire',
    '/livewire/update',
    '/up',
  ];

  for (const path of shouldBeExcluded) {
    const isExcluded = patterns.some((re) => re.test(path));
    assert.ok(isExcluded, `expected ${path} to match navigateFallbackDenylist`);
  }

  const shouldFallbackToSpa = [
    '/',
    '/daftar',
    '/tentang',
    '/status',
    '/checkouts/abc',
    '/kategori',
  ];

  for (const path of shouldFallbackToSpa) {
    const isExcluded = patterns.some((re) => re.test(path));
    assert.ok(!isExcluded, `expected ${path} NOT to match navigateFallbackDenylist`);
  }
});
