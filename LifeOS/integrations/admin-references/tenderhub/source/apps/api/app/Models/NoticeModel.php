<?php

namespace App\Models;

use CodeIgniter\Model;

class NoticeModel extends Model
{
    protected $table         = 'notices';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'kind','reference','slug','title','title_si','title_ta','summary','summary_si','summary_ta','description','description_si','description_ta',
        'authority_id','org_id','category_id','district_id','sector','estimated_value','currency',
        'document_fee','bid_security','contact_officer','contact_phone','contact_email',
        'source_url','source_id','published_at','closing_at','opening_at','status','verified',
        'missing_fields','canonical_id','documents_count',
    ];

    /**
     * Fixed boundaries. A band whose edges move with the data cannot be linked
     * to, bookmarked, or compared between two visits. These same constants
     * build both the facet counts and the result filter, so the number on the
     * chip is the number of rows you get.
     */
    public const VALUE_BANDS = [
        'under-5m'   => ['label' => 'Under Rs. 5 M',        'min' => 0,          'max' => 5000000],
        '5m-25m'     => ['label' => 'Rs. 5 M – 25 M',       'min' => 5000000,    'max' => 25000000],
        '25m-100m'   => ['label' => 'Rs. 25 M – 100 M',     'min' => 25000000,   'max' => 100000000],
        '100m-500m'  => ['label' => 'Rs. 100 M – 500 M',    'min' => 100000000,  'max' => 500000000],
        'over-500m'  => ['label' => 'Over Rs. 500 M',       'min' => 500000000,  'max' => null],
    ];

    /**
     * The constraint set, as a closure.
     *
     * countAllResults() resets the builder — the count and the page used to
     * share one, so the count query silently ran without its joins and where
     * clauses and closed notices appeared in live results with blank category
     * and district. It is now applied to two independent builders.
     */
    public function constraints(array $f): \Closure
    {
        return function ($b) use ($f) {
            $b->where('notices.status', 'published')
              ->where('notices.canonical_id', null);

            // Production catalogue/search should never promote fixture or
            // smoke-test notices into public SEO surfaces.
            foreach (['official test notice', 'demo tender', 'demo notice', 'fixture notice', 'sample tender'] as $pattern) {
                foreach (['title', 'summary', 'description'] as $field) {
                    $b->groupStart()
                        ->where("notices.{$field} IS NULL", null, false)
                        ->orNotLike("notices.{$field}", $pattern, 'both', null, true)
                      ->groupEnd();
                }
            }

            if (! empty($f['kind'])) {
                $b->where('notices.kind', $f['kind']);
            }

            if (! empty($f['q'])) {
                $b->groupStart()
                  ->like('notices.title', $f['q'])
                  ->orLike('notices.title_si', $f['q'])
                  ->orLike('notices.title_ta', $f['q'])
                  ->orLike('notices.summary', $f['q'])
                  ->orLike('notices.summary_si', $f['q'])
                  ->orLike('notices.summary_ta', $f['q'])
                  ->orLike('notices.reference', $f['q'])
                  ->orLike('notices.ocr_text', $f['q'])
                  ;
                if (! empty($f['can_search_pdfs']) && mb_strlen((string) $f['q']) >= 3) {
                    $b->orLike('notices.pdf_search_text', $f['q']);
                }
                $b->groupEnd();
            }

            if (! empty($f['categories'])) {
                // Selecting a parent includes its children.
                $ids = $this->categoryIdsWithChildren($f['categories']);
                $ids ? $b->whereIn('notices.category_id', $ids) : $b->where('1=0', null, false);
            }

            if (! empty($f['districts'])) {
                $ids = array_column(
                    $this->db->table('districts')->select('id')->whereIn('slug', $f['districts'])->get()->getResultArray(),
                    'id'
                );
                $ids ? $b->whereIn('notices.district_id', $ids) : $b->where('1=0', null, false);
            }

            if (! empty($f['sectors'])) {
                $b->whereIn('notices.sector', $f['sectors']);
            }

            if (! empty($f['bands'])) {
                $b->groupStart();
                foreach (array_values($f['bands']) as $i => $slug) {
                    $band = self::VALUE_BANDS[$slug] ?? null;
                    if (! $band) {
                        continue;
                    }
                    $i === 0 ? $b->groupStart() : $b->orGroupStart();
                    $b->where('notices.estimated_value >=', $band['min']);
                    if ($band['max'] !== null) {
                        $b->where('notices.estimated_value <', $band['max']);
                    }
                    $b->groupEnd();
                }
                $b->groupEnd();
            }

            $now = date('Y-m-d H:i:s');
            $soon = date('Y-m-d H:i:s', time() + 7 * 86400);
            match ($f['status'] ?? 'all') {
                'live'         => $b->where('notices.closing_at >=', $now),
                'closing_soon' => $b->where('notices.closing_at >=', $now)->where('notices.closing_at <=', $soon),
                'closed'       => $b->where('notices.closing_at <', $now),
                default        => null,
            };

            return $b;
        };
    }

    public function categoryIdsWithChildren(array $slugs): array
    {
        $rows = $this->db->table('categories')->select('id')->whereIn('slug', $slugs)->get()->getResultArray();
        $ids  = array_map('intval', array_column($rows, 'id'));
        if (! $ids) {
            return [];
        }
        $kids = $this->db->table('categories')->select('id')->whereIn('parent_id', $ids)->get()->getResultArray();

        return array_values(array_unique(array_merge($ids, array_map('intval', array_column($kids, 'id')))));
    }

    public function base()
    {
        return $this->db->table('notices')
            ->select('notices.*, categories.name AS category_name, categories.name_si AS category_name_si, categories.name_ta AS category_name_ta, categories.slug AS category_slug,
                      districts.name AS district_name, districts.name_si AS district_name_si, districts.name_ta AS district_name_ta, districts.slug AS district_slug,
                      authorities.name AS authority_name, authorities.name_si AS authority_name_si, authorities.name_ta AS authority_name_ta, organisations.name AS org_name,
                      (SELECT 1 FROM notice_documents WHERE notice_documents.notice_id = notices.id AND (notice_documents.kind = \'paper_ad\' OR notice_documents.mime LIKE \'image/%\' OR notice_documents.source_url LIKE \'%.jpg%\' OR notice_documents.source_url LIKE \'%.jpeg%\' OR notice_documents.source_url LIKE \'%.png%\') LIMIT 1) AS has_image')
            ->join('categories', 'categories.id = notices.category_id', 'left')
            ->join('districts', 'districts.id = notices.district_id', 'left')
            ->join('authorities', 'authorities.id = notices.authority_id', 'left')
            ->join('organisations', 'organisations.id = notices.org_id', 'left');
    }

    public function search(array $f, int $page, int $per, string $sort = 'newest'): array
    {
        $apply = $this->constraints($f);

        $rows = $apply($this->base());
        $order = match ($sort) {
            // "Listed" = the source's publication date, or when TenderHub first
            // saw the notice if the source gives none (e-GP does not). Sorting on
            // published_at alone sank every such real notice to the bottom.
            'newest'  => ['COALESCE(notices.published_at, notices.first_seen_at, notices.created_at)', 'DESC', false],
            'value'   => ['notices.estimated_value', 'DESC'],
            default   => ['notices.closing_at', 'ASC'],
        };
        if ($sort === 'closing_at' || empty($sort)) {
            $rows = $rows->orderBy('CASE WHEN notices.closing_at IS NULL THEN 1 ELSE 0 END', 'ASC', false);
        }
        $rows = $rows->orderBy($order[0], $order[1], $order[2] ?? null)
            ->orderBy('CASE WHEN notices.documents_count > 0 THEN 0 ELSE 1 END', 'ASC', false)
            ->orderBy('COALESCE(notices.published_at, notices.first_seen_at, notices.created_at)', 'DESC', false)
            ->limit($per, ($page - 1) * $per)
            ->get()->getResultArray();

        $total = $apply($this->db->table('notices')
            ->join('categories', 'categories.id = notices.category_id', 'left')
            ->join('districts', 'districts.id = notices.district_id', 'left'))
            ->countAllResults();

        return ['rows' => $rows, 'total' => $total];
    }

    public function byIds(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        return $this->base()->whereIn('notices.id', $ids)
            ->orderBy("CASE WHEN notices.closing_at IS NOT NULL AND notices.closing_at >= '{$now}' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy("CASE WHEN notices.closing_at IS NOT NULL AND notices.closing_at >= '{$now}' THEN notices.closing_at END", 'ASC', false)
            ->orderBy('COALESCE(notices.published_at, notices.first_seen_at, notices.created_at)', 'DESC', false)
            ->get()->getResultArray();
    }

    /** Delegates to the one matcher, so the feed and the preview cannot drift. */
    public function matchIdsFor(array $profile): array
    {
        return model(AlertProfileModel::class)->matchIds($profile);
    }

    public function bySlug(string $slug): ?array
    {
        return $this->base()->where('notices.slug', $slug)->get()->getFirstRow('array');
    }

    public function byId(int $id): ?array
    {
        return $this->base()->where('notices.id', $id)->get()->getFirstRow('array');
    }

    public function byReference(string $ref): ?array
    {
        return $this->base()->where('notices.reference', $ref)->get()->getFirstRow('array');
    }

    /**
     * Facets are scoped by exactly the same conditions as the list — including
     * kind. They used to ignore it, so the auctions page would have shown
     * category counts drawn from tenders. A filter that leads to an empty page
     * is worse than no filter.
     */
    public function partnerFeed(int $cursor, int $limit): array
    {
        return $this->base()
            ->where('notices.kind', 'tender')
            ->where('notices.status', 'published')
            ->where('notices.canonical_id', null)
            ->where('notices.id >', $cursor)
            ->orderBy('notices.id', 'ASC')->limit($limit)->get()->getResultArray();
    }

    public function facets(array $f): array
    {
        $without = static function (array $f, string $key): array {
            unset($f[$key]);
            return $f;
        };

        $count = function (array $filters, string $select, string $join = '') {
            $b = $this->db->table('notices');
            if ($join === 'categories') {
                $b->join('categories', 'categories.id = notices.category_id', 'left');
            } elseif ($join === 'districts') {
                $b->join('districts', 'districts.id = notices.district_id', 'left');
            }

            return ($this->constraints($filters))($b)->select($select . ', COUNT(*) AS n')
                ->groupBy(explode(' AS ', $select)[0])->get()->getResultArray();
        };

        $cats = $count($without($f, 'categories'), 'categories.slug AS slug', 'categories');
        $dist = $count($without($f, 'districts'), 'districts.slug AS slug', 'districts');
        $sect = $count($without($f, 'sectors'), 'notices.sector AS slug');

        $bands = [];
        foreach (self::VALUE_BANDS as $slug => $band) {
            $bands[] = [
                'slug'  => $slug,
                'label' => $band['label'],
                'n'     => ($this->constraints(array_merge($without($f, 'bands'), ['bands' => [$slug]])))(
                    $this->db->table('notices')
                )->countAllResults(),
            ];
        }

        $labels = fn (string $table) => array_column(
            $this->db->table($table)->select('slug, name')->get()->getResultArray(), 'name', 'slug'
        );
        $catNames  = $labels('categories');
        $distNames = $labels('districts');

        $decorate = static function (array $rows, array $names): array {
            $out = [];
            foreach ($rows as $r) {
                if (! $r['slug']) {
                    continue;
                }
                $out[] = ['slug' => $r['slug'], 'label' => $names[$r['slug']] ?? ucfirst($r['slug']), 'n' => (int) $r['n']];
            }
            usort($out, static fn ($a, $b) => $b['n'] <=> $a['n']);

            return $out;
        };

        return [
            'category'   => $decorate($cats, $catNames),
            'district'   => $decorate($dist, $distNames),
            'sector'     => $decorate($sect, ['government' => 'Government', 'private' => 'Private', 'donor' => 'Donor']),
            'value_band' => $bands,
        ];
    }

    public function statusCounts(array $f): array
    {
        $out = [];
        foreach (['all', 'live', 'closing_soon', 'closed'] as $s) {
            $out[$s] = ($this->constraints(array_merge($f, ['status' => $s])))(
                $this->db->table('notices')
            )->countAllResults();
        }

        return $out;
    }
}
