"""Exercises the real loopback OAuth callback with fake provider token exchange."""
import base64
import contextlib
import hashlib
import http.client
import importlib.util
import io
import json
from pathlib import Path
import socket
from types import SimpleNamespace
import threading
import unittest
from unittest.mock import patch
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('calendar_authorize', ROOT / 'scripts/authorize_calendar.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class AuthorizationTests(unittest.TestCase):
    def test_real_callback_state_pkce_and_refresh_token_exchange(self):
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        captured = {}
        failures = []
        statuses = []
        def browser(url):
            values = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)
            captured.update(values)
            def callback():
                try:
                    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                    connection.request('GET', '/callback?state=wrong&code=bad')
                    response = connection.getresponse()
                    statuses.append(response.status)
                    response.read()
                    connection.close()
                    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                    connection.request('GET', '/callback?' + urllib.parse.urlencode({'state': values['state'][0], 'code': 'one-time-code'}))
                    response = connection.getresponse()
                    statuses.append(response.status)
                    response.read()
                    connection.close()
                except Exception as error:
                    failures.append(error)
            thread = threading.Thread(target=callback)
            captured['thread'] = thread
            thread.start()
            return True
        class Response:
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, size): return b'{"refresh_token":"test-refresh","access_token":"test-access"}'
        class Opener:
            def open(self, request, timeout):
                values = urllib.parse.parse_qs(request.data.decode())
                self_case.assertEqual(values['code'], ['one-time-code'])
                self_case.assertEqual(values['client_secret'], ['test-client-secret'])
                challenge = base64.urlsafe_b64encode(hashlib.sha256(values['code_verifier'][0].encode()).digest()).rstrip(b'=').decode()
                self_case.assertEqual(captured['code_challenge'], [challenge])
                self_case.assertEqual(captured['code_challenge_method'], ['S256'])
                self_case.assertEqual(request.full_url, 'https://oauth2.googleapis.com/token')
                return Response()
        self_case = self
        out, err = io.StringIO(), io.StringIO()
        with patch.object(module.webbrowser, 'open', browser), patch.object(module.urllib.request, 'build_opener', return_value=Opener()), patch.object(module.getpass, 'getpass', return_value='test-client-secret'), patch.dict(module.os.environ, {'LIFEOS_OAUTH_CLIENT_SECRET': ''}), contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            module.authorize(SimpleNamespace(provider='google', client_id='client-id', tenant='common', port=port))
        captured['thread'].join(5)
        self.assertEqual(failures, [])
        self.assertEqual(statuses, [400, 200])
        self.assertEqual(json.loads(out.getvalue())['refresh_token'], 'test-refresh')
        self.assertNotIn('test-client-secret', out.getvalue() + err.getvalue())
        self.assertNotIn('test-refresh', err.getvalue())
        self.assertIn('offline', captured['access_type'])


if __name__ == '__main__': unittest.main()
