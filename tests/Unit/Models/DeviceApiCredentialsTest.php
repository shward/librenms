<?php

/**
 * DeviceApiCredentialsTest.php
 *
 * Tests API credential storage on the Device model.
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
 * @copyright  2026 Josh Thomas-Ward
 * @author     Josh Thomas-Ward <josh.thomasward@pelagicai.com>
 */

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
