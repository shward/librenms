<?php

/**
 * ApiQueryInterface.php
 *
 * Contract for the HTTP/REST API data source, parallel to SnmpQueryInterface.
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
