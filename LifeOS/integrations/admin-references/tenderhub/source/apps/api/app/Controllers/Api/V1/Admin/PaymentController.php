<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Subscriptions\SubscriptionService;
use App\Models\OrderModel;
use App\Libraries\Payments\PaymentGatewayConfiguration;
use Config\Subscription;

class PaymentController extends BaseApiController
{
    public function index()
    {
        $rows = db_connect()->table('payments')
            ->select('payments.*, organisations.name AS org, organisations.reg_no AS br_number, users.email, orders.order_id AS order_number_from_order')
            ->join('organisations', 'organisations.id = payments.org_id')
            ->join('users', 'users.id = payments.user_id', 'left')
            ->join('orders', 'orders.id = payments.order_id', 'left')
            ->where('payments.method !=', 'trial')
            ->where('payments.term !=', 'trial')
            ->where('payments.amount >', 0)
            ->orderBy('payments.created_at', 'DESC')->get()->getResultArray();

        foreach ($rows as &$p) {
            // Someone has paid and cannot use what they paid for, and this queue
            // decides whether they renew.
            $p['waiting_hours'] = $p['state'] === 'claimed'
                ? round((time() - strtotime($p['created_at'])) / 3600, 1) : null;
            $p['overdue'] = $p['waiting_hours'] !== null && $p['waiting_hours'] > 24;
            $p['order_number'] = $p['order_number'] ?: ($p['order_number_from_order'] ?? null);
            $p['receipt_url'] = ! empty($p['receipt_path']) ? '/api/admin/payments/' . $p['id'] . '/receipt' : null;
        }

        return $this->ok($rows, [
            'bank' => config(Subscription::class)->bank,
            'payhere' => PaymentGatewayConfiguration::status('payhere'),
        ]);
    }

    /**
     * Recording the review and activating the organisation happen in ONE
     * transaction. Splitting them is how an account ends up active with no
     * payment behind it, or a confirmed payment with a subscriber still locked
     * out and telephoning about it.
     */
    public function confirm(int $id)
    {
        $payments = model('App\Models\PaymentModel');
        $p        = $payments->find($id);

        if (! $p) {
            return problem(404, 'not_found', 'No such payment.');
        }
        if ($p['state'] !== 'claimed') {
            return problem(409, 'already_reviewed', 'This claim has already been ' . $p['state'] . '.');
        }

        $months = config(Subscription::class)->terms[$p['term']]['months'] ?? 12;

        $db = db_connect();
        $db->transBegin();
        try {
            $db->table('payments')->where('id', $id)->where('state', 'claimed')->update([
                'state' => 'confirmed', 'reviewed_by' => (int) $this->request->userId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return problem(409, 'already_reviewed', 'Another administrator has already reviewed this claim.');
            }
            $renewsAt = (new SubscriptionService($db))->activatePaidSubscription((int) $p['org_id'], (string) $p['plan'], (int) $months);
            if (! empty($p['order_id'])) {
                try {
                    model(OrderModel::class)->transition((int) $p['order_id'], OrderModel::STATUS_PAID, [
                        'transaction_id' => $p['slip_ref'] ?? null,
                        'provider_status' => 'admin_confirmed',
                        'paid_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\DomainException) {
                    // The payment row is authoritative for manual review; an already-paid order is idempotent.
                }
            }
        } catch (\Throwable $e) {
            // Without this, an error mid-transaction left the payment row and the
            // organisation plan in an open, uncommitted transaction — activating
            // access with no recorded payment, or vice versa, is exactly what the
            // single transaction exists to prevent.
            $db->transRollback();
            log_message('error', '[PaymentController:confirm] ' . $e->getMessage());

            return problem(500, 'confirm_failed', 'Could not confirm the payment. Please retry.');
        }

        if (! $db->transCommit()) {
            return problem(500, 'confirm_failed', 'Could not confirm the payment. Please retry.');
        }

        service('eventLedger')->record('payment', $id, 'payment.confirmed', "Payment #{$id} confirmed by staff #{$this->request->userId}", [
            'payment_id' => $id,
            'org_id'     => $p['org_id'],
            'plan'       => $p['plan'],
            'term'       => $p['term'],
        ]);

        // Tell the subscriber their access is live. In-app is delivered
        // immediately; email is attempted only if a provider is configured
        // (NotificationService records 'skipped' honestly otherwise).
        if (! empty($p['user_id'])) {
            try {
                (new \App\Libraries\Notifications\NotificationService())->notify(
                    (int) $p['user_id'], (int) $p['org_id'], 'payment_confirmed',
                    'Subscription activated',
                    'Your payment has been confirmed and your subscription is now active.',
                    '/subscription', ['in_app', 'email'],
                );
            } catch (\Throwable $e) {
                log_message('error', '[PaymentController:confirm] notify failed: ' . $e->getMessage());
            }
        }

        return $this->ok([
            'state' => 'confirmed',
            'renews_at' => date('c', strtotime($renewsAt)),
        ]);
    }

    /** Rejection REQUIRES a reason, because the subscriber is told it verbatim. */
    public function reject(int $id)
    {
        $reason = trim((string) ($this->body()['reason'] ?? ''));
        if ($reason === '') {
            return problem(422, 'reason_required', 'A rejection must carry a reason.');
        }

        $payments = model('App\Models\PaymentModel');
        $p        = $payments->find($id);
        if (! $p) {
            return problem(404, 'not_found', 'No such payment.');
        }
        if ($p['state'] !== 'claimed') {
            return problem(409, 'already_reviewed', 'This claim has already been ' . $p['state'] . '.');
        }

        $db = db_connect();
        $db->table('payments')->where('id', $id)->where('state', 'claimed')->update([
            'state' => 'rejected', 'reject_reason' => $reason,
            'reviewed_by' => (int) $this->request->userId, 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if ($db->affectedRows() !== 1) {
            return problem(409, 'already_reviewed', 'Another administrator has already reviewed this claim.');
        }
        if (! empty($p['order_id'])) {
            try {
                model(OrderModel::class)->transition((int) $p['order_id'], OrderModel::STATUS_FAILED, [
                    'provider_status' => 'admin_rejected',
                ]);
            } catch (\Throwable $e) {
                log_message('warning', '[PaymentController:reject] order transition skipped: ' . $e->getMessage());
            }
        }
        // Only undo the "pending" this claim caused. Rejecting a claim must not
        // wipe an active trial or paid term (it set sub_status='none' for every
        // organisation, including ones with a running paid subscription).
        $orgRow = model('App\Models\OrganisationModel')->find((int) $p['org_id']);
        $otherClaims = $payments->where('org_id', (int) $p['org_id'])->where('state', 'claimed')->countAllResults();
        if ($orgRow && $orgRow['sub_status'] === 'pending' && $otherClaims === 0) {
            model('App\Models\OrganisationModel')->update((int) $p['org_id'], ['sub_status' => 'none']);
        }

        service('eventLedger')->record('payment', $id, 'payment.rejected', "Payment #{$id} rejected: {$reason}", [
            'payment_id' => $id,
            'org_id'     => $p['org_id'],
            'reason'     => $reason,
        ]);

        // The subscriber is "told it verbatim" — make that true by delivering the
        // reason as a notification, not merely storing reject_reason on a row the
        // member endpoint never returned.
        if (! empty($p['user_id'])) {
            try {
                (new \App\Libraries\Notifications\NotificationService())->notify(
                    (int) $p['user_id'], (int) $p['org_id'], 'payment_rejected',
                    'Payment claim not approved', $reason, '/subscription', ['in_app', 'email'],
                );
            } catch (\Throwable $e) {
                log_message('error', '[PaymentController:reject] notify failed: ' . $e->getMessage());
            }
        }

        return $this->ok(['state' => 'rejected', 'reason' => $reason]);
    }

    public function receipt(int $id)
    {
        $payment = model('App\Models\PaymentModel')->find($id);
        if (! $payment || empty($payment['receipt_path'])) {
            return problem(404, 'receipt_not_found', 'No receipt is attached to this payment.');
        }

        $base = realpath(WRITEPATH . 'uploads/payment_receipts');
        $path = realpath(WRITEPATH . 'uploads/payment_receipts/' . $payment['receipt_path']);
        if (! $base || ! $path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return problem(404, 'receipt_not_found', 'No receipt is attached to this payment.');
        }

        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($payment['receipt_name'] ?: 'receipt')) ?: 'receipt';

        return $this->response
            ->setHeader('Content-Type', $payment['receipt_mime'] ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', 'inline; filename="' . $fileName . '"')
            ->setHeader('Cache-Control', 'no-store, private')
            ->setBody(file_get_contents($path));
    }
}
