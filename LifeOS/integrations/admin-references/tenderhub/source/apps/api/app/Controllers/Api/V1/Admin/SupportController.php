<?php

namespace App\Controllers\Api\V1\Admin;

use App\Controllers\Api\V1\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/** Real contact-form inbox and support mailbox routing for staff operators. */
class SupportController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $status = trim((string) $this->request->getGet('status'));
        $query = trim((string) $this->request->getGet('q'));
        $builder = $db->table('contact_messages c')
            ->select('c.*, u.name AS assignee_name, m.label AS mailbox_label, m.email AS mailbox_email')
            ->join('users u', 'u.id = c.assigned_to', 'left')
            ->join('support_mailboxes m', 'm.id = c.mailbox_id', 'left');
        if (in_array($status, ['new', 'in_progress', 'waiting_customer', 'resolved', 'closed'], true)) {
            $builder->where('c.status', $status);
        }
        if ($query !== '') {
            $builder->groupStart()->like('c.name', $query)->orLike('c.email', $query)->orLike('c.message', $query)->groupEnd();
        }
        $messages = $builder->orderBy("CASE c.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END", '', false)
            ->orderBy('c.id', 'DESC')->limit(100)->get()->getResultArray();
        $mailboxes = $db->table('support_mailboxes')->where('status', 'active')->orderBy('is_primary', 'DESC')->orderBy('label')->get()->getResultArray();
        $staff = $db->table('users')->select('id, name, email, role')->where('user_group', 'staff')->where('status', 'active')->orderBy('name')->get()->getResultArray();
        return $this->ok(['messages' => $messages, 'mailboxes' => $mailboxes, 'staff' => $staff]);
    }

    public function updateMessage(int $id): ResponseInterface
    {
        $in = $this->body();
        $status = trim((string) ($in['status'] ?? ''));
        $priority = trim((string) ($in['priority'] ?? ''));
        if (!in_array($status, ['new', 'in_progress', 'waiting_customer', 'resolved', 'closed'], true)
            || !in_array($priority, ['urgent', 'high', 'normal', 'low'], true)) {
            return problem(422, 'validation_failed', 'Invalid support status or priority.');
        }
        $assigned = isset($in['assigned_to']) && $in['assigned_to'] !== '' ? (int) $in['assigned_to'] : null;
        $mailbox = isset($in['mailbox_id']) && $in['mailbox_id'] !== '' ? (int) $in['mailbox_id'] : null;
        $db = db_connect();
        $row = $db->table('contact_messages')->where('id', $id)->get()->getFirstRow('array');
        if (!$row) return problem(404, 'not_found', 'Support message not found.');
        if ($assigned !== null && !$db->table('users')->where('id', $assigned)->where('user_group', 'staff')->where('status', 'active')->countAllResults()) {
            return problem(422, 'validation_failed', 'The selected assignee is not an active staff member.');
        }
        if ($mailbox !== null && !$db->table('support_mailboxes')->where('id', $mailbox)->where('status', 'active')->countAllResults()) {
            return problem(422, 'validation_failed', 'The selected support mailbox is not active.');
        }
        $now = date('Y-m-d H:i:s');
        $db->table('contact_messages')->where('id', $id)->update([
            'status' => $status, 'priority' => $priority, 'assigned_to' => $assigned,
            'mailbox_id' => $mailbox, 'updated_at' => $now,
        ]);
        service('eventLedger')->record('contact_message', $id, 'support.message_updated', 'Support message routed or updated by staff.', [
            'status' => $status, 'priority' => $priority, 'assigned_to' => $assigned, 'mailbox_id' => $mailbox,
        ]);
        return $this->ok(['id' => $id, 'status' => $status]);
    }

    public function createMailbox(): ResponseInterface
    {
        $in = $this->body();
        $label = trim((string) ($in['label'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $purpose = trim((string) ($in['purpose'] ?? 'general_support'));
        if ($label === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return problem(422, 'validation_failed', 'A label and valid mailbox email are required.');
        if (!in_array($purpose, ['general_support', 'sales', 'technical', 'billing'], true)) return problem(422, 'validation_failed', 'Invalid mailbox purpose.');
        $db = db_connect();
        if ($db->table('support_mailboxes')->where('email', $email)->countAllResults()) return problem(409, 'conflict', 'That mailbox is already configured.');
        $now = date('Y-m-d H:i:s');
        $db->table('support_mailboxes')->insert(['label' => $label, 'email' => $email, 'purpose' => $purpose, 'is_primary' => 0, 'status' => 'active', 'created_by' => (int) $this->request->userId, 'created_at' => $now, 'updated_at' => $now]);
        return $this->ok(['id' => (int) $db->insertID(), 'label' => $label, 'email' => $email, 'purpose' => $purpose], [], 201);
    }
}
