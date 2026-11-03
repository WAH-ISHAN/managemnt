"""Offline transport-contract checks for optional Python adapters. No external accounts used."""
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('connectivity_worker', ROOT / 'scripts/connectivity_worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class Mailbox:
    validity = b'44'
    commands = []

    def __init__(self, host, port, ssl_context, timeout):
        assert ssl_context.verify_mode != 0
        self.__class__.commands = []

    def __enter__(self): return self
    def __exit__(self, *args): return None
    def login(self, user, password): pass
    def select(self, folder, readonly):
        assert readonly is True
        return 'OK', []
    def response(self, key): return key, [self.validity]
    def uid(self, command, *args):
        self.commands.append((command, args))
        if command == 'search': return 'OK', [b'1 2']
        uid = args[0]
        if uid == b'1':
            raw = b'From: Person <person@example.org>\r\nSubject: [task] Prepare report\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nDo this tomorrow'
        else:
            raw = b'From: Other <other@example.org>\r\nSubject: Server search false positive\r\n\r\nNot a matching task'
        return 'OK', [(b'body', raw), b')']


class AdapterTests(unittest.TestCase):
    @patch.object(worker.imaplib, 'IMAP4_SSL', Mailbox)
    def test_mail_preview_filter_identity_and_nonmutating_fetch(self):
        config = {'host': 'imap.example.org', 'port': 993, 'user': 'owner', 'password': 'test', 'folder': 'INBOX', 'subject': '[task]', 'sender': 'person@example.org'}
        first = worker.imap_poll(config)['messages']
        self.assertEqual(len(first), 1)
        self.assertEqual(first[0]['body'], 'Do this tomorrow')
        self.assertEqual(first, worker.imap_poll(config)['messages'])
        self.assertTrue(all(command in ('search', 'fetch') for command, _ in Mailbox.commands))
        self.assertIn('BODY.PEEK[]', Mailbox.commands[1][1][1])
        Mailbox.validity = b'45'
        try:
            self.assertNotEqual(first[0]['identity'], worker.imap_poll(config)['messages'][0]['identity'])
        finally:
            Mailbox.validity = b'44'

    def test_timezone_offsets_and_unknown_timezone(self):
        self.assertEqual(worker.parse_time('2026-12-05T03:00:00Z'), '2026-12-05 08:30')
        self.assertEqual(worker.parse_time('2026-12-05T09:00:00+05:30'), '2026-12-05 09:00')
        with self.assertRaises(ValueError): worker.parse_time('2026-12-05T09:00:00', 'Mars')

    def test_provider_credentials_never_follow_untrusted_pagination_url(self):
        api = worker.Api('private-token', 'www.googleapis.com')
        for url in ('http://www.googleapis.com/events', 'https://evil.example/events', 'https://www.googleapis.com@evil.example/events'):
            with self.assertRaises(ValueError): api.request('GET', url)

    def test_google_fetch_expands_instances_and_preserves_cancellation(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append(url)
            return {'items': [{'id': 'a', 'etag': 'v1', 'summary': 'Meeting', 'start': {'dateTime': '2026-12-05T03:00:00Z'}, 'end': {'dateTime': '2026-12-05T04:00:00Z'}}, {'id': 'b', 'etag': 'v2', 'status': 'cancelled'}, {'id': 'c', 'start': {'date': '2026-12-05'}}]}
        with patch.object(worker.Api, 'request', request):
            result = worker.calendar_fetch({'provider': 'google', 'calendar': 'primary', 'token': 'test'})
        self.assertEqual(len(result['events']), 2)
        self.assertEqual(result['skipped_all_day'], 1)
        self.assertTrue(result['events'][1]['cancelled'])
        self.assertIn('singleEvents=true', calls[0])

    def test_conditional_updates_and_repeatable_google_creation_identity(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append((method, body, version))
            return {'id': 'remote', 'etag': 'new'}
        config = {'provider': 'google', 'calendar': 'primary', 'token': 'test', 'identity': 'profile:123', 'event': {'title': 'Plan', 'starts_at': '2026-12-05 09:00', 'ends_at': '2026-12-05 10:00'}}
        with patch.object(worker.Api, 'request', request):
            worker.calendar_write(config)
            worker.calendar_write(config)
            worker.calendar_write(dict(config, remote_id='remote', version='old'))
        self.assertEqual(calls[0][1]['id'], calls[1][1]['id'])
        self.assertEqual(calls[2][0], 'PATCH')
        self.assertEqual(calls[2][2], 'old')
        self.assertNotIn('attendees', calls[0][1])

    def test_outlook_transaction_identity_and_utc_contract(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append(body)
            return {'id': 'remote', '@odata.etag': 'v1'}
        config = {'provider': 'outlook', 'calendar': 'primary', 'token': 'test', 'identity': 'profile:123', 'event': {'title': 'Plan', 'starts_at': '2026-12-05 09:00', 'ends_at': '2026-12-05 10:00'}}
        with patch.object(worker.Api, 'request', request):
            worker.calendar_write(config)
            worker.calendar_write(config)
        self.assertEqual(calls[0]['transactionId'], calls[1]['transactionId'])
        self.assertEqual(calls[0]['start']['timeZone'], 'UTC')
        self.assertNotIn('attendees', calls[0])


if __name__ == '__main__': unittest.main()
