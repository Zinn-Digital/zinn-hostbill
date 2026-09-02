<?php
/**
 * Zinn Digital provisioning module for HostBill.
 *
 * The second billing-panel module, built after WHMCS at the owner's instruction (2026-08-22:
 * *"do 1 but then do hostibill and the generic webhook also for sure, we need to make this as
 * open and adatpable as possible"*).
 *
 * ⛔⛔ IT SHARES THE WHMCS MODULE'S ENTIRE CONTRACT — the same endpoints, the same
 * idempotency keys, the same "a hold you did not place cannot be released" refusal. That is
 * the point of building the API first: the second module is a different panel's calling
 * convention wrapped around calls that already existed, not a second integration with its own
 * ideas. If this file ever needs an endpoint the WHMCS one does not, something has gone
 * wrong in the API rather than in HostBill.
 *
 * Installation: copy to `includes/modules/Hosting/zinn/class.zinn.php` in the HostBill root.
 *
 * @package Zinn\HostBill
 * @license GPL-2.0-or-later
 * @author  Neil Lock — CEO, Zinn Digital® Ltd
 * @link    https://zinndigital.com
 */

declare(strict_types=1);

require_once __DIR__ . '/ZinnHostBillClient.php';

/**
 * HostBill hosting module for the Zinn Digital platform.
 *
 * HostBill loads a module class named for its directory and calls these methods; the base
 * class is provided by HostBill itself at runtime, which is why this file declares no parent
 * and why it cannot be unit-tested outside a HostBill install. Its LOGIC lives in
 * `ZinnHostBillClient` below, which is plain PHP and is exercised by
 * `integrations/tests/HostBillClientTest.php`.
 */
class zinn extends HostingModule
{
    /**
     * Module name in the HostBill UI.
     *
     * @var string
     */
    // ⚖️ docs/203 §8: the ® on every occurrence of the mark in customer-facing copy. A
    // reseller's HostBill module list is customer-facing, and `/kb/billing-panel-modules`
    // quotes this exact string as the entry to activate.
    public $modname = "Zinn Digital\u{00AE}";

    /**
     * Module version.
     *
     * @var string
     */
    public $version = '1.0.0';

    /**
     * Connection details HostBill collects on the server entry.
     *
     * ⛔ `password` type for the key, so HostBill stores it encrypted and masks it in the UI.
     * A hosting-destroying credential does not go in a plain-text field.
     *
     * @var array<string,array<string,mixed>>
     */
    public $serverFields = [
        'hostname' => [
            'name' => 'API address',
            'type' => 'input',
            'default' => 'https://api.zinndigital.com',
        ],
        'password' => [
            'name' => 'API key',
            'type' => 'password',
            'default' => '',
        ],
    ];

    /**
     * Per-product options.
     *
     * @var array<string,array<string,mixed>>
     */
    public $options = [
        'product_line' => [
            'name' => 'Product line',
            'value' => 'mainstream',
            'type' => 'input',
        ],
        // ⛔⛔ MANDATORY AT THE PLATFORM. `POST /v1/sites` answers
        // `422 stack_type: "Required when blueprint_id is omitted."` without it, so this
        // module could not provision a single account until 2026-08-31 (D17360). Kept in
        // step with the engine's `OFFERED_STACK_TYPES` by a test that reads THIS file —
        // `engine/engine/hosting/tests/test_integration_stacks.py`, not by memory.
        'stack_type' => [
            'name' => 'Stack',
            'value' => 'wordpress',
            'type' => 'select',
            'options' => [
                'headless_cms', 'nextcloud', 'node', 'one_click', 'owncloud',
                'php', 'static', 'woocommerce', 'wordpress',
            ],
        ],
        'application' => [
            'name' => 'Application',
            'value' => '',
            'type' => 'input',
        ],
        'php_version' => [
            'name' => 'PHP version',
            'value' => '',
            'type' => 'input',
        ],
        // ⛔⛔ REQUIRED. An order without it is refused rather than provisioned — see
        // `clientPlan()` for what a site with no plan costs (D17700).
        'plan_code' => [
            'name' => 'Plan code',
            'value' => '',
            'type' => 'input',
        ],
        'billing_interval' => [
            'name' => 'Billing interval',
            'value' => 'monthly',
            'type' => 'select',
            'options' => ['annual', 'monthly'],
        ],
    ];

    /**
     * The body `POST /v1/sites` is sent for one order.
     *
     * ⭐ Extracted so the request body is TESTABLE — the same reason as its WHMCS twin. An
     * inline body inside a `try`/`catch` that turns every failure into `addError` cannot be
     * asserted, which is how a missing required field survived a green suite.
     *
     * ⛔ Optional fields are OMITTED rather than sent empty: `php_version: ""` is a 422
     * naming the versions the line offers, and an `application` on a stack that does not ask
     * for one is a 422 too. An empty box on the product form means "use the default", and
     * sending it would mean "refuse the order".
     *
     * @param string $orgId  The client organisation id.
     * @param string $domain The hostname being provisioned.
     * @return array<string,mixed>
     */
    private function sitePayload(string $orgId, string $domain, string $subscriptionId = ''): array
    {
        $payload = [
            'org_id' => $orgId,
            'product_line' => trim((string) ($this->options['product_line']['value'] ?? '')) ?: 'mainstream',
            'primary_domain' => $domain,
            'name' => $domain,
            'stack_type' => trim((string) ($this->options['stack_type']['value'] ?? '')) ?: 'wordpress',
        ];
        // ⛔⛔ THE FIELD WHOSE ABSENCE COST THE WHOLE INVOICE (D17700). Without it the
        // platform falls back to the client organisation's governing subscription — and a
        // client this module has just created has none, because `governing_subscription`
        // resolves within one org and does not walk up to the reseller. The site then lands
        // with no plan at all: no line on the reseller's wholesale statement, no entitlements
        // for the client, and nothing an upgrade could change. Nothing reports it; the site
        // serves perfectly. `clientPlan()` is what makes this value exist.
        if ($subscriptionId !== '') {
            $payload['subscription_id'] = $subscriptionId;
        }
        $application = trim((string) ($this->options['application']['value'] ?? ''));
        if ($application !== '') {
            $payload['application'] = $application;
        }
        $phpVersion = trim((string) ($this->options['php_version']['value'] ?? ''));
        if ($phpVersion !== '') {
            $payload['php_version'] = $phpVersion;
        }
        return $payload;
    }

    /**
     * Provision hosting for a new account.
     *
     * @return bool
     */
    /**
     * Put the client organisation on the plan this product sells, and return its subscription.
     *
     * ⛔⛔ THE STEP WHOSE ABSENCE MEANT ZINN INVOICED THE RESELLER NOTHING (D17700, measured
     * on production 2026-09-01 with a control). A client organisation this module has just
     * created holds no subscription, and `POST /v1/sites` has no plan field — so every
     * account ordered through this module landed with no plan: no wholesale line, no
     * entitlements, no quota on the box, and nothing for an upgrade to move. All of it silent.
     *
     * ⚖️ Owner ruling 2026-09-01 on a client's SECOND service, enforced by the engine: the
     * same plan is a no-op (its own site allowance covers the extra site), a different plan
     * is refused rather than superseding — superseding would bill for one plan while the
     * client ran two services.
     *
     * ⛔ A missing plan code is refused here rather than defaulted. There is no safe default:
     * any guess provisions hosting on a plan the reseller did not choose and may not have
     * priced, and the wrong guess is invisible until a statement arrives.
     *
     * @param ZinnHostBillClient $client The API client.
     * @param string             $orgId  The client organisation id.
     * @return string The subscription id to record the account against.
     */
    private function clientPlan($client, string $orgId): string
    {
        $planCode = trim((string) ($this->options['plan_code']['value'] ?? ''));
        if ($planCode === '') {
            throw new RuntimeException(
                'This product has no Zinn plan code, so the order was not provisioned. Set '
                . '"Plan code" on the product to one of the plans from '
                . 'GET /v1/reseller/prices. Provisioning without it would create hosting that '
                . 'carries no plan — you would not be invoiced for it and the client would '
                . 'get no allowances.'
            );
        }
        $interval = trim((string) ($this->options['billing_interval']['value'] ?? '')) ?: 'monthly';
        $plan = $client->post(
            '/v1/reseller/clients/' . rawurlencode($orgId) . '/plan',
            ['plan_code' => $planCode, 'interval' => $interval],
            'hostbill-plan-' . $orgId . '-' . $planCode
        );
        $subscriptionId = trim((string) ($plan['subscription_id'] ?? ''));
        if ($subscriptionId === '') {
            throw new RuntimeException(
                'The hosting platform did not return a subscription for plan "' . $planCode
                . '", so nothing was provisioned.'
            );
        }
        return $subscriptionId;
    }

    public function createAccount(): bool
    {
        try {
            $client = $this->zinnClient();
            $accountId = (string) $this->details['id'];

            if ($this->zinnSiteId() !== '') {
                return true; // Already provisioned — never a second site.
            }

            $orgId = $this->zinnClientOrg($client);
            $domain = strtolower(trim((string) $this->details['domain']));
            if ($domain === '') {
                $this->addError('This account has no domain, so there is nothing to provision.');
                return false;
            }

            // ⛔⛔ BEFORE the site, and a hard refusal when the product names no plan.
            // `POST /v1/sites` records whatever subscription exists at the moment it runs, so
            // a plan granted afterwards does not attach retrospectively — it would need a
            // second call nobody makes. Refusing is deliberately louder than provisioning:
            // an unbilled site works perfectly and is reported by nothing at either end.
            $subscriptionId = $this->clientPlan($client, $orgId);

            $site = $client->post(
                '/v1/sites',
                $this->sitePayload($orgId, $domain, $subscriptionId),
                'hostbill-site-' . $accountId
            );

            if (empty($site['id'])) {
                $this->addError('The hosting platform did not return a site.');
                return false;
            }
            $this->setDetail('username', (string) $site['id']);
            return true;
        } catch (Throwable $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Put the account on a non-payment hold.
     *
     * @return bool
     */
    public function suspendAccount(): bool
    {
        return $this->zinnCall(
            '/v1/reseller/services/' . rawurlencode($this->zinnRequireSiteId()) . '/suspend',
            ['reason' => 'HostBill account #' . $this->details['id']]
        );
    }

    /**
     * Release a hold this reseller placed.
     *
     * ⛔ A refusal here is CORRECT and is shown, never swallowed: it means our abuse team
     * suspended the site, and no billing panel may release that.
     *
     * @return bool
     */
    public function unsuspendAccount(): bool
    {
        return $this->zinnCall(
            '/v1/reseller/services/' . rawurlencode($this->zinnRequireSiteId()) . '/unsuspend',
            []
        );
    }

    /**
     * Schedule the site's deletion.
     *
     * @return bool
     */
    public function terminateAccount(): bool
    {
        try {
            $site = $this->zinnRequireSiteId();
            $client = $this->zinnClient();
            try {
                $row = $client->get('/v1/reseller/services/' . rawurlencode($site), []);
            } catch (Throwable $e) {
                // Gone already, or never ours — nothing left to terminate. Reporting failure
                // would leave a cancelled account stuck in the panel's queue for ever.
                return true;
            }
            $domain = trim((string) ($row['primary_domain'] ?? ''));
            if ($domain === '') {
                $this->addError(
                    'The hosting platform did not report this account\'s domain, so the '
                    . 'deletion could not be confirmed. Nothing has been changed.'
                );
                return false;
            }
            $client->delete('/v1/sites/' . rawurlencode($site), ['confirm_domain' => $domain]);
            return true;
        } catch (Throwable $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Hand the client a one-time sign-in link into their panel.
     *
     * @return string|false
     */
    public function singleSignOn()
    {
        try {
            $response = $this->zinnClient()->post(
                '/v1/reseller/services/' . rawurlencode($this->zinnRequireSiteId()) . '/sso',
                []
            );
            return empty($response['url']) ? false : (string) $response['url'];
        } catch (Throwable $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * Test the connection with a real call.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $program = $this->zinnClient()->get('/v1/reseller/program');
            $status = (string) ($program['status'] ?? '');
            if ($status !== 'active') {
                $this->addError('Connected, but your reseller programme is "' . $status . '".');
                return false;
            }
            return true;
        } catch (Throwable $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * One POST, with the module's error handling.
     *
     * @param string              $path API path.
     * @param array<string,mixed> $body JSON body.
     * @return bool
     */
    private function zinnCall(string $path, array $body): bool
    {
        try {
            $this->zinnClient()->post($path, $body);
            return true;
        } catch (Throwable $e) {
            $this->addError($e->getMessage());
            return false;
        }
    }

    /**
     * The API client for this server entry.
     *
     * @return ZinnHostBillClient
     */
    private function zinnClient(): ZinnHostBillClient
    {
        return new ZinnHostBillClient(
            (string) ($this->server['hostname'] ?? ''),
            (string) ($this->server['password'] ?? '')
        );
    }

    /**
     * The Zinn site id recorded against this account.
     *
     * @return string
     */
    private function zinnSiteId(): string
    {
        return trim((string) ($this->details['username'] ?? ''));
    }

    /**
     * The site id, or an exception naming what to do about it.
     *
     * @return string
     */
    private function zinnRequireSiteId(): string
    {
        $site = $this->zinnSiteId();
        if ($site === '') {
            throw new RuntimeException(
                'This account has no Zinn site recorded against it. Create the account first.'
            );
        }
        return $site;
    }

    /**
     * Resolve — or create — the client organisation.
     *
     * ⛔ Keyed on the HostBill CLIENT id, so a client's second order lands in the account
     * they already have rather than in a second one.
     *
     * @param ZinnHostBillClient $client The API client.
     * @return string
     */
    private function zinnClientOrg(ZinnHostBillClient $client): string
    {
        $clientId = (int) ($this->details['client_id'] ?? 0);
        $existing = trim((string) $this->getClientMeta($clientId, 'zinn_org_id'));
        if ($existing !== '') {
            return $existing;
        }
        $name = trim((string) ($this->client['companyname'] ?? ''));
        if ($name === '') {
            $name = trim(($this->client['firstname'] ?? '') . ' ' . ($this->client['lastname'] ?? ''));
        }
        if ($name === '') {
            $name = (string) ($this->client['email'] ?? 'Client');
        }
        $org = $client->post('/v1/orgs', [
            'type' => 'customer',
            'name' => $name,
        ], 'hostbill-org-' . $clientId);
        if (empty($org['id'])) {
            throw new RuntimeException('The hosting platform did not return a client account.');
        }
        $this->setClientMeta($clientId, 'zinn_org_id', (string) $org['id']);
        return (string) $org['id'];
    }
}
