<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;

/** Read-only, staff-authorised review of actual published notice records. */
class QualityController extends BaseApiController
{
    public function index()
    {
        $db = db_connect();
        $base = static fn () => $db->table('notices')->where('status', 'published')->where('canonical_id', null);
        $total = $base()->countAllResults();
        $missingClosing = $base()->where('closing_at', null)->countAllResults();
        $missingReference = $base()->groupStart()->where('reference', null)->orWhere('reference', '')->groupEnd()->countAllResults();
        $missingDocuments = $base()->where('documents_count', 0)->countAllResults();
        $unverified = $base()->where('verified', 0)->countAllResults();
        $officialIds = [];
        foreach ($db->table('feed_sources')->select('id, slug, url')->get()->getResultArray() as $source) {
            $host = strtolower((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
            if (in_array($host, ['tendernotices.lk', 'www.tendernotices.lk', 'promise.lk', 'www.promise.lk', 'tenderlive.lk', 'www.tenderlive.lk'], true)) {
                $officialIds[] = (int) $source['id'];
            }
        }
        $unapproved = $base()->where('source_id IS NOT NULL', null, false);
        if ($officialIds !== []) $unapproved->whereNotIn('source_id', $officialIds);
        $unapprovedCount = $unapproved->countAllResults();

        $rows = $base()->select('id, title, reference, slug, closing_at, verified, documents_count, source_id')
            ->groupStart()->where('closing_at', null)->orWhere('verified', 0)->orWhere('documents_count', 0)
                ->orWhere('reference', null)->orWhere('reference', '');
        if ($officialIds !== []) $rows->orWhereNotIn('source_id', $officialIds);
        else $rows->orWhere('source_id IS NOT NULL', null, false);
        $rows = $rows->groupEnd()
            ->orderBy('updated_at', 'DESC')->limit(50)->get()->getResultArray();
        $issues = [];
        foreach ($rows as $row) {
            $flags = [];
            if (empty($row['closing_at'])) $flags[] = 'Missing closing date';
            if (empty($row['reference'])) $flags[] = 'Missing reference';
            if ((int) $row['documents_count'] === 0) $flags[] = 'No documents';
            if ((int) $row['verified'] === 0) $flags[] = 'Unverified';
            if ($row['source_id'] !== null && ! in_array((int) $row['source_id'], $officialIds, true)) $flags[] = 'Unapproved automated source';
            $issues[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'reference' => $row['reference'],
                'slug' => $row['slug'], 'flags' => $flags];
        }
        return $this->ok(['total_published' => $total, 'missing_closing' => $missingClosing,
            'missing_reference' => $missingReference, 'missing_documents' => $missingDocuments,
            'unverified' => $unverified, 'unapproved_source' => $unapprovedCount,
            'recent_issues' => $issues, 'checked_at' => date('c')]);
    }
}
