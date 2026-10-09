/* Isolated, headless test browser; never uses the user's browser profile. */
const assert = require('node:assert/strict');
const { chromium } = require(process.env.WCMCF_PLAYWRIGHT_MODULE || 'playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: process.env.WCMCF_BROWSER_PATH || undefined });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const base = process.argv[2];
  await page.goto(base + '/wp-login.php');
  await page.locator('#user_login').fill('wcmcf-admin');
  await page.locator('#user_pass').fill('wcmcf-local-test-only');
  await page.locator('#wp-submit').click();
  await page.goto(base + '/wp-admin/admin.php?page=wcmcf');
  await page.locator('.wcmcf .select2-container').first().waitFor({ state: 'attached' });
  assert.equal(await page.locator('.wcmcf-card').count(), 1);
  for (const mode of ['products', 'categories', 'tags', 'brands', 'all']) {
    await page.locator('#wcmcf-selection-type').selectOption(mode);
    for (const other of ['products', 'categories', 'tags', 'brands', 'all']) {
      assert.equal(await page.locator('[data-mode="' + other + '"]').isVisible(), mode === other);
    }
  }
  await page.locator('#wcmcf-selection-type').selectOption('products');
  const search = page.locator('[data-mode="products"] .select2-search__field');
  assert.equal(await search.getAttribute('aria-labelledby'), 'wcmcf-label-products');
  await search.click();
  await search.pressSequentially('Selection Search Product', { delay: 15 });
  const result = page.locator('.select2-results__option').filter({ hasText: 'Selection Search Product' }).first();
  await result.waitFor();
  await result.click();
  assert.match(await page.locator('[data-mode="products"] .select2-selection__choice').innerText(), /Selection Search Product/);
  await page.locator('.wcmcf-advanced summary').click();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: 'tests/.runtime/admin-desktop.png', fullPage: true });
  await page.screenshot({ path: 'tests/.runtime/admin-desktop-viewport.png' });
  await page.locator('#wcmcf-selection-type').selectOption('categories');
  await page.locator('[data-mode="categories"] .select2-search__field').click();
  await page.locator('[data-mode="categories"] .select2-search__field').pressSequentially('Selection parent', { delay: 15 });
  await page.locator('.select2-results__option').filter({ hasText: /^Selection parent$/ }).first().click();
  assert.match(await page.locator('[data-mode="categories"] .select2-selection__choice').innerText(), /Selection parent/);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: 'tests/.runtime/admin-mobile.png', fullPage: true });
  await page.screenshot({ path: 'tests/.runtime/admin-mobile-viewport.png' });
  assert.equal(await page.locator('.wcmcf-card').evaluate(el => el.scrollWidth <= el.clientWidth), true);
  // Leave persisted settings untouched; the HTTP suite tests actual form submissions.
  assert.deepEqual(errors, []);
  await browser.close();
  console.log('Headless admin UI: dropdown, product search, category selection, labels, and mobile overflow verified.');
})().catch(error => { console.error(error); process.exit(1); });
