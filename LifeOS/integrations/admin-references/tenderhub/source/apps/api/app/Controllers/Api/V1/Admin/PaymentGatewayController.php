<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Payments\PaymentGatewayConfiguration;

class PaymentGatewayController extends BaseApiController
{
    private function administrator()
    {
        $actor = db_connect('default')->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        if (! $actor || $actor['user_group'] !== 'staff' || $actor['status'] !== 'active'
            || ! in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true)) {
            return problem(403, 'forbidden', 'Only active administrators can change payment gateways.');
        }
        return null;
    }

    public function index()
    {
        $actor = db_connect('default')->table('users')->where('id', (int) $this->request->userId)->get()->getFirstRow('array');
        return $this->ok([
            'can_manage' => $actor && $actor['user_group'] === 'staff' && $actor['status'] === 'active'
                && in_array($actor['role'], \App\Filters\StaffRole::ADMIN_ROLES, true),
            'payhere' => PaymentGatewayConfiguration::status('payhere'),
            'stripe' => PaymentGatewayConfiguration::status('stripe'),
        ]);
    }

    public function configure(string $provider)
    {
        if ($denied = $this->administrator()) return $denied;
        if (! in_array($provider, ['payhere', 'stripe'], true)) return problem(422, 'unsupported_gateway', 'Choose PayHere or Stripe.');
        try {
            PaymentGatewayConfiguration::configure($provider, $this->body(), (int) $this->request->userId);
        } catch (\InvalidArgumentException $e) {
            return problem(422, 'invalid_gateway_credentials', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[PaymentGatewayController] configuration failed: ' . $e->getMessage());
            return problem(503, 'gateway_configuration_failed', 'Could not securely save the gateway credentials. Check server encryption configuration.');
        }
        service('eventLedger')->record('settings', 0, 'payment_gateway.configured', ucfirst($provider) . ' credentials updated by administrator.', [
            'provider' => $provider, 'actor_id' => (int) $this->request->userId,
        ]);
        return $this->ok(PaymentGatewayConfiguration::status($provider));
    }

    public function disable(string $provider)
    {
        if ($denied = $this->administrator()) return $denied;
        if (! in_array($provider, ['payhere', 'stripe'], true)) return problem(422, 'unsupported_gateway', 'Choose PayHere or Stripe.');
        try {
            PaymentGatewayConfiguration::disable($provider, (int) $this->request->userId);
        } catch (\Throwable $e) {
            log_message('error', '[PaymentGatewayController] disable failed: ' . $e->getMessage());
            return problem(503, 'gateway_configuration_failed', 'Could not disable this gateway.');
        }
        service('eventLedger')->record('settings', 0, 'payment_gateway.disabled', ucfirst($provider) . ' checkout disabled by administrator.', [
            'provider' => $provider, 'actor_id' => (int) $this->request->userId,
        ]);
        return $this->ok(PaymentGatewayConfiguration::status($provider));
    }
}
