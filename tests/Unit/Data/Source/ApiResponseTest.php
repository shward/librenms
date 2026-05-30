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

    public function testPluckExtractsColumnFromTableRows(): void
    {
        $body = ['rows' => [
            ['name' => 'default', 'id' => 1],
            ['name' => 'PROD', 'id' => 2],
        ]];
        $response = new ApiResponse(200, $body);

        $this->assertSame(['default', 'PROD'], $response->pluck('name', 'rows'));
    }
}
