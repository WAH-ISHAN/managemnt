<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\BlogPostService;
use InvalidArgumentException;

class BlogController extends BaseApiController
{
    public function index()
    {
        $page = $this->page();
        $per = $this->per(20, 100);
        ['rows' => $rows, 'total' => $total] = (new BlogPostService(db_connect()))->adminList(
            (string) ($this->request->getGet('q') ?? ''),
            (string) ($this->request->getGet('status') ?? ''),
            $page,
            $per,
        );
        return $this->ok($rows, ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => max(1, (int) ceil($total / $per))]);
    }

    /** One post in full, drafts included, for the editor and the staff preview. */
    public function show(int $id)
    {
        try {
            $row = (new BlogPostService(db_connect()))->find($id);
            $row['tags'] = array_values(array_filter(explode('|', (string) ($row['tags'] ?? ''))));
            unset($row['indexnow_fingerprint']);
            return $this->ok($row);
        } catch (InvalidArgumentException $e) {
            return problem(404, 'not_found', $e->getMessage());
        }
    }

    public function create()
    {
        return $this->mutate(null);
    }

    public function update(int $id)
    {
        return $this->mutate($id);
    }

    public function delete(int $id)
    {
        try {
            (new BlogPostService(db_connect()))->delete($id);
            service('eventLedger')->record('blog_post', $id, 'blog.deleted', 'Blog post deleted by staff.');
            return $this->ok(['id' => $id, 'deleted' => true]);
        } catch (InvalidArgumentException $e) {
            return problem($e->getCode() === 404 ? 404 : 422, 'validation_failed', $e->getMessage());
        }
    }

    private function mutate(?int $id)
    {
        try {
            $service = new BlogPostService(db_connect());
            $row = $id === null
                ? $service->create($this->body(), (int) $this->request->userId)
                : $service->update($id, $this->body(), (int) $this->request->userId);
            service('eventLedger')->record('blog_post', (int) $row['id'], $id === null ? 'blog.created' : 'blog.updated', 'Blog post saved by staff.', [
                'status' => $row['status'], 'slug' => $row['slug'],
            ]);
            return $this->ok($row, [], $id === null ? 201 : 200);
        } catch (InvalidArgumentException $e) {
            return problem($e->getCode() === 404 ? 404 : 422, 'validation_failed', $e->getMessage());
        }
    }
}
