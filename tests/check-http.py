#!/usr/bin/env python3
import csv
import io
import sys
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar

base = sys.argv[1].rstrip("/")
url = base + "/?wcmcf_feed=1"
if "--initial" in sys.argv:
    try:
        urllib.request.urlopen(url)
        raise AssertionError("Initial feed should return 503")
    except urllib.error.HTTPError as error:
        assert error.code == 503
        assert error.headers["Retry-After"] == "300"
        assert "no-store" in error.headers["Cache-Control"]
    print("Initial HTTP 503 and retry headers verified.")
    sys.exit(0)

with urllib.request.urlopen(url) as response:
    assert response.status == 200
    assert response.headers["Content-Type"].startswith("text/csv")
    assert "no-store" in response.headers["Cache-Control"]
    assert response.headers["X-Content-Type-Options"] == "nosniff"
    data = response.read()
    assert int(response.headers["Content-Length"]) == len(data)
    rows = list(csv.DictReader(io.StringIO(data.decode("utf-8"), newline="")))
    assert len(rows) > 100
    assert len({row["id"] for row in rows}) == len(rows)
    assert all(row["price"].endswith(" ALL") for row in rows)
    assert any("ë" in row["title"] for row in rows)
with urllib.request.urlopen(urllib.request.Request(url, method="HEAD")) as response:
    assert response.status == 200
    assert response.read() == b""
    assert int(response.headers["Content-Length"]) == len(data)
try:
    urllib.request.urlopen(urllib.request.Request(url, method="POST"))
    raise AssertionError("POST should be rejected")
except urllib.error.HTTPError as error:
    assert error.code == 405
    assert error.headers["Allow"] == "GET, HEAD"
try:
    urllib.request.urlopen(base + "/wp-content/uploads/wcmcf-feed/")
    raise AssertionError("Working artifact directory must not be publicly browsable")
except urllib.error.HTTPError as error:
    assert error.code == 403

# Authenticated forms have real WordPress nonces, native labels, and working refresh.
cookies = CookieJar()
browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
browser.open(base + "/wp-login.php").read()
login = urllib.parse.urlencode({"log": "wcmcf-admin", "pwd": "wcmcf-local-test-only", "wp-submit": "Log In", "testcookie": "1"}).encode()
browser.open(urllib.request.Request(base + "/wp-login.php", data=login)).read()
page_url = base + "/wp-admin/admin.php?page=wcmcf"
html = browser.open(page_url).read().decode()
assert 'id="wcmcf-feed-url"' in html
assert 'for="wcmcf-brand"' in html
assert 'name="action" value="wcmcf_refresh"' in html
assert html.count('<section class="wcmcf-card"') == 1
assert 'Europe/Tirane' in html
assert 'id="wcmcf-selection-type"' in html
assert 'wc-enhanced-select' in html and 'assets/admin.js' in html
assert 'wcmcf-select2-css' in html and '/assets/css/select2.css' in html
import re
forms = re.findall(r"<form\b.*?</form>", html, re.S)
refresh = next(form for form in forms if 'value="wcmcf_refresh"' in form)
assert 'name="renew_images"' in refresh and 'aria-describedby="wcmcf-renew-help"' in refresh
assert not re.search(r'name="renew_images"[^>]*\bchecked\b', refresh)
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', refresh).group(1)
payload = urllib.parse.urlencode({"action": "wcmcf_refresh", "_wpnonce": nonce}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Feed refresh queued" in result
payload = urllib.parse.urlencode({"action": "wcmcf_refresh", "_wpnonce": nonce, "renew_images": "1"}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Feed refresh queued" in result
try:
    browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=urllib.parse.urlencode(
        {"action": "wcmcf_refresh", "_wpnonce": "invalid", "renew_images": "1"}).encode()))
    raise AssertionError("Image renewal must reject an invalid nonce")
except urllib.error.HTTPError as error:
    assert error.code == 403
save = next(form for form in forms if 'value="wcmcf_save"' in form)
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', save).group(1)
# WooCommerce's native protected product search supplies the name/SKU selector.
search_nonce = re.search(r'"search_products_nonce":"([^"]+)"', html).group(1)
search_url = base + "/wp-admin/admin-ajax.php?" + urllib.parse.urlencode({
    "action": "woocommerce_json_search_products_and_variations", "security": search_nonce,
    "term": "Selection Search Product", "exclude_type": "grouped,external"})
import json
search_results = json.loads(browser.open(search_url).read())
assert any("Selection Search Product" in name for name in search_results.values())
product_id = next(key for key, name in search_results.items() if "Selection Search Product" in name)
payload = urllib.parse.urlencode({"action": "wcmcf_save", "_wpnonce": nonce,
    "selection_type": "products", "products[]": product_id, "fallback_brand": "BlissPharma"}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Settings saved" in result and 'value="products"  selected=' in result
# Empty targeted selections return a useful error rather than exporting every product.
payload = urllib.parse.urlencode({"action": "wcmcf_save", "_wpnonce": nonce,
    "selection_type": "products", "fallback_brand": "BlissPharma"}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Search for and select at least one product" in result and 'aria-invalid="true"' in result
# Restore all products before the real scheduler run.
payload = urllib.parse.urlencode({"action": "wcmcf_save", "_wpnonce": nonce,
    "selection_type": "all", "fallback_brand": 'BlissPharma "Albania"'}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Settings saved" in result
payload = urllib.parse.urlencode({"action": "wcmcf_save", "_wpnonce": nonce, "fallback_brand": 'BlissPharma "Albania"'}).encode()
result = browser.open(urllib.request.Request(base + "/wp-admin/admin-post.php", data=payload)).read().decode()
assert "Settings saved" in result
assert "BlissPharma &quot;Albania&quot;" in result
print(f"HTTP feed ({len(rows)} items), HEAD, method restriction, admin forms, native product search, selection save, and validation verified.")
