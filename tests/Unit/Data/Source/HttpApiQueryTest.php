<?php

/**
 * HttpApiQueryTest.php
 *
 * Tests for the HTTP/REST API data-source transport.
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
            // NX-API rejects the JSON-RPC envelope with HTTP 400 unless the
            // Content-Type is application/json-rpc (not the default application/json).
            return $request->url() === 'https://switch.example.com:443/ins'
                && $request->hasHeader('Content-Type', 'application/json-rpc')
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
