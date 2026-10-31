<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;

/** Real registration attribution reports for staff administrators. */
class AcquisitionController extends BaseApiController
{
    public function index()
    {
        $db = db_connect();
        if (! $db->tableExists('user_acquisition')) {
            return problem(503, 'acquisition_not_configured', 'Run the user acquisition migration before viewing this report.');
        }

        $days = max(1, min(730, (int) ($this->request->getGet('days') ?? 90)));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $base = $db->table('user_acquisition a')->where('a.created_at >=', $since);

        $grouped = static function ($builder, string $source, string $label): array {
            return array_map(static fn ($r) => ['key' => $r[$source] ?: 'not_recorded', 'label' => $r[$source] ?: 'Not recorded', 'registrations' => (int) $r['registrations']], $builder->select("a.{$source}, COUNT(*) AS registrations")->groupBy("a.{$source}")->orderBy('registrations', 'DESC')->get()->getResultArray());
        };

        $sources = $grouped(clone $base, 'first_source', 'Source');
        $campaigns = $grouped(clone $base, 'first_campaign', 'Campaign');
        $landingPages = $grouped(clone $base, 'first_landing_path', 'Landing page');
        $recent = (clone $base)->select('a.*, u.name, u.email')->join('users u', 'u.id = a.user_id', 'left')->orderBy('a.created_at', 'DESC')->limit(100)->get()->getResultArray();

        return $this->ok([
            'period_days' => $days,
            'since' => $since,
            'total_registrations' => array_sum(array_column($sources, 'registrations')),
            'sources' => $sources,
            'campaigns' => $campaigns,
            'landing_pages' => $landingPages,
            'recent' => $recent,
        ], ['now' => date('c')]);
    }
}
