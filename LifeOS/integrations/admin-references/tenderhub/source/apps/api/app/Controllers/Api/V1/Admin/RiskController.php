<?php
namespace App\Controllers\Api\V1\Admin;
use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Procurement RISK SIGNALS — review flags, never accusations. Each signal names
 * what was observed, the source, and a severity for a human to review.
 *
 * Every signal is computed from real rows on each request. `meta.checks` says
 * what each check examined, so "no signals" can be told apart from "nothing
 * was examined".
 */
class RiskController extends BaseApiController
{
    /** A public tender closing this soon after publication is worth a look. */
    public const SHORT_WINDOW_DAYS = 7;
    /** Published-tender lookback for the bidding-window check. */
    public const LOOKBACK_DAYS = 90;

    public function signals(): ResponseInterface
    {
        $db = db_connect();
        $signals = [];

        // Native e-procurement (procurements / submissions / addenda / awards).
        // GROUP BY lists every selected column, so it is valid under MySQL's
        // ONLY_FULL_GROUP_BY as well as SQLite.
        $single = $db->query("SELECT p.id, n.reference, COUNT(s.id) AS c
            FROM procurements p JOIN notices n ON n.id = p.notice_id
            JOIN submissions s ON s.procurement_id = p.id
            WHERE p.stage_idx >= 4 GROUP BY p.id, n.reference HAVING COUNT(s.id) = 1")->getResultArray();
        foreach ($single as $r) {
            $signals[] = ['signal' => 'single_bidder', 'severity' => 'medium', 'ref' => $r['reference'], 'source' => 'TenderHub e-Procurement',
                'reason' => 'Only one bid was received for an opened tender.', 'review_state' => 'open'];
        }
        $changes = $db->query("SELECT p.id, n.reference, COUNT(a.id) AS c
            FROM procurements p JOIN notices n ON n.id = p.notice_id
            JOIN addenda a ON a.procurement_id = p.id GROUP BY p.id, n.reference HAVING COUNT(a.id) >= 3")->getResultArray();
        foreach ($changes as $r) {
            $signals[] = ['signal' => 'repeated_date_changes', 'severity' => 'medium', 'ref' => $r['reference'], 'source' => 'TenderHub e-Procurement',
                'reason' => "The closing date/terms changed {$r['c']} times via addenda.", 'review_state' => 'open'];
        }
        $concentration = $db->query("SELECT o.id, o.name, COUNT(a.id) AS c FROM awards a
            JOIN organisations o ON o.id = a.supplier_org_id GROUP BY o.id, o.name HAVING COUNT(a.id) >= 3")->getResultArray();
        foreach ($concentration as $r) {
            $signals[] = ['signal' => 'supplier_concentration', 'severity' => 'low', 'ref' => $r['name'], 'source' => 'TenderHub e-Procurement',
                'reason' => "One supplier holds {$r['c']} awards.", 'review_state' => 'open'];
        }

        // Published tender notices (the bulk of TenderHub's data): an active
        // bidding window under SHORT_WINDOW_DAYS from publication to closing.
        // This is deliberately a review signal, not a compliance finding:
        // the applicable minimum depends on procurement method, and notices
        // do not always carry the document-availability timestamp.
        $since = date('Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * 86400);
        $now = date('Y-m-d H:i:s');
        $published = static fn () => $db->table('notices')
            ->where('status', 'published')->where('kind', 'tender')->where('canonical_id', null)
            ->where('closing_at IS NOT NULL', null, false)
            ->where('closing_at >', $now)
            ->where('COALESCE(published_at, first_seen_at, created_at) >= ' . $db->escape($since), null, false);
        $examined = $published()->countAllResults();
        $short = [];
        foreach ($published()->select('id, reference, slug, title, closing_at, COALESCE(published_at, first_seen_at, created_at) AS opened_at', false)
            ->orderBy('closing_at', 'DESC')->get()->getResultArray() as $n) {
            $seconds = strtotime((string) $n['closing_at']) - strtotime((string) $n['opened_at']);
            if ($seconds <= 0 || $seconds >= self::SHORT_WINDOW_DAYS * 86400) {
                continue;   // non-positive windows are date errors, not short windows
            }
            $days = round($seconds / 86400, 1);
            $short[] = ['signal' => 'short_bid_window', 'severity' => $days < 3 ? 'high' : 'medium',
                'ref' => $n['reference'] ?: ('notice #' . $n['id']), 'source' => 'Published tender notice',
                'reason' => "Active tender: bids close {$days} day(s) after publication (under " . self::SHORT_WINDOW_DAYS . ' days). Verify the procurement method and when bidding documents became available: ' . $n['title'],
                'notice_slug' => $n['slug'], 'closing_at' => $n['closing_at'], 'review_state' => 'open'];
        }
        $signals = array_merge($signals, $short);

        $severityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($signals, static fn ($a, $b) => ($severityRank[$a['severity']] ?? 3) <=> ($severityRank[$b['severity']] ?? 3));

        $count = static fn (string $table) => $db->tableExists($table) ? $db->table($table)->countAllResults() : 0;

        return $this->ok($signals, [
            'count'  => count($signals),
            'note'   => 'Review signals only — not determinations of wrongdoing.',
            'checks' => [
                ['signal' => 'short_bid_window', 'examined' => $examined, 'found' => count($short),
                    'scope' => 'Currently open published tenders listed in the last ' . self::LOOKBACK_DAYS . ' days with a closing date'],
                ['signal' => 'single_bidder', 'examined' => $count('procurements'), 'found' => count($single), 'scope' => 'Opened e-procurement tenders'],
                ['signal' => 'repeated_date_changes', 'examined' => $count('procurements'), 'found' => count($changes), 'scope' => 'E-procurement tenders with addenda'],
                ['signal' => 'supplier_concentration', 'examined' => $count('awards'), 'found' => count($concentration), 'scope' => 'Recorded awards'],
            ],
        ]);
    }
}
