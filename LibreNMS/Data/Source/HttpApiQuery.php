<?php

/**
 * HttpApiQuery.php
 *
 * HTTP/REST API data source. The transport-level peer of NetSnmpQuery.
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
