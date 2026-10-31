<?php

namespace App\Models;

use CodeIgniter\Model;

class AlertProfileModel extends Model
{
    protected $table         = 'alert_profiles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'org_id','user_id','name','kinds','category_slugs','district_slugs','keywords',
        'min_value','max_value','channels','active','exclude_keywords','match_mode','all_tenders',
    ];

    /**
     * The single query that decides what a profile matches. Both the dry-run
     * preview and the personal feed call it, so a preview that promises matches
     * the feed then does not deliver cannot happen by construction.
     *
     * Profiles match on SLUGS, never on auto-increment ids. Ids shift on every
     * re-seed — a category moved from 1 to 35 during development and silently
     * repointed every saved profile at a different category. A subscriber would
     * have kept receiving alerts, for the wrong thing, with nothing broken
     * enough to notice.
     */
    public function matchIds(array $profile, ?string $since = null, int $limit = 200): array
    {
        if ($limit <= 0 || (empty($profile['category_slugs']) && \App\Libraries\Notifications\AlertKeywords::read($profile['keywords'] ?? '') === [] && empty($profile['all_tenders']))) { return []; }
        $matcher = new \App\Libraries\Notifications\AlertMatcherService();
        $ids = [];
        $offset = 0;
        $now = date('Y-m-d H:i:s');
        do {
            $query = $this->db->table('notices n')->select('n.*, c.slug AS category_slug, c.name AS category_name, d.slug AS district_slug, a.name AS authority_name')
                ->join('categories c', 'c.id = n.category_id', 'left')
                ->join('districts d', 'd.id = n.district_id', 'left')
                ->join('authorities a', 'a.id = n.authority_id', 'left')
                ->where('n.status', 'published')->where('n.canonical_id', null);
            if ($since !== null) {
                $query->where('COALESCE(n.public_since, n.published_at, n.first_seen_at, n.created_at) >= ' . $this->db->escape($since), null, false);
            }
            $cats = array_filter(explode(',', (string) ($profile['category_slugs'] ?? '')));
            if ($cats) {
                $parents = array_column($this->db->table('categories')->select('id')->whereIn('slug', $cats)->get()->getResultArray(), 'id');
                $query->groupStart()->whereIn('c.slug', $cats);
                if ($parents) { $query->orWhereIn('c.parent_id', $parents); }
                $query->groupEnd();
            }
            $rows = $query->orderBy("CASE WHEN n.closing_at IS NOT NULL AND n.closing_at >= '{$now}' THEN 0 ELSE 1 END", 'ASC', false)
                ->orderBy('n.published_at', 'DESC')->orderBy('n.id', 'DESC')->limit(250, $offset)->get()->getResultArray();
            foreach ($rows as $notice) {
                if ($matcher->matchesNotice($notice, $profile)) {
                    $ids[] = (int) $notice['id'];
                    if (count($ids) >= $limit) { return $ids; }
                }
            }
            $offset += 250;
        } while (count($rows) === 250);
        return $ids;
    }
}
