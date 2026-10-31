<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Transformers\NoticeTransformer;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Social marketing console: the tenders TenderHub collected on one day.
 *
 * "Today's Tenders" in the console is this list and nothing else. A row is a
 * real `notices` tender first collected (first_seen_at, else created_at) on
 * the requested Asia/Colombo calendar day. The social engine acts only on ids
 * read from here and re-reads each tender from the public API before it posts,
 * so a tender that is not publicly published can never be promoted.
 *
 * Brand rule (AGENTS.md §4): the upstream source URL is never returned; the
 * only source designation is NoticeTransformer::sourceLabel().
 */
class SocialController extends BaseApiController
{
    public const ZONE  = 'Asia/Colombo';
    public const LIMIT = 500;

    /** Server-to-server discovery uses the same collection day as the console. */
    public function automationTenders(): ResponseInterface
    {
        $expected = (string) (getenv('INGEST_SECRET_KEY') ?: '');
        $provided = $this->request->getHeaderLine('X-Marketing-Key');
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return problem(401, 'unauthorized', 'Invalid marketing service key.');
        }
        return $this->todayTenders();
    }

    /** GET /api/v1/admin/social/today-tenders?date=YYYY-MM-DD */
    public function todayTenders(): ResponseInterface
    {
        $zone = new \DateTimeZone(self::ZONE);
        $date = trim((string) ($this->request->getGet('date') ?? ''));
        if ($date === '') {
            $date = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
        }
        // Pinned format: relative strings ("today", "next tuesday") are refused.
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone) : false;
        if (! $day || $day->format('Y-m-d') !== $date) {
            return problem(422, 'validation_failed', 'date must be a calendar date in YYYY-MM-DD format.');
        }

        $utc  = new \DateTimeZone('UTC');
        $from = $day->setTimezone($utc)->format('Y-m-d H:i:s');
        $to   = $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        $db    = db_connect();
        $scope = static function ($b) use ($db, $from, $to) {
            $collected = 'COALESCE(n.first_seen_at, n.created_at)';
            $b->where('n.kind', 'tender')
                ->where('n.canonical_id', null)
                ->where("{$collected} >= " . $db->escape($from), null, false)
                ->where("{$collected} < " . $db->escape($to), null, false);
        };

        // Count and list share one constraint on two independent builders.
        $countBuilder = $db->table('notices n');
        $scope($countBuilder);
        $total = $countBuilder->countAllResults();

        $listBuilder = $db->table('notices n')
            ->select('n.id, n.reference, n.slug, n.title, n.summary, n.status, n.sector, n.org_id, n.source_url,
                n.estimated_value, n.currency, n.document_fee, n.bid_security, n.documents_count,
                n.published_at, n.closing_at, n.opening_at, n.first_seen_at, n.created_at, n.public_since,
                n.raw_organization, n.raw_category, n.raw_district, n.raw_location,
                a.name AS authority_name, c.name AS category_name, d.name AS district_name, p.name AS province_name')
            ->select("(SELECT COUNT(*) FROM notice_documents nd WHERE nd.notice_id = n.id
                AND (nd.kind = 'paper_ad' OR nd.mime LIKE 'image/%')) AS image_count", false)
            ->join('authorities a', 'a.id = n.authority_id', 'left')
            ->join('categories c', 'c.id = n.category_id', 'left')
            ->join('districts d', 'd.id = n.district_id', 'left')
            ->join('provinces p', 'p.id = d.province_id', 'left');
        $scope($listBuilder);
        $page = max(1, (int) $this->request->getGet('page'));
        $rows = $listBuilder->orderBy('COALESCE(n.first_seen_at, n.created_at)', 'DESC', false)
            ->orderBy('n.id', 'DESC')->limit(self::LIMIT, ($page - 1) * self::LIMIT)->get()->getResultArray();

        $base = rtrim((string) (env('APP_BASE_URL') ?: 'https://tenderhub.lk'), '/');

        return $this->ok(array_map(fn (array $row): array => $this->shape($row, $base), $rows), [
            'date'     => $date,
            'timezone' => self::ZONE,
            'window'   => ['from_utc' => $from, 'to_utc' => $to],
            'total'    => $total,
            'returned' => count($rows),
            'limit'    => self::LIMIT,
            'page'     => $page,
            'pages'    => (int) ceil($total / self::LIMIT),
        ]);
    }

    private function shape(array $row, string $base): array
    {
        $public   = $row['status'] === 'published';
        $closing  = strtotime((string) ($row['closing_at'] ?? '')) ?: 0;
        $hasImage = (int) $row['image_count'] > 0;

        // Why this tender cannot be promoted yet, if it cannot. The engine
        // re-checks all of these at publish time; this is for the console.
        $blocker = null;
        if (! $public) {
            $blocker = 'Not publicly published on TenderHub (status: ' . $row['status'] . ').';
        } elseif ($closing === 0) {
            $blocker = 'No confirmed closing date.';
        } elseif ($closing <= time()) {
            $blocker = 'Tender has closed.';
        }

        return [
            'id'              => (int) $row['id'],
            'reference'       => $row['reference'],
            'title'           => $row['title'],
            'summary'         => $row['summary'],
            'organization'    => $row['authority_name'] ?: ($row['raw_organization'] ?: null),
            'category'        => $row['category_name'] ?: ($row['raw_category'] ?: null),
            'location'        => [
                'district' => $row['district_name'] ?: ($row['raw_district'] ?: null),
                'province' => $row['province_name'] ?: null,
                'detail'   => $row['raw_location'] ?: null,
            ],
            'sector'          => $row['sector'],
            'estimated_value' => $row['estimated_value'] !== null ? (float) $row['estimated_value'] : null,
            'currency'        => $row['currency'] ?: 'LKR',
            'document_fee'    => $row['document_fee'] !== null ? (float) $row['document_fee'] : null,
            'bid_security'    => $row['bid_security'] !== null ? (float) $row['bid_security'] : null,
            'documents_count' => (int) $row['documents_count'],
            'published_at'    => $row['published_at'],
            'closing_at'      => $row['closing_at'],
            'opening_at'      => $row['opening_at'],
            'collected_at'    => $row['first_seen_at'] ?: $row['created_at'],
            'public_since'    => $row['public_since'],
            'status'          => $row['status'],
            'live_status'     => NoticeTransformer::liveStatus($row),
            'source_label'    => NoticeTransformer::sourceLabel($row),
            'tender_url'      => $public && $row['slug'] ? $base . '/tenders/' . rawurlencode((string) $row['slug']) : null,
            'has_image'       => $hasImage,
            'promotable'      => $blocker === null,
            'blocker'         => $blocker,
        ];
    }
}
