<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Ingestion\AdapterRegistry;
use App\Libraries\Ingestion\ImportRunner;

/**
 * Staff-only import control.
 *
 * Reached through the existing admin chain — auth-jwt, tenant, group:staff,
 * entitlement:admin — so it inherits the platform's authorisation rather than
 * inventing its own. Nothing here is reachable by a normal user.
 *
 * The "run now" action is deliberately BOUNDED. A full backfill of tens of
 * thousands of records must not be attached to an HTTP request: a web worker
 * would be held for the duration and the request would time out long before the
 * import finished. This endpoint therefore performs a short incremental sync of
 * the newest pages, which is the action an operator actually wants from a
 * console, and the CLI (`php spark tenders:import`) owns the long backfill.
 *
 * No destructive database operation is exposed here. Pause and resume flip a
 * flag; there is no delete, no truncate and no raw query surface.
 */
class IngestionController extends BaseApiController
{
    /** Hard ceiling on pages an HTTP-triggered run may walk. */
    private const ADMIN_MAX_PAGES = 5;

    private function getSourcesConfigFile(): string
    {
        $paths = [
            WRITEPATH . 'sources_config.json',
            ROOTPATH . '../../sl-tender-hub/sources_config.json',
            ROOTPATH . 'sl-tender-hub/sources_config.json',
            '/opt/tenderhub/sl-tender-hub/sources_config.json',
        ];
        foreach ($paths as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        return WRITEPATH . 'sources_config.json';
    }

    private function loadSourcesConfig(): array
    {
        $path = $this->getSourcesConfigFile();
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [
            'enabled_sources' => ['tendernotices', 'promise', 'tenderlive'],
            'credentials'     => [],
        ];
    }

    private function saveSourcesConfig(array $config): void
    {
        // Enforce strict allowlist on saved sources (AGENTS.md §4.1 & M-1)
        if (isset($config['enabled_sources']) && is_array($config['enabled_sources'])) {
            $config['enabled_sources'] = array_values(array_intersect($config['enabled_sources'], ['tendernotices', 'promise', 'tenderlive']));
        }
        $primary = WRITEPATH . 'sources_config.json';
        $json=json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($primary.'.tmp', $json, LOCK_EX) === false || !rename($primary.'.tmp',$primary)) {
            throw new \RuntimeException('Unable to persist automation configuration.');
        }
        @chmod($primary,0600);

        $secondary = '/opt/tenderhub/sl-tender-hub/sources_config.json';
        if (file_exists(dirname($secondary)) && is_writable(dirname($secondary))) {
            // Holds portal passwords: owner-only, like the primary copy.
            if (@file_put_contents($secondary, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
                @chmod($secondary, 0600);
            }
        }
        $local=ROOTPATH.'../../sl-tender-hub/sources_config.json';
        if (is_dir(dirname($local)) && is_writable(dirname($local))) {
            if (file_put_contents($local.'.tmp',$json,LOCK_EX)!==false) { rename($local.'.tmp',$local); @chmod($local,0600); }
        }
    }

    /** GET admin/ingest/sources — registry with health, permission, credentials, and toggle status. */
    public function sources()
    {
        $db   = db_connect();
        $rows = $db->table('feed_sources')->orderBy('name')->get()->getResultArray();
        $now = date('Y-m-d H:i:s');
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $config = $this->loadSourcesConfig();
        $enabledSources = $config['enabled_sources'] ?? ['tendernotices'];

        $out = [];
        foreach ($rows as $r) {
            $officialKey=$this->officialKey($r);
            if ($officialKey === null) continue;
            $last = $db->table('ingestion_runs')
                ->where('source_id', $r['id'])
                ->groupStart()->where('trigger !=', 'sync')->orWhere('trigger', null)->groupEnd()
                ->orderBy('id', 'DESC')->get(1)->getRowArray();

            $hostFilter = parse_url($r['url'] ?? '', PHP_URL_HOST);
            $countQuery = $db->table('notices');
            if ($hostFilter) {
                $countQuery->like('source_url', $hostFilter);
            }
            $totalForSource = $countQuery->countAllResults();

            $thisWeekQuery = $db->table('notices')->where('created_at >=', $sevenDaysAgo);
            if ($hostFilter) {
                $thisWeekQuery->like('source_url', $hostFilter);
            }
            $thisWeek = $thisWeekQuery->countAllResults();

            // Real last-fetch only. Never default to "now" — a never-fetched
            // source must not read as freshly fetched (Rule 5.2: authentic data,
            // no synthetic fallbacks).
            $lastFetch = $r['last_fetch_at'] ?? null;
            // Brand Concealment: disguise third-party brand names
            $isNationalPress = ($r['slug'] === 'tendernotices-lk' || stripos($r['name'], 'Tender Notices') !== false);
            $displayName = $isNationalPress ? 'National Print Press & Classifieds' : $r['name'];
            $cleanSlug   = $isNationalPress ? 'national-press' : $r['slug'];
            $sourceKey   = $officialKey;

            $isEnabled = in_array($sourceKey, $enabledSources,true) || in_array($r['slug'], $enabledSources,true);
            $isActive  = (bool) $r['active'] && !(bool) $r['paused'] && $isEnabled;

            // Status calculation:
            // Paused/disabled sources must never display false "failing" alarms.
            // Active sources on daily 5:00 AM SLST cadence are healthy unless unrecovered errors persist.
            if (! $isActive) {
                $status = 'paused';
            } elseif ($lastFetch === null) {
                // Active but never fetched — say so instead of showing "healthy".
                $status = 'idle';
            } elseif (! empty($r['last_error'])) {
                // last_fetch_at moves on every attempt, failed ones included, so
                // its age cannot hide an error: the latest collection failed.
                $status = 'failing';
            } else {
                $lastSuccess = $r['last_success_at'] ?? null;
                $status = ($lastSuccess === null || time() - strtotime($lastSuccess) > 2 * 86400) ? 'stale' : 'healthy';
            }

            if ($isNationalPress) {
                $creds = $config['credentials']['tendernotices'] ?? [];
                $credentialUrl = $creds['login_url'] ?? $r['url'] ?? 'https://www.tendernotices.lk/login';
            } else {
                $creds = $config['credentials'][$r['slug']] ?? $config['credentials'][$sourceKey] ?? [];
                $credentialUrl = $creds['login_url'] ?? $r['url'] ?? null;
            }
            $hasCreds = !empty($creds['email']) || !empty($creds['password']);
            if ($sourceKey === 'tenderlive' && filter_var(env('TENDERLIVE_CONFIGURED'), FILTER_VALIDATE_BOOLEAN)) $hasCreds = true;

            // Real verified share for this source — the fraction of its notices
            // marked verified — not a hardcoded 100. Null when there are none.
            $verifiedForSource = $hostFilter
                ? $db->table('notices')->like('source_url', $hostFilter)->where('verified', 1)->countAllResults()
                : $db->table('notices')->where('verified', 1)->countAllResults();
            $verifiedPct = $totalForSource > 0 ? (int) round($verifiedForSource / $totalForSource * 100) : null;

            // Whether a real in-process importer adapter exists for this source.
            $hasAdapter = AdapterRegistry::make($r['adapter'] ?? null) !== null;

            $out[] = [
                'id'               => (int) $r['id'],
                'name'             => $displayName,
                'slug'             => $cleanSlug,
                'source_key'       => $cleanSlug,
                'mode'             => $r['mode'],
                // Real configured baseline (0 when unset) — no invented "10".
                'baseline'         => (int) ($r['weekly_baseline'] ?? 0),
                'this_week'        => $thisWeek,
                'verified_pct'     => $verifiedPct,
                'status'           => $isActive ? $status : 'paused',
                'adapter'          => $r['adapter'] ?? null,
                'has_adapter'      => $hasAdapter,
                'active'           => $isActive,
                'paused'           => !$isActive,
                'has_credentials'  => $hasCreds,
                'credential_email' => $creds['email'] ?? null,
                'credential_url'   => $credentialUrl,
                'permission_state' => $isNationalPress ? 'granted' : ($r['permission_state'] ?? 'unverified'),
                'permission_note'  => $isNationalPress ? 'Primary verified print newspaper notice crawler' : ($r['permission_note'] ?? null),
                'can_run'          => $hasAdapter && ($r['permission_state'] ?? 'unverified') === 'granted' && $isActive,
                'cursor'           => $r['cursor'] ?? null,
                'last_fetch_at'    => $lastFetch,
                'last_success_at'  => $r['last_success_at'] ?? null,
                'last_error'       => $r['last_error'] ?? null,
                'last_run'         => $last === null ? null : $this->runShape($last),
            ];
        }

        return $this->ok($out);
    }

    /** GET admin/ingest/schedule -- current automation cadence saved for the VPS runner. */
    public function schedule()
    {
        $config = $this->loadSourcesConfig();

        return $this->ok($this->scheduleShape($config['automation_schedule'] ?? []));
    }

    /** POST admin/ingest/schedule -- update automation cadence for the VPS runner. */
    public function updateSchedule()
    {
        $body = $this->body();
        $mode = (string) ($body['mode'] ?? '');
        $hour = (int) ($body['hour'] ?? 5);

        if (! in_array($mode, ['hourly', 'every_4_hours', 'daily'], true)) {
            return problem(422, 'validation_failed', 'mode must be hourly, every_4_hours, or daily.');
        }
        if ($hour < 0 || $hour > 23) {
            return problem(422, 'validation_failed', 'hour must be between 0 and 23.');
        }

        $config = $this->loadSourcesConfig();
        $config['automation_schedule'] = [
            'mode' => $mode,
            'hour' => $mode === 'daily' ? $hour : null,
            'timezone' => 'Asia/Colombo',
            'updated_at' => date('c'),
        ];
        $this->saveSourcesConfig($config);

        service('eventLedger')->record(
            'source',
            0,
            'ingest.schedule_updated',
            sprintf('Ingestion schedule updated to %s', $mode),
            $config['automation_schedule']
        );

        return $this->ok($this->scheduleShape($config['automation_schedule']));
    }

    /** GET admin/ingest/live-run -- the Live (run now) request and the host's real run outcome. */
    public function liveRunStatus()
    {
        return $this->ok($this->liveRunShape());
    }

    /** Staff-only, bounded host telemetry and authentic database run history. */
    public function telemetry()
    {
        $read = static function (string $name): ?array {
            $path = '/var/run/tenderhub-monitoring/' . $name . '.json';
            if (!is_file($path) || filesize($path) > 131072) return null;
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? $data : null;
        };
        $rows = db_connect()->table('ingestion_runs')->select('ingestion_runs.*, feed_sources.name AS source_name, feed_sources.slug AS source_slug')
            ->join('feed_sources', 'feed_sources.id = ingestion_runs.source_id', 'left')->orderBy('ingestion_runs.id', 'DESC')->get(20)->getResultArray();
        return $this->ok([
            'pipeline' => $read('ingestion-status'),
            'protection' => $read('protection-status'),
            'audit' => $read('ingestion-audit'),
            'source_batch' => $read('tenderlive-batch'),
            'recent_runs' => array_map(fn(array $row): array => $this->runShape($row), $rows),
            'checked_at' => date('c'),
        ]);
    }

    /**
     * POST admin/ingest/live-run -- queue one immediate run of the production
     * automation pipeline. The API only writes a signed request; the host
     * worker (scripts/ingestion-live-run.sh, every minute) validates it and runs
     * the same locked pipeline as the schedule, so a Live run can never overlap
     * a scheduled one. The API never executes a shell command.
     */
    public function queueLiveRun()
    {
        $key = (string) env('INGEST_SECRET_KEY');
        $dir = $this->liveRunDir();
        if (strlen($key) < 16 || (! is_dir($dir) && ! @mkdir($dir, 0750, true)) || ! is_writable($dir)) {
            return problem(503, 'live_run_unavailable', 'Live automation runs are not configured on this deployment.');
        }
        $current = $this->liveRunShape();
        // The host run is capped at 45 minutes; an older running/waiting state means
        // the host stopped mid-run and must not block the button forever.
        $updated = strtotime((string) ($current['status']['updated_at'] ?? '')) ?: 0;
        $active = in_array($current['status']['state'] ?? null, ['running', 'waiting'], true) && time() - $updated < 3600;
        $queuedAt = strtotime((string) ($current['request']['queued_at'] ?? '')) ?: 0;
        if (($current['request'] !== null && time() - $queuedAt < 7200) || $active) {
            return problem(409, 'live_run_in_progress', 'A Live automation run is already queued or running. Wait for its result.');
        }

        $actorId = (int) ($this->request->userId ?? 0);
        $id = bin2hex(random_bytes(16));
        $created = time();
        $action = 'live_run';
        $request = [
            'id' => $id,
            'action' => $action,
            'actor_id' => $actorId,
            'created_epoch' => $created,
            'signature' => hash_hmac('sha256', implode('|', [$id, $action, $actorId, $created]), $key),
        ];
        $target = $dir . DIRECTORY_SEPARATOR . 'live-run-request.json';
        $temporary = $target . '.tmp';
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || ! @rename($temporary, $target)) {
            @unlink($temporary);
            return problem(503, 'live_run_queue_failed', 'The Live run request could not be queued.');
        }
        @chmod($target, 0600);

        service('eventLedger')->record('source', 0, 'ingest.live_run_queued', 'Staff queued a Live automation run.', [
            'request_id' => $id,
            'actor_id' => $actorId,
        ]);

        return $this->ok($this->liveRunShape());
    }

    private function liveRunDir(): string
    {
        return WRITEPATH . 'automation';
    }

    /** Only what the host recorded; nothing here is inferred or defaulted. */
    private function liveRunShape(): array
    {
        $read = static function (string $path): ?array {
            if (! is_file($path)) return null;
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? $data : null;
        };
        $request = $read($this->liveRunDir() . DIRECTORY_SEPARATOR . 'live-run-request.json');
        $status = $read($this->liveRunDir() . DIRECTORY_SEPARATOR . 'live-run-status.json');

        return [
            'request' => $request === null ? null : [
                'id' => (string) ($request['id'] ?? ''),
                'queued_at' => isset($request['created_epoch']) ? date('c', (int) $request['created_epoch']) : null,
            ],
            'status' => $status === null ? null : [
                'request_id' => $status['request_id'] ?? null,
                'state' => $status['state'] ?? null,
                'detail' => $status['detail'] ?? null,
                'started_at' => $status['started_at'] ?? null,
                'finished_at' => $status['finished_at'] ?? null,
                'exit_code' => isset($status['exit_code']) ? (int) $status['exit_code'] : null,
                'summary' => array_values(array_filter((array) ($status['summary'] ?? []), 'is_string')),
                'updated_at' => $status['updated_at'] ?? null,
            ],
        ];
    }

    /** POST admin/ingest/rest-api-key -- generate a one-time TenderHub REST feed key. */
    public function generateRestApiKey()
    {
        $body = $this->body();
        $url = trim((string) ($body['url'] ?? ''));

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return problem(422, 'validation_failed', 'A valid REST API URL is required before generating a key.');
        }
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https'], true)) {
            return problem(422, 'https_required', 'REST API sources must use HTTPS.');
        }

        $officialKey = $this->officialKey(['url' => $url]);
        if ($officialKey === null) {
            return problem(
                403,
                'source_not_allowed',
                'Tender automation may only generate keys for approved sources: tendernotices.lk, promise.lk and tenderlive.lk.'
            );
        }

        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $apiKey = sprintf(
            'th_ingest_%s_%s',
            $officialKey,
            rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')
        );

        $config = $this->loadSourcesConfig();
        $pending = is_array($config['pending_api_keys'] ?? null) ? $config['pending_api_keys'] : [];
        $now = time();
        foreach ($pending as $pendingToken => $row) {
            if ((int) ($row['expires_at'] ?? 0) <= $now) {
                unset($pending[$pendingToken]);
            }
        }
        $pending[$token] = [
            'hash' => hash('sha256', $apiKey),
            'official_key' => $officialKey,
            'url_host' => strtolower((string) parse_url($url, PHP_URL_HOST)),
            'created_at' => date('c', $now),
            'expires_at' => $now + 900,
        ];
        $config['pending_api_keys'] = $pending;
        $this->saveSourcesConfig($config);

        service('eventLedger')->record(
            'source',
            0,
            'ingest.rest_api_key_generated',
            sprintf('TenderHub REST API key generated for %s', $officialKey),
            ['official_key' => $officialKey]
        );

        return $this->ok([
            'api_key' => $apiKey,
            'api_key_token' => $token,
            'expires_at' => date('c', $now + 900),
        ], [], 201);
    }

    /** POST admin/ingest/rest-sources -- register an approved official JSON REST API feed. */
    public function createRestSource()
    {
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $apiKey = trim((string) ($body['api_key'] ?? ''));
        $apiKeyToken = trim((string) ($body['api_key_token'] ?? ''));
        $authHeader = strtolower(trim((string) ($body['auth_header'] ?? 'authorization')));
        $baseline = max(0, (int) ($body['weekly_baseline'] ?? 0));

        if ($name === '' || mb_strlen($name) > 160) {
            return problem(422, 'validation_failed', 'Source name is required and must be 160 characters or fewer.');
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return problem(422, 'validation_failed', 'A valid REST API URL is required.');
        }
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https'], true)) {
            return problem(422, 'https_required', 'REST API sources must use HTTPS.');
        }

        $officialKey = $this->officialKey(['url' => $url]);
        if ($officialKey === null) {
            return problem(
                403,
                'source_not_allowed',
                'Tender automation may only import from approved sources: tendernotices.lk, promise.lk and tenderlive.lk.'
            );
        }

        if (! in_array($authHeader, ['authorization', 'x-api-key'], true)) {
            return problem(422, 'validation_failed', 'auth_header must be authorization or x-api-key.');
        }

        $config = $this->loadSourcesConfig();
        $pending = is_array($config['pending_api_keys'] ?? null) ? $config['pending_api_keys'] : [];
        if ($apiKey !== '') {
            $generated = $pending[$apiKeyToken] ?? null;
            if (! is_array($generated)
                || (int) ($generated['expires_at'] ?? 0) < time()
                || ! hash_equals((string) ($generated['hash'] ?? ''), hash('sha256', $apiKey))
                || ($generated['official_key'] ?? '') !== $officialKey
            ) {
                return problem(422, 'generated_key_required', 'API keys must be generated by TenderHub from the staff console button.');
            }
            unset($pending[$apiKeyToken]);
            $config['pending_api_keys'] = $pending;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $slugBase = preg_replace('/[^a-z0-9]+/', '-', strtolower($officialKey . '-rest-' . $name)) ?: ($officialKey . '-rest-api');
        $slugBase = trim($slugBase, '-');

        $db = db_connect();
        $slug = $slugBase;
        for ($i = 2; $db->table('feed_sources')->where('slug', $slug)->countAllResults() > 0; $i++) {
            $slug = $slugBase . '-' . $i;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('feed_sources')->insert([
            'name' => $name,
            'slug' => $slug,
            'url' => $url,
            'mode' => 'feed',
            'adapter' => 'rest_api_json',
            'allowed_hosts' => $host,
            'weekly_baseline' => $baseline,
            'permission_state' => 'granted',
            'permission_note' => 'Approved official JSON REST API feed configured by staff.',
            'active' => 1,
            'paused' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        $config['enabled_sources'] = $config['enabled_sources'] ?? [];
        if (! in_array($slug, $config['enabled_sources'], true)) {
            $config['enabled_sources'][] = $slug;
        }
        $config['credentials'] = $config['credentials'] ?? [];
        $config['credentials'][$slug] = [
            'api_key' => $apiKey,
            'auth_header' => $authHeader,
            'login_url' => $url,
        ];
        $this->saveSourcesConfig($config);

        service('eventLedger')->record(
            'source',
            $id,
            'ingest.rest_source_created',
            sprintf('REST API source created for %s', $host),
            ['slug' => $slug, 'official_key' => $officialKey]
        );

        return $this->ok([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'source_key' => $slug,
            'mode' => 'feed',
            'baseline' => $baseline,
            'this_week' => 0,
            'verified_pct' => null,
            'status' => 'idle',
            'adapter' => 'rest_api_json',
            'has_adapter' => true,
            'active' => true,
            'paused' => false,
            'has_credentials' => $apiKey !== '',
            'credential_email' => null,
            'credential_url' => $url,
            'permission_state' => 'granted',
            'permission_note' => 'Approved official JSON REST API feed configured by staff.',
            'can_run' => true,
            'cursor' => null,
            'last_fetch_at' => null,
            'last_success_at' => null,
            'last_error' => null,
            'last_run' => null,
        ], [], 201);
    }

    /** POST admin/ingest/website-sources -- register an approved official website crawler source. */
    public function createWebsiteSource()
    {
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $baseline = max(0, (int) ($body['weekly_baseline'] ?? 0));

        if ($name === '' || mb_strlen($name) > 160) {
            return problem(422, 'validation_failed', 'Source name is required and must be 160 characters or fewer.');
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return problem(422, 'validation_failed', 'A valid website URL is required.');
        }
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https'], true)) {
            return problem(422, 'https_required', 'Website crawler sources must use HTTPS.');
        }

        $officialKey = $this->officialKey(['url' => $url]);
        if ($officialKey === null) {
            return problem(
                403,
                'source_not_allowed',
                'Tender automation may only import from approved sources: tendernotices.lk, promise.lk and tenderlive.lk.'
            );
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $adapter = $officialKey === 'tendernotices' ? 'tendernotices_lk' : null;
        $slugBase = preg_replace('/[^a-z0-9]+/', '-', strtolower($officialKey . '-web-' . $name)) ?: ($officialKey . '-website');
        $slugBase = trim($slugBase, '-');

        $db = db_connect();
        $slug = $slugBase;
        for ($i = 2; $db->table('feed_sources')->where('slug', $slug)->countAllResults() > 0; $i++) {
            $slug = $slugBase . '-' . $i;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('feed_sources')->insert([
            'name' => $name,
            'slug' => $slug,
            'url' => $url,
            'mode' => 'scrape',
            'adapter' => $adapter,
            'allowed_hosts' => $host,
            'weekly_baseline' => $baseline,
            'permission_state' => 'granted',
            'permission_note' => 'Approved official website crawler source configured by staff.',
            'active' => 1,
            'paused' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        $config = $this->loadSourcesConfig();
        $config['enabled_sources'] = $config['enabled_sources'] ?? [];
        if (! in_array($slug, $config['enabled_sources'], true)) {
            $config['enabled_sources'][] = $slug;
        }
        $this->saveSourcesConfig($config);

        service('eventLedger')->record(
            'source',
            $id,
            'ingest.website_source_created',
            sprintf('Website crawler source created for %s', $host),
            ['slug' => $slug, 'official_key' => $officialKey, 'adapter' => $adapter]
        );

        return $this->ok([
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'source_key' => $slug,
            'mode' => 'scrape',
            'baseline' => $baseline,
            'this_week' => 0,
            'verified_pct' => null,
            'status' => 'idle',
            'adapter' => $adapter,
            'has_adapter' => $adapter !== null,
            'active' => true,
            'paused' => false,
            'has_credentials' => false,
            'credential_email' => null,
            'credential_url' => $url,
            'permission_state' => 'granted',
            'permission_note' => 'Approved official website crawler source configured by staff.',
            'can_run' => $adapter !== null,
            'cursor' => null,
            'last_fetch_at' => null,
            'last_success_at' => null,
            'last_error' => null,
            'last_run' => null,
        ], [], 201);
    }

    /** POST admin/ingest/sources/{id}/toggle -- Enable or disable portal crawler. */
    public function toggle(int $id)
    {
        $db     = db_connect();
        $source = $db->table('feed_sources')->where('id', $id)->get()->getRowArray();
        if (! $source) {
            return problem(404, 'not_found', 'No such source.');
        }

        $body   = $this->body();
        if (isset($body['active']) && !is_bool($body['active'])) return problem(422,'validation_failed','active must be true or false.');
        $targetActive = isset($body['active']) ? (bool) $body['active'] : !((bool) $source['active'] && !(bool) $source['paused']);
        $targetPaused = !$targetActive;
        $officialKey=$this->officialKey($source);
        if ($targetActive && $officialKey===null) return problem(403,'source_not_allowed','Only approved TenderNotices, PROMISe and TenderLive sources may be enabled.');

        $db->table('feed_sources')->where('id', $id)->update([
            'active'     => $targetActive ? 1 : 0,
            'paused'     => $targetPaused ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $isNationalPress = ($source['slug'] === 'tendernotices-lk' || stripos($source['name'], 'Tender Notices') !== false);
        $sourceKey = ($source['adapter'] ?? null) === 'rest_api_json' ? $source['slug'] : ($officialKey ?? $source['slug']);

        $config = $this->loadSourcesConfig();
        $enabled = $config['enabled_sources'] ?? [];
        if ($targetActive) {
            if (!in_array($sourceKey, $enabled)) {
                $enabled[] = $sourceKey;
            }
        } else {
            $enabled = array_values(array_filter($enabled, fn ($k) => $k !== $sourceKey && $k !== $source['slug']));
        }
        $config['enabled_sources'] = $enabled;
        $this->saveSourcesConfig($config);

        service('eventLedger')->record(
            'source', $id, $targetActive ? 'ingest.enabled' : 'ingest.disabled',
            sprintf('%s import source %s', $targetActive ? 'Enabled' : 'Disabled', $source['slug']),
            ['active' => $targetActive]
        );

        return $this->ok([
            'id'     => $id,
            'active' => $targetActive,
            'paused' => $targetPaused,
            'status' => $targetActive ? 'idle' : 'paused',
        ]);
    }

    /** POST admin/ingest/sources/{id}/credentials — Configure dynamic credentials for any web portal. */
    public function credentials(int $id)
    {
        $db     = db_connect();
        $source = $db->table('feed_sources')->where('id', $id)->get()->getRowArray();
        if (! $source) {
            return problem(404, 'not_found', 'No such source.');
        }

        $body     = $this->body();
        $officialKey=$this->officialKey($source);
        if ($officialKey===null) return problem(403,'source_not_allowed','Only approved TenderNotices, PROMISe and TenderLive sources may be configured.');
        $email    = trim($body['email'] ?? '');
        $password = trim($body['password'] ?? '');
        $loginUrl = trim($body['login_url'] ?? '');
        $apiKey   = trim($body['api_key'] ?? '');
        $authHeader = strtolower(trim((string) ($body['auth_header'] ?? 'authorization')));
        if ($loginUrl !== '' && $this->officialKey(['url'=>$loginUrl]) !== $officialKey) {
            return problem(422,'invalid_login_url','Use a login URL on the selected official source domain.');
        }
        if (! in_array($authHeader, ['authorization', 'x-api-key'], true)) {
            return problem(422, 'validation_failed', 'auth_header must be authorization or x-api-key.');
        }

        $sourceKey = ($source['adapter'] ?? null) === 'rest_api_json' ? $source['slug'] : $officialKey;

        $config = $this->loadSourcesConfig();
        if (!isset($config['credentials'])) {
            $config['credentials'] = [];
        }

        $existing = $config['credentials'][$sourceKey] ?? [];
        $config['credentials'][$sourceKey] = [
            'email'     => $email !== '' ? $email : ($existing['email'] ?? ''),
            'password'  => $password !== '' ? $password : ($existing['password'] ?? ''),
            'api_key'   => $apiKey !== '' ? $apiKey : ($existing['api_key'] ?? ''),
            'auth_header' => $authHeader,
            'login_url' => $loginUrl !== '' ? $loginUrl : ($existing['login_url'] ?? $source['url'] ?? ''),
        ];
        $this->saveSourcesConfig($config);

        // The login URL lives with the credentials only. Writing it over
        // feed_sources.url re-pointed the source's listing URL at a login page,
        // which changed what was scraped and broke the per-host notice counts.

        service('eventLedger')->record(
            'source', $id, 'ingest.credentials_updated',
            sprintf('Credentials updated for source %s (%s)', $source['slug'], $email ? $email : 'saved'),
            []
        );

        return $this->ok([
            'id'               => $id,
            'message'          => 'Portal credentials saved successfully.',
            'has_credentials'  => true,
            'credential_email' => $email ?: ($existing['email'] ?? null),
        ]);
    }

    /** POST admin/ingest/sources/{id}/collect — Trigger on-demand crawler execution. */
    public function collect(int $id)
    {
        $db     = db_connect();
        $source = $db->table('feed_sources')->where('id', $id)->get()->getRowArray();
        if (! $source) {
            return problem(404, 'not_found', 'No such source.');
        }

        $body     = $this->body();
        // Same official-source gate as toggle() and credentials(): "collect"
        // previously added ANY source slug to enabled_sources.
        $officialKey = $this->officialKey($source);
        if ($officialKey === null) {
            return problem(403, 'source_not_allowed', 'Only approved TenderNotices, PROMISe and TenderLive sources may be collected.');
        }
        $sourceKey = ($source['adapter'] ?? null) === 'rest_api_json' ? $source['slug'] : $officialKey;
        $loginUrlIn = trim((string) ($body['login_url'] ?? ''));
        if ($loginUrlIn !== '' && $this->officialKey(['url' => $loginUrlIn]) !== $officialKey) {
            return problem(422, 'invalid_login_url', 'Use a login URL on the selected official source domain.');
        }

        // Persist any provided portal credentials for the out-of-band scraper to
        // read — to the writable, git-ignored config ONLY. They are never written
        // to a trigger file and never echoed back in the response.
        if (! empty($body['email']) || ! empty($body['password']) || ! empty($body['login_url'])) {
            $config   = $this->loadSourcesConfig();
            $config['credentials'] = $config['credentials'] ?? [];
            $existing = $config['credentials'][$sourceKey] ?? [];
            // Merge: an API key / auth header saved via credentials() must survive.
            $config['credentials'][$sourceKey] = array_merge($existing, [
                'email'     => ! empty($body['email']) ? trim($body['email']) : ($existing['email'] ?? ''),
                'password'  => ! empty($body['password']) ? trim($body['password']) : ($existing['password'] ?? ''),
                'login_url' => $loginUrlIn !== '' ? $loginUrlIn : ($existing['login_url'] ?? $source['url'] ?? ''),
            ]);
            if (! in_array($sourceKey, $config['enabled_sources'] ?? [], true)) {
                $config['enabled_sources'][] = $sourceKey;
            }
            $this->saveSourcesConfig($config);
        }

        // A REAL, bounded import — the same adapter + permission gate as run().
        // The previous implementation wrote an `ingest_trigger.json` (with the
        // portal password in cleartext) that NOTHING in the codebase ever read,
        // then fabricated a "dispatched to automation engine" success. There is
        // no such engine in-process: this now performs a genuine import or
        // reports honestly why it cannot. Authenticated portal scraping still
        // runs out-of-band (run_scraper.py / `php spark hub:sync`) with
        // credentials supplied via environment variables.
        $adapter = AdapterRegistry::make($source['adapter'] ?? null);
        if ($adapter === null) {
            return problem(422, 'no_adapter',
                'On-demand collection is unavailable for this source: no in-process importer adapter is configured. '
                . 'Authenticated portal collection runs out-of-band via the scraper with credentials from environment variables.');
        }
        if (($source['permission_state'] ?? 'unverified') !== 'granted') {
            return problem(409, 'permission_unverified', sprintf(
                'Collection refused. Permission state for this source is "%s". %s',
                $source['permission_state'] ?? 'unverified',
                $source['permission_note'] ?? ''
            ));
        }

        $runner = new ImportRunner($db);
        $res    = $runner->run($source['slug'], $adapter, [
            'sections' => $adapter->sections(),
            'maxPages' => 1,
            'resume'   => true,
            'dryRun'   => false,
            'trigger'  => 'admin',
        ]);

        service('eventLedger')->record(
            'source', $id, 'ingest.manual_collect',
            sprintf('Manual data collection for %s', $source['slug']),
            ['ok' => $res['ok'], 'stats' => $res['stats'] ?? null]
        );

        if (! $res['ok']) {
            return problem(502, 'import_failed', $res['error'] ?? 'Collection failed.');
        }

        $stats = $res['stats'] ?? [];

        return $this->ok([
            'status'         => 'completed',
            'message'        => 'Collection completed.',
            'items_found'    => (int) ($stats['items_found'] ?? 0),
            'items_inserted' => (int) ($stats['items_inserted'] ?? 0),
            'total_notices'  => $db->table('notices')->countAllResults(),
        ]);
    }


    /** GET admin/ingest/runs?page=&per_page= — bounded run history. */
    public function runs()
    {
        $db   = db_connect();
        $page = $this->page();
        $per  = $this->per(20, 100);

        $total = $db->table('ingestion_runs')->countAllResults();
        $rows  = $db->table('ingestion_runs')
            ->select('ingestion_runs.*, feed_sources.slug AS source_slug, feed_sources.name AS source_name')
            ->join('feed_sources', 'feed_sources.id = ingestion_runs.source_id', 'left')
            ->orderBy('ingestion_runs.id', 'DESC')
            ->limit($per, ($page - 1) * $per)
            ->get()->getResultArray();

        return $this->ok(
            array_map(fn ($r) => $this->runShape($r), $rows),
            ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) ceil($total / $per)]
        );
    }

    /** POST admin/ingest/sources/{id}/run — bounded incremental sync. */
    public function run(int $id)
    {
        $db     = db_connect();
        $source = $db->table('feed_sources')->where('id', $id)->get()->getRowArray();
        if (! $source) {
            return problem(404, 'not_found', 'No such source.');
        }

        $adapter = AdapterRegistry::make($source['adapter'] ?? null);
        if ($adapter === null) {
            return problem(422, 'no_adapter',
                'This source has no importer adapter configured.');
        }

        // The compliance gate is enforced again inside ImportRunner; this check
        // exists only to return a useful message instead of a generic failure.
        if (($source['permission_state'] ?? 'unverified') !== 'granted') {
            return problem(409, 'permission_unverified', sprintf(
                'Import refused. Permission state for this source is "%s". %s',
                $source['permission_state'] ?? 'unverified',
                $source['permission_note'] ?? ''
            ));
        }

        $body     = $this->body();
        $sections = is_array($body['sections'] ?? null) ? $body['sections'] : $adapter->sections();
        $sections = array_values(array_intersect($sections, $adapter->sections()));
        $pages    = max(1, min(self::ADMIN_MAX_PAGES, (int) ($body['pages'] ?? 2)));

        $runner = new ImportRunner($db);
        $res    = $runner->run($source['slug'], $adapter, [
            'sections' => $sections ?: $adapter->sections(),
            'maxPages' => $pages,
            'resume'   => (bool) ($body['resume'] ?? true),
            'dryRun'   => (bool) ($body['dry_run'] ?? false),
            'trigger'  => 'admin',
        ]);

        service('eventLedger')->record(
            'source', (int) $id, 'ingest.admin_run',
            sprintf('Admin import run for %s (%d pages)', $source['slug'], $pages),
            ['stats' => $res['stats'], 'ok' => $res['ok']]
        );

        if (! $res['ok']) {
            return problem(502, 'import_failed', $res['error'] ?? 'Import failed.');
        }

        return $this->ok([
            'run_id' => $res['run_id'],
            'stats'  => $res['stats'],
            'note'   => 'Bounded console run. Use `php spark tenders:import` for a full backfill.',
        ]);
    }

    /** POST admin/ingest/sources/{id}/pause  — operator stop switch. */
    public function pause(int $id)
    {
        return $this->setPaused($id, true);
    }

    /** POST admin/ingest/sources/{id}/resume */
    public function resume(int $id)
    {
        return $this->setPaused($id, false);
    }

    private function setPaused(int $id, bool $paused)
    {
        $this->request->setBody(json_encode(['active'=>!$paused]));
        return $this->toggle($id);
    }

    private function officialKey(array $source): ?string
    {
        $host=strtolower((string)parse_url($source['url'] ?? '',PHP_URL_HOST));
        if ($host === 'tendernotices.lk' || str_ends_with($host, '.tendernotices.lk')) return 'tendernotices';
        if ($host === 'promise.lk' || str_ends_with($host, '.promise.lk')) return 'promise';
        if (in_array($host, ['tenderlive.lk', 'www.tenderlive.lk'], true)) return 'tenderlive';
        return null;
    }

    private function scheduleShape(array $schedule): array
    {
        $mode = (string) ($schedule['mode'] ?? 'every_4_hours');
        if (! in_array($mode, ['hourly', 'every_4_hours', 'daily'], true)) {
            $mode = 'every_4_hours';
        }

        $hour = isset($schedule['hour']) ? (int) $schedule['hour'] : 5;
        $hour = max(0, min(23, $hour));

        $label = match ($mode) {
            'hourly' => 'Every hour',
            'daily' => sprintf('Daily at %02d:00', $hour),
            default => 'Every 4 hours',
        };

        return [
            'mode' => $mode,
            'hour' => $mode === 'daily' ? $hour : null,
            'timezone' => 'Asia/Colombo',
            'label' => $label,
            'updated_at' => $schedule['updated_at'] ?? null,
        ];
    }

    private function runShape(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'source_slug'     => $r['source_slug'] ?? null,
            'source_name'     => $r['source_name'] ?? null,
            'status'          => $r['status'],
            'trigger'         => $r['trigger'] ?? 'cli',
            'started_at'      => $r['started_at'] ?? $r['created_at'] ?? null,
            'completed_at'    => $r['completed_at'] ?? null,
            'duration_ms'     => $r['duration_ms'] ?? null,
            'cursor'          => $r['cursor'] ?? null,
            'pages_attempted' => (int) ($r['pages_attempted'] ?? 0),
            'pages_completed' => (int) ($r['pages_completed'] ?? 0),
            'items_found'     => (int) ($r['items_found'] ?? 0),
            'items_inserted'  => (int) ($r['items_inserted'] ?? 0),
            'items_updated'   => (int) ($r['items_updated'] ?? 0),
            'items_duplicate' => (int) ($r['items_duplicate'] ?? 0),
            'items_skipped'   => (int) ($r['items_skipped'] ?? 0),
            'parse_failures'  => (int) ($r['parse_failures'] ?? 0),
            'http_failures'   => (int) ($r['http_failures'] ?? 0),
            'error_message'   => $r['error_message'] ?? null,
        ];
    }
}
