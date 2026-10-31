<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Seo\AhrefsBacklinkProvider;
use App\Libraries\Seo\BacklinkService;
use App\Libraries\Seo\BacklinkVerifier;
use CodeIgniter\HTTP\ResponseInterface;

class BacklinkController extends BaseApiController
{
    private function ready(): ?ResponseInterface
    {
        return db_connect()->tableExists('seo_backlinks') ? null : problem(503, 'backlink_monitoring_not_configured', 'Run the backlink monitoring migration first.');
    }

    public function index(): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        return $this->ok((new BacklinkService())->dashboard(), ['now' => date('c')]);
    }

    public function create(): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        try { $saved = (new BacklinkService())->save($this->request->getJSON(true) ?? $this->request->getPost(), 'manual', (int) $this->request->userId); }
        catch (\InvalidArgumentException $e) { return problem(422, 'validation_failed', $e->getMessage()); }
        service('eventLedger')->record('seo_backlink', $saved['id'], $saved['created'] ? 'seo.backlink_created' : 'seo.backlink_updated', 'Backlink submitted manually.');
        return $this->response->setStatusCode($saved['created'] ? 201 : 200)->setJSON(['status' => $saved['created'] ? 201 : 200, 'data' => $saved]);
    }

    public function import(): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid() || $file->getSize() > 5 * 1024 * 1024 || strtolower($file->getClientExtension()) !== 'csv') return problem(422, 'invalid_csv', 'Upload a valid CSV file no larger than 5 MB.');
        try { $summary = (new BacklinkService())->importCsv((string) file_get_contents($file->getTempName()), (int) $this->request->userId, $file->getClientName()); }
        catch (\Throwable $e) { return problem(422, 'csv_import_failed', $e->getMessage()); }
        service('eventLedger')->record('seo_backlink', 0, 'seo.backlinks_imported', 'Google Search Console Links CSV imported.', $summary);
        return $this->ok($summary);
    }

    public function syncProvider(): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        try { $summary = (new AhrefsBacklinkProvider())->sync(new BacklinkService(), (int) $this->request->userId); }
        catch (\Throwable $e) { log_message('error', 'Backlink provider sync failed: {message}', ['message' => $e->getMessage()]); return problem(503, 'provider_sync_failed', $e->getMessage()); }
        service('eventLedger')->record('seo_backlink', 0, 'seo.provider_synced', 'Approved Ahrefs backlink data synchronized.', $summary);
        return $this->ok($summary);
    }

    public function verify(int $id): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        try { $result = (new BacklinkVerifier())->verify($id); }
        catch (\InvalidArgumentException $e) { return problem(404, 'not_found', $e->getMessage()); }
        catch (\Throwable $e) { log_message('error', 'Backlink verification failed for {id}: {message}', ['id' => $id, 'message' => $e->getMessage()]); return problem(502, 'verification_failed', 'The linking page could not be checked safely.'); }
        service('eventLedger')->record('seo_backlink', $id, 'seo.backlink_checked', 'Backlink verification completed: ' . $result['outcome'] . '.', ['outcome' => $result['outcome'], 'http_status' => $result['http_status']]);
        return $this->ok($result);
    }

    public function export()
    {
        if ($error = $this->ready()) return $error;
        $rows = db_connect()->table('seo_backlinks')->orderBy('id', 'ASC')->get()->getResultArray();
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['Source URL', 'Target URL', 'Referring domain', 'Anchor text', 'Attributes', 'Status', 'First seen', 'Last seen', 'Last checked', 'HTTP status']);
        foreach ($rows as $r) fputcsv($stream, \App\Libraries\SafeCsv::row([$r['source_url'], $r['target_url'], $r['referring_domain'], $r['anchor_text'], $r['link_attributes'], $r['status'], $r['first_seen_at'], $r['last_seen_at'], $r['last_checked_at'], $r['http_status']]));
        rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
        service('eventLedger')->record('seo_backlink', 0, 'seo.backlinks_exported', count($rows) . ' backlink records exported as CSV.');
        return $this->response->setContentType('text/csv')->setHeader('Content-Disposition', 'attachment; filename="tenderhub-backlinks.csv"')->setBody($csv);
    }

    public function saveOutreach(?int $id = null): ResponseInterface
    {
        if ($error = $this->ready()) return $error;
        $in = $this->request->getJSON(true) ?? $this->request->getPost();
        $name = trim((string) ($in['name'] ?? '')); $url = BacklinkService::normalizeUrl((string) ($in['website_url'] ?? ''));
        $allowed = ['prospect', 'researching', 'contacted', 'responded', 'accepted', 'declined', 'closed'];
        $status = (string) ($in['status'] ?? 'prospect');
        if ($name === '' || $url === null || ! in_array($status, $allowed, true)) return problem(422, 'validation_failed', 'Name, valid website URL and a supported outreach status are required.');
        $clean = static fn ($v, $n) => ($x = trim(strip_tags((string) $v))) === '' ? null : mb_substr($x, 0, $n);
        $data = ['name' => mb_substr($name, 0, 190), 'website_url' => $url, 'contact_name' => $clean($in['contact_name'] ?? null, 190),
            'contact_email' => filter_var($in['contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ? strtolower((string) $in['contact_email']) : null,
            'status' => $status, 'editorial_response' => $clean($in['editorial_response'] ?? null, 5000), 'notes' => $clean($in['notes'] ?? null, 5000),
            'last_contacted_at' => ! empty($in['last_contacted_at']) && strtotime($in['last_contacted_at']) ? date('Y-m-d H:i:s', strtotime($in['last_contacted_at'])) : null,
            'follow_up_at' => ! empty($in['follow_up_at']) && strtotime($in['follow_up_at']) ? date('Y-m-d H:i:s', strtotime($in['follow_up_at'])) : null,
            'updated_at' => date('Y-m-d H:i:s')];
        $db = db_connect();
        if ($id) { if ($db->table('seo_outreach_prospects')->where('id', $id)->countAllResults() !== 1) return problem(404, 'not_found', 'Outreach prospect not found.'); $db->table('seo_outreach_prospects')->where('id', $id)->update($data); }
        else { $data += ['created_by' => (int) $this->request->userId, 'created_at' => date('Y-m-d H:i:s')]; $db->table('seo_outreach_prospects')->insert($data); $id = (int) $db->insertID(); }
        service('eventLedger')->record('seo_backlink', $id, 'seo.outreach_saved', 'Legitimate outreach prospect updated.', ['status' => $status]);
        return $this->ok(['id' => $id]);
    }
}
