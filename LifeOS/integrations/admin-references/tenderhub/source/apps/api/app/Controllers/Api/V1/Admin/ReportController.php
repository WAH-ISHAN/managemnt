<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;

/**
 * EVERY figure on this dashboard is a real query.
 *
 * A dashboard that reports constants is worse than none, because people stop
 * checking the thing it was meant to watch.
 */
class ReportController extends BaseApiController
{
    public function health()
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $bidders = $db->table('organisations')->where('type', 'bidder')->countAllResults();
        $paying  = $db->table('organisations')->where('type', 'bidder')->where('sub_status', 'active')->countAllResults();
        $total   = $db->table('notices')->where('status', 'published')->countAllResults();
        $verified= $db->table('notices')->where('status', 'published')->where('verified', 1)->countAllResults();
        $lastFeed   = $db->table('feed_sources')->selectMax('last_fetch_at', 'm')->get()->getFirstRow('array');
        $lastNotice = $db->table('notices')->selectMax('updated_at', 'm')->get()->getFirstRow('array');
        $lastRun    = $db->table('ingestion_runs')->selectMax('completed_at', 'm')->get()->getFirstRow('array');

        $lastTs = max(
            strtotime($lastFeed['m'] ?? '1970-01-01'),
            strtotime($lastNotice['m'] ?? '1970-01-01'),
            strtotime($lastRun['m'] ?? '1970-01-01')
        );
        $minutesSinceFetch = ($lastTs > 0 && $lastTs > strtotime('2020-01-01')) ? (int) max(0, round((time() - $lastTs) / 60)) : null;

        return $this->ok([
            'catalogue' => [
                'live' => $db->table('notices')->where('status', 'published')->where('closing_at >=', $now)->countAllResults(),
                'archived' => $db->table('notices')->where('status', 'published')->where('closing_at <', $now)->countAllResults(),
                'added_today' => $db->table('notices')->where('created_at >=', date('Y-m-d 00:00:00'))->countAllResults(),
                'verified_pct' => $total ? round($verified / $total * 100, 1) : 0,
                'awaiting_review' => $db->table('notices')->where('status', 'unverified')->countAllResults(),
                'minutes_since_fetch' => $minutesSinceFetch,
            ],

            'accounts' => [
                'bidders' => $bidders,
                'paying_bidders' => $paying,
                'conversion_pct' => $bidders ? round($paying / $bidders * 100, 1) : 0,
                'publishers' => $db->table('organisations')->where('type', 'company')->countAllResults(),
                'awaiting_payment' => $db->table('payments')->where('state', 'claimed')->countAllResults(),
                'unverified_orgs' => $db->table('organisations')->where('verify_state', 'unverified')->countAllResults(),
            ],
            'engagement' => [
                'active_alert_profiles' => $db->table('alert_profiles')->where('active', 1)->countAllResults(),
                'tenders_in_pipelines' => $db->table('bids')->countAllResults(),
                'submissions' => $db->table('submissions')->countAllResults(),
                'awards' => $db->table('awards')->countAllResults(),
            ],
        ]);
    }

    public function coverage()
    {
        return $this->ok(db_connect()->table('notices')
            ->select('districts.name AS district, COUNT(*) AS n')
            ->join('districts', 'districts.id = notices.district_id', 'left')
            ->where('notices.status', 'published')
            ->groupBy('districts.name')->orderBy('n', 'DESC')->get()->getResultArray());
    }

    /** Authentic, read-only automation telemetry for the Sri Lankan calendar day. */
    public function automation()
    {
        try {
        $db = db_connect();
        $localTimezone = new \DateTimeZone('Asia/Colombo');
        $utcTimezone = new \DateTimeZone('UTC');
        $startLocal = new \DateTimeImmutable('today', $localTimezone);
        $endLocal = $startLocal->modify('+1 day');
        $startUtc = $startLocal->setTimezone($utcTimezone)->format('Y-m-d H:i:s');
        $endUtc = $endLocal->setTimezone($utcTimezone)->format('Y-m-d H:i:s');

        $sourceRows = $db->table('feed_sources')->orderBy('id', 'ASC')->get()->getResultArray();
        $officialSources = [];
        foreach ($sourceRows as $source) {
            $slug = strtolower((string) ($source['slug'] ?? ''));
            $host = strtolower((string) parse_url($source['url'] ?? '', PHP_URL_HOST));
            $isPress = $slug === 'tendernotices-lk' || in_array($host, ['tendernotices.lk', 'www.tendernotices.lk'], true);
            $isEgp = $slug === 'promise' || in_array($host, ['promise.lk', 'www.promise.lk'], true);
            if (! $isPress && ! $isEgp) {
                continue;
            }
            $source['public_name'] = $isPress ? 'Official Gazette / National Press' : 'TenderHub e-Procurement';
            $officialSources[] = $source;
        }
        $sourceIds = array_map(static fn (array $source): int => (int) $source['id'], $officialSources);

        $noticeCount = function (?string $status = null, ?int $verified = null) use ($db, $sourceIds, $startUtc, $endUtc): int {
            if ($sourceIds === []) {
                return 0;
            }
            $builder = $db->table('notices')->whereIn('source_id', $sourceIds)
                ->where('created_at >=', $startUtc)->where('created_at <', $endUtc);
            if ($status !== null) {
                $builder->where('status', $status);
            }
            if ($verified !== null) {
                $builder->where('verified', $verified);
            }
            return $builder->countAllResults();
        };

        $todayCollected = $noticeCount();
        $todayPublished = $noticeCount('published');
        $todayReview = $noticeCount('unverified');
        $todayVerified = $noticeCount(null, 1);
        $missingClosing = $sourceIds === [] ? 0 : $db->table('notices')
            ->whereIn('source_id', $sourceIds)
            ->where('created_at >=', $startUtc)->where('created_at <', $endUtc)
            ->where('closing_at', null)
            ->countAllResults();
        $withDocuments = $sourceIds === [] ? 0 : (int) (($db->table('notices')
            ->select('COUNT(DISTINCT notices.id) AS n', false)
            ->join('notice_documents', 'notice_documents.notice_id = notices.id', 'inner')
            ->whereIn('notices.source_id', $sourceIds)
            ->where('notices.created_at >=', $startUtc)->where('notices.created_at <', $endUtc)
            ->get()->getFirstRow('array')['n'] ?? 0));

        $runsBuilder = $db->table('ingestion_runs')->select(
            'COUNT(*) AS runs, '
            . 'COALESCE(SUM(items_found), 0) AS items_found, '
            . 'COALESCE(SUM(items_inserted), 0) AS items_inserted, '
            . 'COALESCE(SUM(items_updated), 0) AS items_updated, '
            . 'COALESCE(SUM(items_duplicate), 0) AS items_duplicate, '
            . 'COALESCE(SUM(items_skipped), 0) AS items_skipped, '
            . 'COALESCE(SUM(parse_failures), 0) AS parse_failures, '
            . 'COALESCE(SUM(http_failures), 0) AS http_failures',
            false
        );
        if ($sourceIds !== []) {
            // Scraper rows describe the hub collection that the sync row already
            // totals; counting both would double every found/duplicate figure.
            $runsBuilder->whereIn('source_id', $sourceIds)
                ->groupStart()->where('trigger !=', 'scraper')->orWhere('trigger', null)->groupEnd()
                ->groupStart()
                    ->groupStart()->where('started_at >=', $startUtc)->where('started_at <', $endUtc)->groupEnd()
                    ->orGroupStart()->where('started_at', null)->where('created_at >=', $startUtc)->where('created_at <', $endUtc)->groupEnd()
                ->groupEnd();
            $runSummary = $runsBuilder->get()->getFirstRow('array') ?? [];
        } else {
            $runSummary = [];
        }

        $sources = [];
        foreach ($officialSources as $source) {
            $id = (int) $source['id'];
            $lastRun = $db->table('ingestion_runs')->where('source_id', $id)
                ->groupStart()->where('trigger !=', 'sync')->orWhere('trigger', null)->groupEnd()
                ->orderBy('id', 'DESC')->get(1)->getFirstRow('array');
            $todayForSource = $db->table('notices')->where('source_id', $id)
                ->where('created_at >=', $startUtc)->where('created_at <', $endUtc)->countAllResults();
            $totalForSource = $db->table('notices')->where('source_id', $id)->countAllResults();
            $enabled = (bool) ($source['active'] ?? false) && ! (bool) ($source['paused'] ?? false);
            $state = ! $enabled ? 'paused'
                : (! empty($source['last_error']) ? 'failing'
                : (empty($source['last_fetch_at']) ? 'idle' : 'healthy'));
            $sources[] = [
                'id' => $id,
                'name' => $source['public_name'],
                'state' => $state,
                'today_collected' => $todayForSource,
                'total_collected' => $totalForSource,
                'last_fetch_at' => $source['last_fetch_at'] ?? null,
                'last_success_at' => $source['last_success_at'] ?? null,
                'last_error' => $source['last_error'] ?? null,
                'last_run_status' => $lastRun['status'] ?? null,
            ];
        }

        $recentRuns = [];
        if ($sourceIds !== []) {
            $runRows = $db->table('ingestion_runs')->whereIn('source_id', $sourceIds)
                ->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();
            $sourceNames = [];
            foreach ($officialSources as $source) {
                $sourceNames[(int) $source['id']] = $source['public_name'];
            }
            foreach ($runRows as $run) {
                $recentRuns[] = [
                    'id' => (int) $run['id'],
                    'source' => ($run['trigger'] ?? null) === 'sync' ? 'TenderHub catalogue sync'
                        : ($sourceNames[(int) ($run['source_id'] ?? 0)] ?? 'Official Source'),
                    'status' => $run['status'],
                    'trigger' => $run['trigger'] ?? 'cli',
                    'started_at' => $run['started_at'] ?? $run['created_at'] ?? null,
                    'completed_at' => $run['completed_at'] ?? null,
                    'duration_ms' => $run['duration_ms'] === null ? null : (int) $run['duration_ms'],
                    'items_found' => (int) ($run['items_found'] ?? 0),
                    'items_inserted' => (int) ($run['items_inserted'] ?? 0),
                    'items_updated' => (int) ($run['items_updated'] ?? 0),
                    'items_duplicate' => (int) ($run['items_duplicate'] ?? 0),
                    'parse_failures' => (int) ($run['parse_failures'] ?? 0),
                    'http_failures' => (int) ($run['http_failures'] ?? 0),
                    'error_message' => $run['error_message'] ?? null,
                ];
            }
        }

        $summary = [
            'runs' => (int) ($runSummary['runs'] ?? 0),
            'items_found' => (int) ($runSummary['items_found'] ?? 0),
            'items_inserted' => (int) ($runSummary['items_inserted'] ?? 0),
            'items_updated' => (int) ($runSummary['items_updated'] ?? 0),
            'items_duplicate' => (int) ($runSummary['items_duplicate'] ?? 0),
            'items_skipped' => (int) ($runSummary['items_skipped'] ?? 0),
            'parse_failures' => (int) ($runSummary['parse_failures'] ?? 0),
            'http_failures' => (int) ($runSummary['http_failures'] ?? 0),
        ];

        return $this->ok([
            'window' => [
                'timezone' => 'Asia/Colombo',
                'date' => $startLocal->format('Y-m-d'),
                'generated_at' => (new \DateTimeImmutable('now', $localTimezone))->format(DATE_ATOM),
            ],
            'today' => [
                'collected' => $todayCollected,
                'published' => $todayPublished,
                'awaiting_review' => $todayReview,
                'verified' => $todayVerified,
                'with_documents' => $withDocuments,
                'missing_closing_date' => $missingClosing,
            ],
            'runs_today' => $summary,
            'sources' => $sources,
            'recent_runs' => $recentRuns,
        ]);
        } catch (\Throwable $error) {
            log_message('error', '[ReportController:automation] ' . $error->getMessage());
            return problem(500, 'automation_report_failed', 'Could not build automation telemetry.');
        }
    }

    /** Moderation queue. Each row is flagged with WHAT is missing, so a
     *  reviewer reads the gaps before the prose. */
    public function queue()
    {
        $rows = db_connect()->table('notices')
            ->select('notices.*, feed_sources.name AS source')
            ->join('feed_sources', 'feed_sources.id = notices.source_id', 'left')
            // "held" = collected while public publishing was switched off.
            ->whereIn('notices.status', ['unverified', 'held'])->orderBy('notices.created_at', 'DESC')
            ->limit(500)->get()->getResultArray();

        foreach ($rows as &$r) {
            $missing = [];
            foreach (['closing_at' => 'closing date', 'estimated_value' => 'value',
                      'district_id' => 'district', 'authority_id' => 'authority'] as $col => $label) {
                if (empty($r[$col])) {
                    $missing[] = $label;
                }
            }
            $r['missing'] = $missing;
        }

        return $this->ok($rows);
    }

    public function publishNotice(int $id)
    {
        $notice = model('App\Models\NoticeModel')->find($id);
        if (! $notice) {
            return problem(404, 'not_found', 'No such notice.');
        }
        if ($off = \App\Libraries\NoticePublishing::refuseIfDisabled()) {
            return $off;
        }

        // Refused outright. A wrong deadline published as fact is the one error
        // that loses a customer permanently.
        if (empty($notice['closing_at'])) {
            return problem(409, 'no_closing_date', 'A notice cannot be published without a closing date.');
        }

        model('App\Models\NoticeModel')->update($id, [
            'status' => 'published', 'verified' => 1,
            'published_at' => $notice['published_at'] ?: date('Y-m-d H:i:s'),
            'public_since' => $notice['public_since'] ?? null ?: date('Y-m-d H:i:s'),
        ]);
        service('eventLedger')->record('notice', $id, 'notice.published', "Notice #{$id} published by staff #{$this->request->userId}", [
            'notice_id' => $id, 'previous_status' => $notice['status'], 'actor' => (int) $this->request->userId,
        ]);

        return $this->ok(['published' => true]);
    }

    /** MERGE, not delete. A URL already indexed keeps resolving and says where
     *  it went. */
    public function merge(int $id)
    {
        $canonicalId = (int) ($this->body()['canonical_id'] ?? 0);
        $model = model('App\Models\NoticeModel');

        if ($id === $canonicalId || ! $model->find($canonicalId) || ! $model->find($id)) {
            return problem(422, 'bad_merge', 'Give a different, existing canonical notice.');
        }

        $model->update($id, ['canonical_id' => $canonicalId, 'status' => 'withdrawn']);
        service('eventLedger')->record('notice', $id, 'notice.merged', "Notice #{$id} merged into #{$canonicalId} by staff #{$this->request->userId}", [
            'notice_id' => $id, 'canonical_id' => $canonicalId, 'actor' => (int) $this->request->userId,
        ]);

        return $this->ok(['merged_into' => $canonicalId]);
    }

    /**
     * "Healthy" means producing what THIS source normally produces, computed as
     * its own four-week weekly average. A source that has broken looks exactly
     * like a source having a quiet week unless its own baseline is watched.
     */
    public function sources()
    {
        $db   = db_connect();
        $rows = $db->table('feed_sources')->get()->getResultArray();

        foreach ($rows as &$s) {
            $thisWeek = $db->table('notices')->where('source_id', $s['id'])
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))->countAllResults();
            $total = $db->table('notices')->where('source_id', $s['id'])->countAllResults();
            $verified = $db->table('notices')->where('source_id', $s['id'])->where('verified', 1)->countAllResults();

            $baseline = (float) $s['weekly_baseline'];
            $s['this_week'] = $thisWeek;
            $s['baseline'] = $baseline;
            $s['verified_pct'] = $total ? round($verified / $total * 100, 1) : 0;
            $s['status'] = match (true) {
                (int) $s['active'] === 0 => 'paused',
                $s['last_error'] !== null => 'failing',
                $baseline > 0 && $thisWeek < $baseline * 0.5 => 'below_baseline',
                default => 'healthy',
            };
        }

        return $this->ok($rows);
    }

    /** Returns 501 with the reason, rather than pretending to queue something.
     *  The endpoint exists so the console can call it the day the worker lands. */
    public function runSource(int $id)
    {
        return problem(501, 'not_implemented', 'The crawl worker is not built yet.', [
            'design' => 'Fetch on a schedule, store the raw artefact, parse into a staging row, '
                . 'score confidence, auto-publish above the threshold and queue the rest.',
            'available_today' => ['php spark documents:mirror'],
        ]);
    }

    public function organisations()
    {
        $db   = db_connect();
        $rows = $db->table('organisations')
            ->select('organisations.*, districts.name AS district')
            ->join('districts', 'districts.id = organisations.district_id', 'left')
            ->orderBy('organisations.created_at', 'DESC')->get()->getResultArray();

        foreach ($rows as &$o) {
            $o['seats_used'] = $db->table('users')->where('org_id', $o['id'])->countAllResults();
            $o['tenders_published'] = $db->table('notices')->where('org_id', $o['id'])->where('status', 'published')->countAllResults();
            $o['bids_lodged'] = $db->table('submissions')->where('bidder_org_id', $o['id'])->countAllResults();
        }

        return $this->ok($rows);
    }

    public function verifyOrg(int $id)
    {
        $organisations = model('App\Models\OrganisationModel');
        if (! $organisations->find($id)) {
            return problem(404, 'not_found', 'No such organisation.');
        }

        // Verification is granted ONLY by approving a reviewed KYC submission
        // (Admin\KycController::review). This direct route used to mark any
        // organisation verified with no submission and no documents.
        if (($this->body()['state'] ?? 'verified') !== 'rejected') {
            return problem(409, 'kyc_review_required', 'Organisations are verified only by approving a reviewed KYC submission in Console → Verification.');
        }
        $state = 'rejected';
        $before = $organisations->find($id);
        $organisations->update($id, [
            'verify_state' => $state,
            'verified_at' => $state === 'verified' ? date('Y-m-d H:i:s') : null,
        ]);
        // A verification decision outside the KYC queue must still leave a trail.
        service('eventLedger')->record('org', $id, 'org.verify_state', "Organisation #{$id} set to {$state} by staff #{$this->request->userId} (direct, outside KYC review)", [
            'org_id' => $id, 'from' => $before['verify_state'] ?? null, 'to' => $state, 'actor' => (int) $this->request->userId,
        ]);

        return $this->ok(['verify_state' => $state]);
    }
}
