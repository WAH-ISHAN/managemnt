<?php

namespace App\Models;

use CodeIgniter\Model;

class NoticeDocumentModel extends Model
{
    protected $table         = 'notice_documents';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;

    // TH-SEC-002: explicit writable columns instead of protectFields=false.
    protected $allowedFields = [
        'notice_id', 'name', 'kind', 'mime', 'size_bytes', 'sha256', 'path',
        'source_url', 'mirrored_at', 'mirror_error', 'uploaded_by', 'created_at', 'updated_at',
    ];
}
