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

    def test_delete_request_emits_exact_if_match_header_and_no_body(self):
        requests = []
        class Response:
            def __enter__(self): return self
            def __exit__(self, *args): return None
            def read(self, size): return b''
        class Opener:
            def open(self, request, timeout):
                requests.append(request)
                return Response()
        for host, path in [('www.googleapis.com', '/calendar/v3/calendars/primary/events/remote'), ('graph.microsoft.com', '/v1.0/me/events/remote'), ('graph.microsoft.com', '/v1.0/me/calendars/selected/events/remote')]:
            with patch.object(worker.urllib.request, 'build_opener', return_value=Opener()):
                self.assertEqual(worker.Api('test', host).request('DELETE', 'https://' + host + path, version='W/"exact-version"'), {})
            self.assertEqual(requests[-1].method, 'DELETE')
            self.assertEqual(requests[-1].get_header('If-match'), 'W/"exact-version"')
            self.assertIsNone(requests[-1].data)

    def test_google_fetch_expands_instances_and_preserves_cancellation(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append(url)
            return {'items': [{'id': 'a', 'etag': 'v1', 'summary': 'Meeting', 'start': {'dateTime': '2026-12-05T03:00:00Z'}, 'end': {'dateTime': '2026-12-05T04:00:00Z'}}, {'id': 'b', 'etag': 'v2', 'status': 'cancelled'}, {'id': 'c', 'start': {'date': '2026-12-05'}, 'end': {'date': '2026-12-07'}}]}
        with patch.object(worker.Api, 'request', request):
            result = worker.calendar_fetch({'provider': 'google', 'calendar': 'primary', 'token': 'test'})
        self.assertEqual(len(result['events']), 3)
        self.assertTrue(result['events'][2]['all_day'])
        self.assertEqual(result['events'][2]['starts_at'], '2026-12-05 00:00')
        self.assertEqual(result['events'][2]['ends_at'], '2026-12-07 00:00')
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

    def test_all_day_write_preserves_dates_for_both_providers(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append(body)
            return {'id': 'remote', 'etag': 'v1', '@odata.etag': 'v1'}
        config = {'calendar': 'primary', 'token': 'test', 'identity': 'profile:all-day', 'event': {'title': 'Leave', 'all_day': True, 'starts_at': '2026-12-05 00:00', 'ends_at': '2026-12-07 00:00'}}
        with patch.object(worker.Api, 'request', request):
            worker.calendar_write(dict(config, provider='google'))
            worker.calendar_write(dict(config, provider='outlook'))
        self.assertEqual(calls[0]['start'], {'date': '2026-12-05'})
        self.assertEqual(calls[0]['end'], {'date': '2026-12-07'})
        self.assertTrue(calls[1]['isAllDay'])
        self.assertEqual(calls[1]['start'], {'dateTime': '2026-12-05T00:00:00', 'timeZone': 'Sri Lanka Standard Time'})
        self.assertEqual(calls[1]['end']['dateTime'], '2026-12-07T00:00:00')

    def test_outlook_all_day_fetch_uses_original_calendar_date(self):
        calls = []
        def request(api, method, url, body=None, version=None):
            calls.append((url, api.timezone))
            event = {'id': 'all-day', '@odata.etag': 'v1', 'subject': 'Day off', 'isAllDay': True, 'originalStartTimeZone': 'Sri Lanka Standard Time', 'start': {'dateTime': '2026-12-04T18:30:00', 'timeZone': 'UTC'}, 'end': {'dateTime': '2026-12-05T18:30:00', 'timeZone': 'UTC'}}
            if '/calendarView?' in url:
                return {'value': [event]}
            event['start']['dateTime'], event['end']['dateTime'] = '2026-12-05T00:00:00', '2026-12-06T00:00:00'
            return event
        with patch.object(worker.Api, 'request', request):
            result = worker.calendar_fetch({'provider': 'outlook', 'calendar': 'primary', 'token': 'test'})
        self.assertEqual(result['events'][0]['starts_at'], '2026-12-05 00:00')
        self.assertEqual(result['events'][0]['ends_at'], '2026-12-06 00:00')
        self.assertEqual(calls[1][1], 'Sri Lanka Standard Time')

    def test_deletion_is_version_guarded_idempotent_and_never_cancels_attendee_meetings(self):
        for provider in ('google', 'outlook'):
            calls = []
            def request(api, method, url, body=None, version=None):
                calls.append((method, body, version))
                return {'id': 'remote', 'etag': 'v1', '@odata.etag': 'v1'} if method == 'GET' else {}
            config = {'provider': provider, 'calendar': 'primary', 'token': 'test', 'remote_id': 'remote', 'version': 'v1', 'event': {'status': 'cancelled'}}
            with patch.object(worker.Api, 'request', request):
                self.assertTrue(worker.calendar_write(config)['deleted'])
            self.assertEqual(calls, [('GET', None, None), ('DELETE', None, 'v1')])
            with patch.object(worker.Api, 'request', return_value={'etag': 'v2', '@odata.etag': 'v2'}) as stale:
                self.assertTrue(worker.calendar_write(config)['conflict'])
                self.assertEqual(stale.call_count, 1)
            with patch.object(worker.Api, 'request', return_value={'etag': 'v1', '@odata.etag': 'v1', 'attendees': [{'email': 'person@example.org'}]}) as attendees:
                self.assertTrue(worker.calendar_write(config)['conflict'])
                self.assertEqual(attendees.call_count, 1)
            for status in (404, 410):
                with patch.object(worker.Api, 'request', side_effect=urllib.error.HTTPError('https://provider.example', status, '', {}, None)):
                    self.assertTrue(worker.calendar_write(config)['deleted'])
            with patch.object(worker.Api, 'request', side_effect=urllib.error.HTTPError('https://provider.example', 412, '', {}, None)):
                self.assertTrue(worker.calendar_write(config)['conflict'])
            with patch.object(worker.Api, 'request') as missing:
                self.assertTrue(worker.calendar_write(dict(config, version=''))['conflict'])
                missing.assert_not_called()

    def test_conditional_edit_conflict_has_no_success_version(self):
        config = {'provider': 'google', 'calendar': 'primary', 'token': 'test', 'remote_id': 'remote', 'version': 'v1', 'event': {'title': 'Edited', 'starts_at': '2026-12-05 09:00', 'ends_at': '2026-12-05 10:00'}}
        with patch.object(worker.Api, 'request', side_effect=urllib.error.HTTPError('https://provider.example', 412, '', {}, None)):
            self.assertEqual(worker.calendar_write(config), {'conflict': True})


if __name__ == '__main__': unittest.main()
