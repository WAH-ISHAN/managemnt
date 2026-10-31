<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Ingestion\AutoCategoriser;
use App\Libraries\Ingestion\DeduplicationService;

class IngestWebhookController extends BaseApiController
{
    /**
     * POST /api/v1/admin/ingest/push
     * Ingestion crawler receiver webhook. Authenticated via X-Ingest-Key.
     */
    public function push()
    {
        $ingestKey   = trim($this->request->getHeaderLine('X-Ingest-Key'));
        $expectedKey = (string) (getenv('INGEST_SECRET_KEY') ?: '');

        if ($expectedKey === '' || $ingestKey === '' || ! hash_equals($expectedKey, $ingestKey)) {
            return problem(401, 'invalid_ingest_key', 'Unauthorized ingestion agent.');
        }

        $items = $this->body();
        if (! is_array($items) || empty($items)) {
            return problem(422, 'empty_payload', 'Payload must contain a non-empty array of notice items.');
        }

        $crawlerService = new \App\Libraries\Ingestion\CrawlerIngestionService();
        $res = $crawlerService->ingestItems($items, null, 'push');

        return $this->ok([
            'processed' => count($items),
            'inserted'  => $res['items_inserted'],
            'skipped'   => $res['items_skipped'],
            'run_id'    => $res['run_id'],
        ]);
    }
}
