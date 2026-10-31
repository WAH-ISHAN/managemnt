<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Plans;

/**
 * Does this plan include the capability?
 *
 * This filter originally called service('tenant') and service('entitlements') —
 * neither of which existed, so every tenant-scoped request returned 500. It now
 * reads $request->orgId (set by the tenant filter) and the plan matrix. A
 * service locator will happily hand you null and let you find out in production.
 */
class Entitlement implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $capability = $arguments[0] ?? '';
        $orgId      = (int) ($request->orgId ?? 0);

        // Entitlements are re-read server-side, not trusted from the token, so a
        // downgrade or suspension takes effect on the very next request.
        $org = model('App\Models\OrganisationModel')->find($orgId);
        if (! $org) {
            return problem(403, 'no_org_context', 'This account is not attached to an organisation.');
        }

        $plan   = (string) $org['plan'];
        $status = (string) $org['sub_status'];
        $plans  = config(Plans::class);

        // A paid subscription lapses on its own renewal date. Nothing else in the
        // system ever writes 'expired', so without this a term was granted once
        // and never reclaimed — a confirmed 3-month payment gave access forever.
        // renews_at is only set for paid terms; publisher/free orgs leave it NULL
        // and are unaffected. Detected lapse is persisted once so the admin views,
        // the paywall transformer and the next login all agree.
        $renews = $org['renews_at'] ?? null;
        if ($plan !== 'free' && $plan !== 'staff' && $status !== 'expired'
            && $renews !== null && strtotime((string) $renews) < time()) {
            model('App\Models\OrganisationModel')->update($orgId, ['sub_status' => 'expired']);
            $status = 'expired';
        }

        // An ended trial/subscription falls back to the FREE plan's
        // capabilities (the feed and alert profiles, as the subscription page
        // promises) instead of locking the member out of everything.
        if ($plan !== 'staff' && $status === 'expired' && $plans->allows('free', $capability)) {
            $request->plan = 'free';
            $request->org  = $org;

            return null;
        }

        if ($plan !== 'staff' && $status === 'expired') {
            return problem(402, 'subscription_expired', 'Your TenderHub trial or subscription has ended. Please renew to continue using this feature.', [
                'capability' => $capability,
                'plan'       => $plan,
                'upgrade_to' => $plans->upgradeTo[$capability] ?? 'business',
            ]);
        }

        if (! $plans->allows($plan, $capability)) {
            return problem(402, 'plan_required', 'Your plan does not include this.', [
                'capability' => $capability,
                'plan'       => $plan,
                'upgrade_to' => $plans->upgradeTo[$capability] ?? 'business',
            ]);
        }

        if ($plan !== 'staff' && in_array($capability, ['publish', 'evaluation', 'award'], true)) {
            $paid = db_connect()->table('payments')
                ->where('org_id', $orgId)
                ->where('state', 'confirmed')
                ->where('amount >', 0)
                ->whereNotIn('method', ['trial'])
                ->whereNotIn('term', ['trial'])
                ->countAllResults();
            if ($paid < 1) {
                return problem(402, 'paid_plan_required', 'Tender publishing is available only after a paid TenderHub plan is active.', [
                    'capability' => $capability,
                    'plan'       => $plan,
                    'upgrade_to' => 'publish',
                ]);
            }
        }

        $request->plan = $plan;
        $request->org  = $org;

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
