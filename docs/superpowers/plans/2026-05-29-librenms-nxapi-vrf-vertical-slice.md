# LibreNMS API Data-Source — Milestone 1 (NX-OS VRF Vertical Slice) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `\ApiQuery` HTTP/REST data-source (parallel to `\SnmpQuery`), encrypted per-device API credentials, an NX-API fallback that discovers SNMP-invisible NX-OS VRFs, and a composable device-scoped VRF dashboard widget.

**Architecture:** A new `LibreNMS\Data\Source\ApiQuery` (interface + concrete `HttpApiQuery` + `ApiResponse`) built on `LibreNMS\Util\Http::client()`, fronted by an `\ApiQuery` facade. Credentials are encrypted columns on `devices`. The existing `includes/discovery/vrf.inc.php` NX-OS branch gains an NX-API fallback that reuses its existing `create()/update()` + `$valid_vrf` persistence. Display is a `DeviceVrfsController` dashboard widget cloned from `HealthSensorsController`.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, PHPUnit 10.5, Laravel HTTP client, RRD/legacy procedural includes.

**Approved spec:** `docs/superpowers/specs/2026-05-29-librenms-api-data-source-design.md` (decisions locked in §9).

---

## Conventions every task must follow

- **License header:** every new `.php` file starts with the GPL docblock used across the repo. Template (copy verbatim, change only the filename line, the one-line description, and the copyright year/author). Note the codebase quirk `PURPOSE.See` (no space) — reproduce it exactly so php-cs-fixer doesn't rewrite it:

```php
<?php

/**
 * <FILENAME>.php
 *
 * <one-line description>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS Contributors
 * @author     LibreNMS Contributors
 */
```

- **Style:** short array syntax, single quotes, alpha-ordered imports, one blank line after namespace, trailing comma in multiline arrays. Auto-fix with `./lnms dev:check style` (changed files) before each commit.
- **Run a single unit test:** `vendor/bin/phpunit --filter='<TestClass>::<method>' <path>` (or `php artisan test --filter=...`). Run the whole unit suite with `./lnms dev:check unit`.
- **Commit cadence:** commit after each task's tests pass. Commit messages use Conventional Commits (`feat:`, `test:`, `fix:`).
- **Branch:** work on `nxos-vrf-discovery` (current) or a new `nxapi-vrf` branch off it. Do NOT push or open a PR unless explicitly asked.

### Deviations from the spec (intentional, YAGNI)
- **No `Keyable` / `syncModels` in M1.** The NX-OS branch persists via `create()/update()` + the `$valid_vrf` cleanup array, not `syncModels`. Adding `Keyable` now would be dead code; it is deferred to the future modern `Modules/Vrf.php` promotion.
- **`$hidden` is limited to the two NEW secrets** (`api_password`, `api_token`). SNMP creds are deliberately NOT added to `$hidden` (would break legacy `$device['authpass']` array reads).
- **Credential UI extends the legacy `edit/snmp.inc.php`** because SNMP-credential editing has no modern Blade/controller path today (the native `EditDeviceController`/`UpdateDeviceRequest` don't handle creds). A native Blade is out of scope for M1.

---

# Phase 0 — `\ApiQuery` transport (pure addition, independently mergeable)

### Task 1: `ApiResponse` value object (TDD)

**Files:**
- Test: `tests/Unit/Data/Source/ApiResponseTest.php`
- Create: `LibreNMS/Data/Source/ApiResponse.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Data/Source/ApiResponseTest.php`:

```php
<?php

/**
 * ApiResponseTest.php
 *
 * Tests for the HTTP/REST data-source response object.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2026 LibreNMS Contributors
 * @author     LibreNMS Contributors
 */

namespace LibreNMS\Tests\Unit\Data\Source;

use LibreNMS\Data\Source\ApiResponse;
use LibreNMS\Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    public function testValidJsonIsValid(): void
    {
        $response = new ApiResponse(200, ['result' => ['body' => ['ok' => true]]]);

        $this->assertTrue($response->isValid());
        $this->assertSame('', $response->getErrorMessage());
        $this->assertSame(['result' => ['body' => ['ok' => true]]], $response->json());
    }

    public function testHttpErrorIsInvalidWithMessage(): void
    {
        $response = new ApiResponse(401, null, 'Unauthorized');

        $this->assertFalse($response->isValid());
        $this->assertStringContainsString('401', $response->getErrorMessage());
    }

    public function testTransportErrorIsInvalid(): void
    {
        $response = ApiResponse::transportError('Connection timed out');

        $this->assertFalse($response->isValid());
        $this->assertSame('Connection timed out', $response->getErrorMessage());
        $this->assertTrue($response->isTransportError());
    }

    public function testJsonRpcErrorBodyIsInvalid(): void
    {
        // NX-API returns HTTP 200 with a JSON-RPC error object
        $response = new ApiResponse(200, ['error' => ['code' => -32602, 'message' => 'Invalid params']]);

        $this->assertFalse($response->isValid());
        $this->assertStringContainsString('Invalid params', $response->getErrorMessage());
    }

    public function testTableNormalizesListOfRows(): void
    {
        $body = ['result' => ['body' => ['TABLE_vrf' => ['ROW_vrf' => [
            ['vrf_name' => 'default'],
            ['vrf_name' => 'PROD'],
        ]]]]];
        $response = new ApiResponse(200, $body);

        $rows = $response->table('result', 'body', 'TABLE_vrf', 'ROW_vrf');

        $this->assertCount(2, $rows);
        $this->assertSame('PROD', $rows[1]['vrf_name']);
    }

    public function testTableWrapsSingleRowObject(): void
    {
        // NX-OS emits a bare object (not a list) when there is exactly one row
        $body = ['result' => ['body' => ['TABLE_vrf' => ['ROW_vrf' => ['vrf_name' => 'default']]]]];
        $response = new ApiResponse(200, $body);

        $rows = $response->table('result', 'body', 'TABLE_vrf', 'ROW_vrf');

        $this->assertCount(1, $rows);
        $this->assertSame('default', $rows[0]['vrf_name']);
    }

    public function testTableMissingPathReturnsEmpty(): void
    {
        $response = new ApiResponse(200, ['result' => ['body' => []]]);

        $this->assertSame([], $response->table('result', 'body', 'TABLE_vrf', 'ROW_vrf'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Data/Source/ApiResponseTest.php`
Expected: FAIL — `Class "LibreNMS\Data\Source\ApiResponse" not found`.

- [ ] **Step 3: Write the `ApiResponse` class**

Create `LibreNMS/Data/Source/ApiResponse.php` (license header from Conventions, description `Responsible for normalizing HTTP/REST API responses into usable PHP data structures.`):

```php
namespace LibreNMS\Data\Source;

use Illuminate\Support\Arr;

class ApiResponse
{
    private ?string $errorMessage = null;

    /**
     * @param  int  $status  HTTP status code (0 for transport errors)
     * @param  array<mixed>|null  $body  Decoded JSON body
     * @param  string  $reason  HTTP reason phrase or error text
     * @param  bool  $transportError  True if the request never completed (connection/timeout)
     */
    public function __construct(
        public readonly int $status = 200,
        private readonly ?array $body = null,
        private readonly string $reason = '',
        private readonly bool $transportError = false,
    ) {
    }

    public static function transportError(string $message): self
    {
        return new self(0, null, $message, true);
    }

    public function isTransportError(): bool
    {
        return $this->transportError;
    }

    public function isValid(): bool
    {
        $this->errorMessage = '';

        if ($this->transportError) {
            $this->errorMessage = $this->reason ?: 'Transport error';

            return false;
        }

        if ($this->status < 200 || $this->status >= 300) {
            $this->errorMessage = trim("HTTP $this->status $this->reason");

            return false;
        }

        if ($this->body === null) {
            $this->errorMessage = 'Empty or non-JSON response body';

            return false;
        }

        // JSON-RPC error: HTTP 200 with an {"error": {...}} object
        if (isset($this->body['error'])) {
            $err = $this->body['error'];
            $this->errorMessage = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string) $err;

            return false;
        }

        return true;
    }

    public function getErrorMessage(): string
    {
        if ($this->errorMessage === null) {
            $this->isValid();
        }

        return (string) $this->errorMessage;
    }

    /**
     * Get the full decoded body.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        return $this->body ?? [];
    }

    /**
     * Descend the decoded body by the given keys and return a normalized LIST of row arrays.
     * A single associative row (common in NX-API when there is one result) is wrapped in a list.
     *
     * @return array<int, array<mixed>>
     */
    public function table(string ...$keys): array
    {
        $node = $this->body ?? [];
        foreach ($keys as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return [];
            }
            $node = $node[$key];
        }

        if (! is_array($node) || $node === []) {
            return [];
        }

        // list of rows -> return as-is; single assoc row -> wrap
        return array_is_list($node) ? $node : [$node];
    }

    /**
     * Pluck a single column from table rows.
     *
     * @return array<int, mixed>
     */
    public function pluck(string $column, string ...$keys): array
    {
        return Arr::pluck($this->table(...$keys), $column);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Data/Source/ApiResponseTest.php`
Expected: PASS (7 tests). Then `./lnms dev:check style`.

- [ ] **Step 5: Commit**

```bash
git add LibreNMS/Data/Source/ApiResponse.php tests/Unit/Data/Source/ApiResponseTest.php
git commit -m "feat(data-source): add ApiResponse normalizing HTTP/REST responses"
```

---

### Task 2: `ApiQueryInterface`

**Files:**
- Create: `LibreNMS/Data/Source/ApiQueryInterface.php`

- [ ] **Step 1: Write the interface**

Create `LibreNMS/Data/Source/ApiQueryInterface.php` (license header, description `Contract for the HTTP/REST API data source, parallel to SnmpQueryInterface.`):

```php
namespace LibreNMS\Data\Source;

use App\Models\Device;

interface ApiQueryInterface
{
    /**
     * Start a new instance.
     */
    public static function make(): ApiQueryInterface;

    /**
     * Specify the device to query. Defaults to the primary device.
     */
    public function device(Device $device): ApiQueryInterface;

    /**
     * Cache the result for the rest of the runtime.
     */
    public function cache(): ApiQueryInterface;

    /**
     * HTTP GET the given path.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): ApiResponse;

    /**
     * HTTP POST the given path with a JSON body.
     *
     * @param  array<mixed>  $body
     */
    public function post(string $path, array $body = []): ApiResponse;

    /**
     * Run a device CLI command via a JSON-RPC API (e.g. Cisco NX-API ins-api).
     */
    public function cli(string $command): ApiResponse;
}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l LibreNMS/Data/Source/ApiQueryInterface.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
./lnms dev:check style
git add LibreNMS/Data/Source/ApiQueryInterface.php
git commit -m "feat(data-source): add ApiQueryInterface contract"
```

---

### Task 3: `HttpApiQuery` concrete transport (TDD)

**Files:**
- Test: `tests/Unit/Data/Source/HttpApiQueryTest.php`
- Create: `LibreNMS/Data/Source/HttpApiQuery.php`

Note: Laravel's `Http::fake()` works on the facade. `LibreNMS\Util\Http::client()` calls `LaravelHttp::withOptions(...)`, so `Http::fake()` intercepts it. The test fakes responses and asserts request shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Data/Source/HttpApiQueryTest.php` (license header):

```php
namespace LibreNMS\Tests\Unit\Data\Source;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use LibreNMS\Data\Source\ApiResponse;
use LibreNMS\Data\Source\HttpApiQuery;
use LibreNMS\Tests\TestCase;

final class HttpApiQueryTest extends TestCase
{
    private function device(array $attrs = []): Device
    {
        return new Device(array_merge([
            'hostname' => 'switch.example.com',
            'api_transport' => 'https',
            'api_port' => 443,
            'api_username' => 'svc',
            'api_password' => 'secret',
            'api_verify_tls' => false,
        ], $attrs));
    }

    public function testCliPostsNxApiEnvelopeAndReturnsValidResponse(): void
    {
        Http::fake([
            'https://switch.example.com:443/ins' => Http::response([
                'result' => ['body' => ['TABLE_vrf' => ['ROW_vrf' => [['vrf_name' => 'default']]]]],
            ], 200),
        ]);

        $response = HttpApiQuery::make()->device($this->device())->cli('show vrf');

        $this->assertInstanceOf(ApiResponse::class, $response);
        $this->assertTrue($response->isValid());
        $this->assertSame('default', $response->table('result', 'body', 'TABLE_vrf', 'ROW_vrf')[0]['vrf_name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://switch.example.com:443/ins'
                && $request['method'] === 'cli'
                && $request['params']['cmd'] === 'show vrf';
        });
    }

    public function testHttpErrorBecomesInvalidResponse(): void
    {
        Http::fake(['*' => Http::response('nope', 403)]);

        $response = HttpApiQuery::make()->device($this->device())->get('/api/data');

        $this->assertFalse($response->isValid());
        $this->assertStringContainsString('403', $response->getErrorMessage());
    }

    public function testConnectionExceptionBecomesTransportError(): void
    {
        Http::fake(function (): void {
            throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host');
        });

        $response = HttpApiQuery::make()->device($this->device())->get('/api/data');

        $this->assertFalse($response->isValid());
        $this->assertTrue($response->isTransportError());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Data/Source/HttpApiQueryTest.php`
Expected: FAIL — `Class "LibreNMS\Data\Source\HttpApiQuery" not found`.

- [ ] **Step 3: Write the `HttpApiQuery` class**

Create `LibreNMS/Data/Source/HttpApiQuery.php` (license header, description `HTTP/REST API data source. The transport-level peer of NetSnmpQuery.`):

```php
namespace LibreNMS\Data\Source;

use App\Models\Device;
use DeviceCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use LibreNMS\Util\Http;
use Log;

class HttpApiQuery implements ApiQueryInterface
{
    private Device $device;
    private bool $cache = false;

    public function __construct()
    {
        $this->device = DeviceCache::getPrimary();
    }

    public static function make(): ApiQueryInterface
    {
        return new static;
    }

    public function device(Device $device): ApiQueryInterface
    {
        $this->device = $device;

        return $this;
    }

    public function cache(): ApiQueryInterface
    {
        $this->cache = true;

        return $this;
    }

    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->execute('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $body = []): ApiResponse
    {
        return $this->execute('POST', $path, ['json' => $body]);
    }

    public function cli(string $command): ApiResponse
    {
        // Cisco NX-API ins-api JSON-RPC envelope
        $body = [
            'jsonrpc' => '2.0',
            'method' => 'cli',
            'params' => ['cmd' => $command, 'version' => 1],
            'id' => 1,
        ];

        return $this->execute('POST', '/ins', ['json' => $body]);
    }

    /**
     * @param  array{query?: array<string,mixed>, json?: array<mixed>}  $options
     */
    private function execute(string $method, string $path, array $options): ApiResponse
    {
        $driver = $this->cache ? 'array' : 'null';
        $key = $this->cache ? $this->cacheKey($method, $path, $options) : '';

        return Cache::driver($driver)->rememberForever($key, function () use ($method, $path, $options) {
            try {
                $client = $this->buildClient();
                $response = match ($method) {
                    'GET' => $client->get($path, $options['query'] ?? []),
                    'POST' => $client->post($path, $options['json'] ?? []),
                    default => $client->send($method, $path),
                };

                return new ApiResponse($response->status(), $response->json(), $response->reason());
            } catch (ConnectionException $e) {
                Log::debug('API query connection failure: ' . $e->getMessage());

                return ApiResponse::transportError($e->getMessage());
            } catch (RequestException $e) {
                return new ApiResponse($e->response?->status() ?? 0, null, $e->getMessage());
            }
        });
    }

    private function buildClient(): PendingRequest
    {
        $transport = $this->device->api_transport ?: 'https';
        $host = $this->device->api_host ?: $this->device->hostname;
        $port = $this->device->api_port ?: 443;

        $client = Http::client()->baseUrl("$transport://$host:$port");

        if ($this->device->timeout) {
            $client->timeout($this->device->timeout);
        }

        if (! $this->device->api_verify_tls) {
            $client->withoutVerifying();
        }

        if ($this->device->api_token) {
            $client->withToken($this->device->api_token);
        } elseif ($this->device->api_username) {
            $client->withBasicAuth($this->device->api_username, (string) $this->device->api_password);
        }

        return $client;
    }

    /**
     * @param  array<mixed>  $options
     */
    private function cacheKey(string $method, string $path, array $options): string
    {
        // Note: do not include credentials in the cache key.
        return implode('|', [$this->device->device_id, $method, $path, md5(json_encode($options))]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Data/Source/HttpApiQueryTest.php`
Expected: PASS (3 tests). Then `./lnms dev:check style`.

- [ ] **Step 5: Commit**

```bash
git add LibreNMS/Data/Source/HttpApiQuery.php tests/Unit/Data/Source/HttpApiQueryTest.php
git commit -m "feat(data-source): add HttpApiQuery transport on Http::client()"
```

---

### Task 4: `\ApiQuery` facade + alias

**Files:**
- Create: `app/Facades/FacadeAccessorApi.php`
- Modify: `config/app.php:25` (aliases merge block)

- [ ] **Step 1: Create the facade accessor**

Create `app/Facades/FacadeAccessorApi.php` (license header, description `Facade accessor resolving a fresh HttpApiQuery, parallel to FacadeAccessorSnmp.`):

```php
namespace App\Facades;

use Illuminate\Support\Facades\Facade;
use LibreNMS\Data\Source\HttpApiQuery;

class FacadeAccessorApi extends Facade
{
    protected static function getFacadeAccessor()
    {
        // always resolve a new instance
        self::clearResolvedInstance(HttpApiQuery::class);

        return HttpApiQuery::class;
    }
}
```

- [ ] **Step 2: Register the alias**

In `config/app.php`, add the `ApiQuery` line to the `aliases` merge block immediately after the `SnmpQuery` line (currently line 25):

```php
        'SnmpQuery' => App\Facades\FacadeAccessorSnmp::class,
        'ApiQuery' => App\Facades\FacadeAccessorApi::class,
        'LibrenmsConfig' => App\Facades\LibrenmsConfig::class,
```

- [ ] **Step 3: Clear cached config and verify the alias resolves**

Run:
```bash
lnms config:clear
php artisan tinker --execute="echo get_class(\ApiQuery::make());"
```
Expected: `LibreNMS\Data\Source\HttpApiQuery`.

- [ ] **Step 4: Commit**

```bash
./lnms dev:check style
git add app/Facades/FacadeAccessorApi.php config/app.php
git commit -m "feat(data-source): register \\ApiQuery facade alias"
```

---

# Phase 1 — Encrypted per-device API credentials

### Task 5: Migration + schema YAML

**Files:**
- Create: `database/migrations/2026_05_29_120000_add_api_credentials_to_devices_table.php`
- Modify: `resources/definitions/schema/db_schema.yaml` (devices `Columns:` list)

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_05_29_120000_add_api_credentials_to_devices_table.php` (NO license header — migrations in this repo have none):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('api_transport', 16)->nullable()->after('mtu_status');
            $table->string('api_host', 128)->nullable()->after('api_transport');
            $table->unsignedSmallInteger('api_port')->nullable()->after('api_host');
            $table->string('api_username', 128)->nullable()->after('api_port');
            $table->text('api_password')->nullable()->after('api_username');
            $table->text('api_token')->nullable()->after('api_password');
            $table->boolean('api_verify_tls')->default(true)->after('api_token');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'api_transport',
                'api_host',
                'api_port',
                'api_username',
                'api_password',
                'api_token',
                'api_verify_tls',
            ]);
        });
    }
};
```

- [ ] **Step 2: Apply the migration and regenerate the schema YAML**

Run:
```bash
lnms migrate --force
lnms schema:dump
```
Expected: `db_schema.yaml updated!`. Verify the `devices:` block in `resources/definitions/schema/db_schema.yaml` now ends with rows equivalent to:

```yaml
    - { Field: api_transport, Type: varchar(16), 'Null': true, Extra: '' }
    - { Field: api_host, Type: varchar(128), 'Null': true, Extra: '' }
    - { Field: api_port, Type: 'smallint unsigned', 'Null': true, Extra: '' }
    - { Field: api_username, Type: varchar(128), 'Null': true, Extra: '' }
    - { Field: api_password, Type: text, 'Null': true, Extra: '' }
    - { Field: api_token, Type: text, 'Null': true, Extra: '' }
    - { Field: api_verify_tls, Type: tinyint(1), 'Null': false, Extra: '', Default: '1' }
```

(If `lnms schema:dump` is unavailable in your environment, hand-edit the YAML to add those rows in the same style as the existing `devices:` columns — quote `'Null'`, single-quote integer `Default` values.)

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_05_29_120000_add_api_credentials_to_devices_table.php resources/definitions/schema/db_schema.yaml
git commit -m "feat(devices): add API credential columns (encrypted) migration + schema"
```

---

### Task 6: Device model — `$fillable`, encrypted `casts()`, `$hidden` (TDD)

**Files:**
- Test: `tests/Unit/Models/DeviceApiCredentialsTest.php`
- Modify: `app/Models/Device.php` (`$fillable` 48-91, `casts()` 115-134, add `$hidden`)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/DeviceApiCredentialsTest.php` (license header):

```php
namespace LibreNMS\Tests\Unit\Models;

use App\Models\Device;
use LibreNMS\Tests\TestCase;

final class DeviceApiCredentialsTest extends TestCase
{
    public function testApiSecretsAreHiddenFromArray(): void
    {
        $device = new Device([
            'hostname' => 'sw.example.com',
            'api_username' => 'svc',
            'api_password' => 'secret',
            'api_token' => 'tok',
        ]);

        $array = $device->toArray();

        $this->assertArrayNotHasKey('api_password', $array);
        $this->assertArrayNotHasKey('api_token', $array);
        // non-secret api fields remain visible
        $this->assertSame('svc', $device->api_username);
    }

    public function testApiVerifyTlsCastsToBoolean(): void
    {
        $device = new Device(['api_verify_tls' => 0]);

        $this->assertFalse($device->api_verify_tls);
    }

    public function testApiPasswordReadableViaAttribute(): void
    {
        // attribute access bypasses $hidden (this is how HttpApiQuery reads it)
        $device = new Device(['api_password' => 'secret']);

        $this->assertSame('secret', $device->api_password);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Models/DeviceApiCredentialsTest.php`
Expected: FAIL — `api_password` present in `toArray()` (no `$hidden` yet) / `api_verify_tls` not cast.

- [ ] **Step 3: Edit `app/Models/Device.php`**

Append the seven new columns to the END of the `$fillable` array (after `'uptime',` at line ~90):

```php
        'uptime',
        'api_transport',
        'api_host',
        'api_port',
        'api_username',
        'api_password',
        'api_token',
        'api_verify_tls',
    ];
```

Add the encrypted + boolean casts inside `casts()` (after `'override_sysLocation' => 'boolean',`):

```php
            'override_sysLocation' => 'boolean',
            'api_password' => 'encrypted',
            'api_token' => 'encrypted',
            'api_verify_tls' => 'boolean',
        ];
    }
```

Add a `$hidden` property (Device currently has none) immediately after the `casts()` method, hiding ONLY the two new secrets:

```php
    /**
     * Keep API secrets out of array/JSON serialization (e.g. the REST API).
     * Read them via attribute access ($device->api_password), which bypasses $hidden.
     *
     * @var list<string>
     */
    protected $hidden = [
        'api_password',
        'api_token',
    ];
```

> ⚠️ Do NOT add `community`, `authpass`, or `cryptopass` to `$hidden` — the legacy SNMP stack reads them from `$device->toArray()` (`includes/snmp.inc.php`) and hiding them breaks all SNMP polling.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Models/DeviceApiCredentialsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
./lnms dev:check style
git add app/Models/Device.php tests/Unit/Models/DeviceApiCredentialsTest.php
git commit -m "feat(devices): fillable + encrypted casts + hidden for API credentials"
```

---

### Task 7: Credential entry UI (legacy SNMP edit page)

**Files:**
- Modify: `includes/html/pages/device/edit/snmp.inc.php` (POST handler ~lines 11-36; render section — append a fieldset)

- [ ] **Step 1: Persist posted API fields**

In the POST handler of `includes/html/pages/device/edit/snmp.inc.php`, after the existing SNMP credential block (after the `} elseif ($_POST['community'] != '********') { ... }` around line 36, still inside `if (Gate::allows('update', $device))`), add:

```php
        // API credentials (HTTP/REST data source)
        $device->api_transport = $_POST['api_transport'] ?: null;
        $device->api_host = $_POST['api_host'] ?: null;
        $device->api_port = $_POST['api_port'] ?: null;
        $device->api_username = $_POST['api_username'] ?: null;
        $device->api_verify_tls = isset($_POST['api_verify_tls']) && $_POST['api_verify_tls'] == 'on' ? 1 : 0;
        if (($_POST['api_password'] ?? '') !== '********') {
            $device->api_password = $_POST['api_password'] ?: null;
        }
        if (($_POST['api_token'] ?? '') !== '********') {
            $device->api_token = $_POST['api_token'] ?: null;
        }
```

- [ ] **Step 2: Render the API credentials fieldset**

At the END of the form render section in the same file (just before the form's closing/submit markup), append an API fieldset. Password/token use the `'********'` sentinel so the encrypted secret is never echoed:

```php
    echo "
    <div class='form-group'>
    <label class='col-sm-2 control-label'>API Transport</label>
    <div class='col-sm-4'>
    <select id='api_transport' class='form-control' name='api_transport'>
        <option value=''" . ($device->api_transport ? '' : ' selected') . ">None</option>
        <option value='https'" . ($device->api_transport === 'https' ? ' selected' : '') . ">HTTPS (NX-API / REST)</option>
        <option value='http'" . ($device->api_transport === 'http' ? ' selected' : '') . ">HTTP</option>
    </select>
    </div>
    </div>
    <div class='form-group'>
    <label for='api_host' class='col-sm-2 control-label'>API Host (optional)</label>
    <div class='col-sm-4'><input id='api_host' class='form-control' name='api_host' placeholder='" . htmlspecialchars($device->hostname) . "' value='" . htmlspecialchars($device->api_host ?? '') . "'/></div>
    </div>
    <div class='form-group'>
    <label for='api_port' class='col-sm-2 control-label'>API Port</label>
    <div class='col-sm-4'><input id='api_port' class='form-control' name='api_port' value='" . htmlspecialchars((string) ($device->api_port ?? '443')) . "'/></div>
    </div>
    <div class='form-group'>
    <label for='api_username' class='col-sm-2 control-label'>API Username</label>
    <div class='col-sm-4'><input id='api_username' class='form-control' name='api_username' value='" . htmlspecialchars($device->api_username ?? '') . "'/></div>
    </div>
    <div class='form-group'>
    <label for='api_password' class='col-sm-2 control-label'>API Password</label>
    <div class='col-sm-4'><input type='password' id='api_password' class='form-control' name='api_password' value='" . ($device->api_password ? '********' : '') . "' autocomplete='off'/></div>
    </div>
    <div class='form-group'>
    <label for='api_token' class='col-sm-2 control-label'>API Token (overrides user/pass)</label>
    <div class='col-sm-4'><input type='password' id='api_token' class='form-control' name='api_token' value='" . ($device->api_token ? '********' : '') . "' autocomplete='off'/></div>
    </div>
    <div class='form-group'>
    <label for='api_verify_tls' class='col-sm-2 control-label'>Verify TLS</label>
    <div class='col-sm-4'><input type='checkbox' id='api_verify_tls' name='api_verify_tls'" . ($device->api_verify_tls ? ' checked' : '') . "/> <span class='help-block'>Uncheck for self-signed NX-API certificates.</span></div>
    </div>
    ";
```

- [ ] **Step 3: Manual verification**

Run the app, open a device → Edit → SNMP tab. Confirm the API fields render, saving persists (check `select api_transport, api_username from devices where device_id=<id>`), the password field shows `********` after save (never the real secret), and `select api_password from devices ...` shows ciphertext (encrypted at rest).

- [ ] **Step 4: Commit**

```bash
./lnms dev:check style
git add includes/html/pages/device/edit/snmp.inc.php
git commit -m "feat(ui): API credential fields on device edit page (masked, encrypted)"
```

---

# Phase 2 — NX-OS VRF NX-API fallback

### Task 8: ApiQuery fallback in the NX-OS VRF branch

**Files:**
- Modify: `includes/discovery/vrf.inc.php` (NX-OS branch, lines 163-266)

Context (verified): the NX-OS branch is gated by `if (empty($rds) && $device['os'] === 'nxos')`. It walks NV-OVERLAY + BGP4 SNMP OIDs into `$vrf_names`. On transient SNMP error OR empty result it preserves existing rows via `$valid_vrf[$existing_vrf_id] = 1`. The fallback adds: when SNMP yields no `$vrf_names` AND the device has API creds, query NX-API `show vrf` before deciding to preserve. It reuses the existing `create()/update()` + `$valid_vrf` persistence (NO `syncModels`, NO `ifVrf` — the SNMP branch never set `ifVrf`, so nothing regresses).

- [ ] **Step 1: Add the API fallback inside the `empty($vrf_names)` path**

In `includes/discovery/vrf.inc.php`, inside the `else` branch (the non-transient path), after the two `foreach ($nv_response->values()...)` / `foreach ($bgp_response->values()...)` loops and the `d_echo(...)` line, there is an `if (empty($vrf_names)) { ...preserve... } else { ...create/update... }`. **INSERT the following NEW block immediately BEFORE that `if (empty($vrf_names)) {` line** (do not delete the existing `if/else` — the new block runs first and may populate `$vrf_names`, after which the existing `if (empty($vrf_names))` decides preserve-vs-sync):

```php
                if (empty($vrf_names) && $device['api_transport']) {
                    // SNMP exposed no VRFs but the device has an API configured.
                    // Cisco NX-OS exposes ALL VRFs (incl. SNMP-invisible ones) via NX-API 'show vrf'.
                    $api_response = \ApiQuery::device(DeviceCache::getPrimary())->cli('show vrf');

                    if (! $api_response->isValid()) {
                        // Transport/auth error -> preserve existing rows (do not wipe on a blip).
                        echo "\n  [VRF discovery] NX-OS fallback: NX-API error (" . $api_response->getErrorMessage() . '); preserving existing vrfs rows for this device.';
                        foreach (DeviceCache::getPrimary()->vrfs()->pluck('vrf_id') as $existing_vrf_id) {
                            $valid_vrf[$existing_vrf_id] = 1;
                        }
                    } else {
                        $valid_name_re = '/^[A-Za-z0-9_-]{1,32}$/';
                        foreach ($api_response->table('result', 'body', 'TABLE_vrf', 'ROW_vrf') as $row) {
                            $name = $row['vrf_name'] ?? null;
                            if (is_string($name) && preg_match($valid_name_re, $name)) {
                                $vrf_names[$name] = true;
                            }
                        }
                        echo "\n  [VRF discovery] NX-OS fallback: NX-API 'show vrf' found " . count($vrf_names) . ' VRF(s).';
                    }
                }

                if (empty($vrf_names)) {
```

Then leave the EXISTING preservation body (the `foreach (...->pluck('vrf_id') ...) { $valid_vrf[...] = 1; }`) and the existing `} else { foreach (array_keys($vrf_names) ...) { create/update; $valid_vrf[$vrf_id]=1; } }` exactly as they are — they now handle the API-discovered names identically to SNMP-discovered names.

> The net effect: SNMP first → if empty and API configured, NX-API → names from either source flow through the same `create()/update()` + `$valid_vrf` path and the same cleanup tail.

- [ ] **Step 2: Verify no syntax error**

Run: `php -l includes/discovery/vrf.inc.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual integration verification (no automated test — legacy include needs a live device)**

On a test poller with a real NX-OS switch (e.g. `dv-int-pol-sw1`) configured with API creds:
```bash
lnms device:discover -m vrf -d <device_id>
```
Expected output includes `[VRF discovery] NX-OS fallback: NX-API 'show vrf' found N VRF(s).` and the non-default VRFs appear in `select vrf_name from vrfs where device_id=<id>`. Re-run with the API unreachable (wrong port) and confirm the output says `preserving existing vrfs rows` and no rows are deleted.

- [ ] **Step 4: Commit**

```bash
./lnms dev:check style
git add includes/discovery/vrf.inc.php
git commit -m "feat(discovery): NX-OS VRF NX-API fallback when SNMP is empty"
```

---

# Phase 3 — Composable VRF widget

### Task 9: `DeviceVrfsController` dashboard widget

**Files:**
- Create: `app/Http/Controllers/Widgets/DeviceVrfsController.php`

- [ ] **Step 1: Create the controller (clone of HealthSensorsController, simplified for VRFs)**

Create `app/Http/Controllers/Widgets/DeviceVrfsController.php` (license header, description `Dashboard widget listing a device's VRFs (source-agnostic: SNMP or API).`):

```php
namespace App\Http\Controllers\Widgets;

use App\Models\Device;
use App\Models\Vrf;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceVrfsController extends WidgetController
{
    protected string $name = 'device-vrfs';

    /** @var array<string, mixed> */
    protected $defaults = [
        'title' => null,
        'device' => null,
    ];

    public function getView(Request $request): View|string
    {
        $settings = $this->getSettings();

        if (empty($settings['device'])) {
            return $this->getSettingsView($request);
        }

        $device = Device::hasAccess($request->user())->find($settings['device']);

        $vrfs = $device
            ? $device->vrfs()->orderBy('vrf_name')->get()
            : collect();

        return view('widgets.device-vrfs', [
            'id' => $settings['id'],
            'device' => $device,
            'vrfs' => $vrfs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings($settingsView = false): array
    {
        $settings = parent::getSettings($settingsView);
        $settings['device'] = isset($settings['device']) && is_numeric($settings['device']) ? (int) $settings['device'] : null;

        return $settings;
    }

    public function getSettingsView(Request $request): View
    {
        $settings = $this->getSettings(true);
        $settings['device'] = Device::hasAccess($request->user())->find($settings['device']) ?: null;

        return view('widgets.settings.device-vrfs', $settings);
    }
}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l app/Http/Controllers/Widgets/DeviceVrfsController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
./lnms dev:check style
git add app/Http/Controllers/Widgets/DeviceVrfsController.php
git commit -m "feat(widget): DeviceVrfsController device-scoped VRF widget"
```

---

### Task 10: Widget Blade views

**Files:**
- Create: `resources/views/widgets/device-vrfs.blade.php`
- Create: `resources/views/widgets/settings/device-vrfs.blade.php`

- [ ] **Step 1: Create the widget body view**

Create `resources/views/widgets/device-vrfs.blade.php`:

```blade
@if (empty($device))
    <div class="alert alert-info">{{ __('Please select a device.') }}</div>
@elseif ($vrfs->isEmpty())
    <div class="alert alert-info">{{ __('No VRFs discovered for this device.') }}</div>
@else
    <table class="table table-condensed table-hover">
        <thead>
            <tr>
                <th>{{ __('VRF') }}</th>
                <th>{{ __('Route Distinguisher') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vrfs as $vrf)
                <tr>
                    <td><a href="{{ route('device', ['device' => $device->device_id, 'tab' => 'routing', 'proto' => 'vrf']) }}">{{ $vrf->vrf_name }}</a></td>
                    <td>{{ $vrf->mplsVpnVrfRouteDistinguisher }}</td>
                    <td>{{ $vrf->mplsVpnVrfDescription }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
```

- [ ] **Step 2: Create the settings view (device picker reusing init_select2)**

Create `resources/views/widgets/settings/device-vrfs.blade.php`:

```blade
@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="title-{{ $id }}" class="control-label">{{ __('Widget title') }}</label>
        <input type="text" class="form-control" name="title" id="title-{{ $id }}" placeholder="{{ __('Default Title') }}" value="{{ $title }}">
    </div>
    <div class="form-group">
        <label for="device-{{ $id }}" class="control-label">{{ __('Device') }}</label>
        <select class="form-control" name="device" id="device-{{ $id }}">
            @if($device)
                <option value="{{ $device->device_id }}">{{ $device->displayName() }}</option>
            @endif
        </select>
    </div>
@endsection

@section('javascript')
    <script type="text/javascript">
        init_select2('#device-{{ $id }}', 'device', {}, @json($device ? ['id' => $device->device_id, 'text' => $device->displayName()] : ''));
    </script>
@endsection
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/widgets/device-vrfs.blade.php resources/views/widgets/settings/device-vrfs.blade.php
git commit -m "feat(widget): device-vrfs widget + settings Blade views"
```

---

### Task 11: Register widget route + translation

**Files:**
- Modify: `routes/web.php` (the `Route::prefix('dash')` group, after the `device-types` line ~399)
- Modify: `lang/en/widgets.php` (after the `device-types` entry)

- [ ] **Step 1: Add the route**

In `routes/web.php`, inside `Route::prefix('dash')->group(...)`, add after the `device-types` line:

```php
            Route::post('device-types', Widgets\DeviceTypeController::class);
            Route::post('device-vrfs', Widgets\DeviceVrfsController::class);
```

- [ ] **Step 2: Add the translation**

In `lang/en/widgets.php`, add after the `device-types` entry:

```php
    'device-types' => [
        'title' => 'Device Types',
    ],
    'device-vrfs' => [
        'title' => 'Device VRFs',
    ],
```

- [ ] **Step 3: Manual verification**

Run the app. On a dashboard, add a widget → the picker (populated by `DashboardController::listWidgets()` route reflection) now lists **Device VRFs**. Add it, open settings, pick an NX-OS device with discovered VRFs, save. The widget renders the VRF table. Confirm it renders identically for an SNMP-discovered-VRF device and an NX-API-discovered-VRF device (source-agnostic).

- [ ] **Step 4: Commit**

```bash
./lnms dev:check style
git add routes/web.php lang/en/widgets.php
git commit -m "feat(widget): register device-vrfs route and title"
```

---

# Final verification

### Task 12: Full check + manual end-to-end

- [ ] **Step 1: Run the unit suite and style/lint**

Run:
```bash
./lnms dev:check unit
./lnms dev:check style
./lnms dev:check lint
```
Expected: all pass. (If you have a test DB, run `./lnms dev:check unit --db` so `DBSetupTest::testValidateSchema` confirms the migration matches `db_schema.yaml`.)

- [ ] **Step 2: End-to-end on a real NX-OS switch**

1. Add/edit a device (NX-OS, e.g. `dv-int-pol-sw1`), set API transport=HTTPS, username/password, uncheck Verify TLS.
2. `lnms device:discover -m vrf -d <device_id>` → confirm NX-API fallback log line + non-default VRFs in the `vrfs` table.
3. Add the **Device VRFs** dashboard widget scoped to that device → confirm the VRFs render.
4. Confirm `select api_password from devices where device_id=<id>` returns ciphertext (encrypted at rest), and the REST API (`/api/v0/devices/<id>`) does NOT include `api_password`/`api_token`.

- [ ] **Step 3: Final commit (if any style fixups remain)**

```bash
git add -A
git commit -m "chore: style fixups for nxapi vrf vertical slice"
```

---

## Deferred to later milestones (NOT in this plan)
- Numeric API telemetry via `sensors.poller_type='http'` + `os_discovery` YAML `source: api` (Phase 4).
- Modern `LibreNMS/Modules/Vrf.php` + `LibreNMS/OS/Nxos.php` promotion (adds `Keyable`/`syncModels`, `ports.ifVrf` handling, `cisco-vrf-lite` reconciliation).
- Composable device top-level tabs via a new `DeviceTabHook` (Phase 5).
- `KeyRotate` coverage for the new encrypted columns; document `APP_KEY` parity requirement across all 9 cluster nodes.
- Optional `DeviceOverviewHook` plugin panel (the dashboard widget covers the goal-3 requirement for M1).
