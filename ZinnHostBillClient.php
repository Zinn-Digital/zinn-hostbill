<?php
/**
 * The HTTP client the HostBill module calls the Zinn platform through.
 *
 * ⛔⛔ THIS IS DELIBERATELY A SECOND COPY OF THE WHMCS CLIENT'S LOGIC, NOT A SHARED FILE, and
 * the reason is the install instruction rather than taste: a billing-panel module is
 * installed by copying ONE directory into the panel's module tree, so a file outside that
 * directory is a file the reseller does not copy and a fatal error on their first order. A
 * shared library would need a build step that inlines it, which is a mechanism to maintain
 * for two small files.
 *
 * ⭐ What keeps the copies honest is a TEST rather than a comment:
 * `integrations/tests/ClientBehaviourTest.php` runs the same six cases against both classes
 * — the API's own error message survives, the idempotency header is sent, the credential is
 * redacted, a transport failure is distinguishable from a refusal — so a fix applied to one
 * and not the other goes red. Two copies with no test is how they come to disagree; two
 * copies with a shared test is a duplication that cannot drift silently.
 *
 * @package Zinn\HostBill
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

/**
 * A thin JSON client for the Zinn public API.
 */
class ZinnHostBillClient
{
    /**
     * Seconds to wait for the API.
     */
    public const TIMEOUT = 20;

    /**
     * API base URL.
     *
     * @var string
     */
    private $base;

    /**
     * API key.
     *
     * @var string
     */
    private $key;

    /**
     * @param string $base API base URL, with or without a scheme.
     * @param string $key  API key.
     */
    public function __construct(string $base, string $key)
    {
        $base = trim($base);
        if ($base === '') {
            $base = 'https://api.zinndigital.com';
        }
        if (!preg_match('#^https?://#i', $base)) {
            // TLS is not negotiable for a credential that can destroy hosting, so the
            // scheme is forced rather than guessed from a checkbox somebody may have left
            // off. A panel's server entry usually holds a bare hostname.
            $base = 'https://' . $base;
        }
        $this->base = rtrim($base, '/');
        $this->key = trim($key);
        if ($this->key === '') {
            throw new RuntimeException(
                'No Zinn API key on this server entry. Put the key in the API key field.'
            );
        }
    }

    /**
     * GET a path.
     *
     * @param string               $path  API path beginning with `/v1/`.
     * @param array<string,scalar> $query Query parameters.
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->base . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $this->send('GET', $url, null, '');
    }

    /**
     * POST a JSON body.
     *
     * @param string              $path           API path beginning with `/v1/`.
     * @param array<string,mixed> $body           JSON body.
     * @param string              $idempotencyKey Stable key for this logical operation.
     * @return array<string,mixed>
     */
    public function post(string $path, array $body, string $idempotencyKey = ''): array
    {
        return $this->send('POST', $this->base . $path, $body, $idempotencyKey);
    }

    /**
     * DELETE a path.
     *
     * @param string $path API path beginning with `/v1/`.
     * @return array<string,mixed>
     */
    public function delete(string $path, ?array $body = null): array
    {
        return $this->send('DELETE', $this->base . $path, $body, '');
    }

    /**
     * The headers one request carries.
     *
     * ⭐ Public and pure so a test can assert the `Idempotency-Key` is actually sent. A
     * retried provision that silently omitted it would bill the reseller for a second site,
     * and that is not visible in a diff — only in a header list.
     *
     * @param bool   $hasBody        Whether a JSON body is being sent.
     * @param string $idempotencyKey Idempotency key, or an empty string.
     * @return array<int,string>
     */
    public function headers(bool $hasBody, string $idempotencyKey): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: ZinnHostBill/1.0',
        ];
        if ($hasBody) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $headers;
    }

    /**
     * Turn a status + raw body into decoded data, or throw with the API's own message.
     *
     * ⛔⛔ Public and pure for the same reason `headers()` is: this is the behaviour that
     * decides what a reseller's admin reads when something goes wrong, and it is the one
     * thing in this file worth a test. The platform's errors are written to be read by a
     * person — "It may already be suspended, still being set up, or held by our abuse team."
     * — and both panels render a module's message verbatim.
     *
     * @param int    $status HTTP status.
     * @param string $raw    Raw response body.
     * @return array<string,mixed>
     */
    public function interpret(int $status, string $raw): array
    {
        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($decoded) ? $decoded : [];
        }
        $message = '';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $message = (string) $decoded['error']['message'];
        }
        if ($message === '') {
            $message = 'The hosting platform answered with status ' . $status . '.';
        }
        // ⛔⛔ THE `details` ARRAY IS THE HALF THAT SAYS WHAT TO DO, AND IT USED TO BE
        // DISCARDED. A validation failure's top-level message is deliberately generic —
        // "The request was well-formed but failed validation." — because the platform puts
        // the actionable half in `details`: which field, and why. Dropping it left a
        // reseller's admin staring at a sentence that names nothing, on the one screen
        // where they are trying to work out what to change (D17362).
        $message .= self::detailSuffix($decoded);
        throw new RuntimeException($message);
    }

    /**
     * The ` (field: reason)` tail appended to a refusal, or an empty string.
     *
     * ⛔ Deduplicated and capped. A bulk refusal can carry a `details` entry per row, and a
     * WHMCS module's returned string is rendered into an admin page — an unbounded paste of
     * fifty identical reasons is a message nobody reads, which is the same outcome as
     * having no message at all.
     *
     * ⭐ `code: "none"` entries carry a bare VALUE rather than a sentence — the platform
     * uses them to attach numbers to an error (`max_sites: 0`, `sites_in_use: 0` on
     * `PLAN_REQUIRED`) — so they are rendered as `field: value`, which is what they mean.
     *
     * @param mixed $decoded The decoded response body.
     * @return string
     */
    private static function detailSuffix($decoded): string
    {
        if (!is_array($decoded) || !isset($decoded['error']['details'])) {
            return '';
        }
        $details = $decoded['error']['details'];
        if (!is_array($details)) {
            return '';
        }
        $parts = [];
        foreach ($details as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $field = trim((string) ($entry['field'] ?? ''));
            $reason = trim((string) ($entry['message'] ?? ''));
            if ($field === '' && $reason === '') {
                continue;
            }
            $part = $field === '' ? $reason : $field . ': ' . $reason;
            if (!in_array($part, $parts, true)) {
                $parts[] = $part;
            }
            if (count($parts) === 5) {
                break;
            }
        }
        return $parts === [] ? '' : ' (' . implode('; ', $parts) . ')';
    }

    /**
     * Perform the call.
     *
     * @param string                   $method         HTTP method.
     * @param string                   $url            Absolute URL.
     * @param array<string,mixed>|null $body           JSON body, or null.
     * @param string                   $idempotencyKey Idempotency key, or an empty string.
     * @return array<string,mixed>
     */
    private function send(string $method, string $url, ?array $body, string $idempotencyKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers($body !== null, $idempotencyKey),
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            // ⛔ Distinguishable from a refusal on purpose. "We could not reach them" and
            // "they said no" need completely different actions from the reseller, and a
            // single "request failed" makes an outage look like a configuration error.
            throw new RuntimeException('Could not reach the hosting platform: ' . $transportError);
        }
        return $this->interpret($status, (string) $raw);
    }
}
