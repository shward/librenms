<?php

/**
 * ApiResponse.php
 *
 * Responsible for normalizing HTTP/REST API responses into usable PHP data structures.
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

        // JSON-RPC error: HTTP 200 with an {"error": ...} member. Use array_key_exists so a
        // present-but-null error is still treated as an error, not a success.
        if (array_key_exists('error', $this->body)) {
            $err = $this->body['error'];
            $this->errorMessage = match (true) {
                $err === null => 'Unknown API error',
                is_array($err) => $err['message'] ?? (string) json_encode($err),
                default => (string) $err,
            };

            return false;
        }

        // An empty body ([] / {}) is a valid 200 response; callers check table()/json() for content.
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
