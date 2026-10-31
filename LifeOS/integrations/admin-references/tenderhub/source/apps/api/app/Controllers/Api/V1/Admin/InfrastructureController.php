<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;

/** Read-only production infrastructure telemetry; no shell commands are run from HTTP. */
class InfrastructureController extends BaseApiController
{
    private const OWNER_EMAIL = 'redacted@example.invalid';

    public function status()
    {
        $path = (string) (env('TENDERHUB_MONITORING_STATUS') ?: '/var/run/tenderhub-monitoring/vps-status.json');
        if (! is_file($path)) {
            return problem(503, 'infrastructure_unavailable', 'The VPS observer has not produced a live status file yet.');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['checked_at'])) {
            return problem(503, 'infrastructure_invalid', 'The VPS observer status is invalid.');
        }

        $warnings = [];
        $host = $data['host'] ?? [];
        $cpu = (float) ($host['cpu_count'] ?? 1);
        if ($cpu > 0 && (float) ($host['load_1m'] ?? 0) / $cpu >= 0.8) $warnings[] = 'CPU load is at or above 80%.';
        if ((float) ($host['memory_used_percent'] ?? 0) >= 80) $warnings[] = 'Memory usage is at or above 80%.';
        if ((float) ($host['disk_used_percent'] ?? 0) >= 85) $warnings[] = 'Docker storage is at or above 85%.';
        foreach (($data['containers'] ?? []) as $container) {
            if (($container['state'] ?? '') !== 'running') $warnings[] = 'Container ' . ($container['name'] ?? 'unknown') . ' is not running.';
        }

        return $this->ok([
            'checked_at' => $data['checked_at'],
            'host' => $host,
            'orchestrator' => $data['orchestrator'] ?? 'unknown',
            'containers' => $data['containers'] ?? [],
            'processes' => $data['processes'] ?? [],
            'kubernetes' => $data['kubernetes'] ?? ['available' => false, 'configured' => false, 'nodes' => [], 'message' => 'Not configured.'],
            'warnings' => array_values(array_unique($warnings)),
            'observer_age_seconds' => max(0, time() - (strtotime((string) $data['checked_at']) ?: time())),
        ]);
    }

    /** Host-produced state only. No command is run from HTTP. */
    public function maintenance()
    {
        $path = $this->maintenanceStatusPath();
        if (! is_file($path)) {
            return $this->ok(['available' => false, 'reason' => 'The Docker maintenance engine has not reported yet.']);
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return problem(503, 'maintenance_status_invalid', 'The Docker maintenance engine status is invalid.');
        }
        return $this->ok(['available' => true, 'status' => $data]);
    }

    /**
     * Queues the sole permitted mutation. The host validates a separate HMAC,
     * request age and action allowlist before it calls Docker. The API never
     * receives the Docker socket and never executes a shell command.
     */
    public function queueSafeCleanup()
    {
        $actorId = (int) ($this->request->userId ?? 0);
        $owner = $actorId > 0 ? db_connect()->table('users')->select('email')->where('id', $actorId)->get()->getFirstRow('array') : null;
        if (strtolower((string) ($owner['email'] ?? '')) !== self::OWNER_EMAIL) {
            return problem(403, 'owner_required', 'Only the TenderHub primary owner can queue Docker maintenance.');
        }

        $key = (string) env('DOCKER_MANAGEMENT_KEY');
        $pending = (string) (env('TENDERHUB_DOCKER_MANAGEMENT_QUEUE_DIR') ?: '/var/run/tenderhub-docker-management-queue');
        if (strlen($key) < 32 || ! is_dir($pending) || ! is_writable($pending)) {
            return problem(503, 'maintenance_unavailable', 'Docker maintenance is not configured on this deployment.');
        }

        $id = bin2hex(random_bytes(16));
        $created = time();
        $action = 'safe_cleanup';
        $payload = implode('|', [$id, $action, $actorId, $created]);
        $request = [
            'id' => $id,
            'action' => $action,
            'actor_id' => $actorId,
            'created_epoch' => $created,
            'signature' => hash_hmac('sha256', $payload, $key),
        ];
        $target = $pending . DIRECTORY_SEPARATOR . $id . '.json';
        $temporary = $target . '.tmp';
        $encoded = json_encode($request, JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || ! @rename($temporary, $target)) {
            @unlink($temporary);
            return problem(503, 'maintenance_queue_failed', 'The host maintenance queue is unavailable.');
        }
        @chmod($target, 0600);

        service('eventLedger')->record('infrastructure', 0, 'docker.safe_cleanup_queued', 'Primary owner queued safe Docker cleanup.', [
            'request_id' => $id,
            'action' => $action,
            'scope' => ['dangling_images', 'build_cache_older_than_14_days'],
        ]);

        return $this->ok([
            'request_id' => $id,
            'queued' => true,
            'message' => 'Safe cleanup was queued. The host worker validates and processes it within one minute.',
        ]);
    }

    private function maintenanceStatusPath(): string
    {
        return (string) (env('TENDERHUB_DOCKER_MANAGEMENT_STATUS_FILE') ?: '/var/run/tenderhub-docker-management-status/status.json');
    }
}
