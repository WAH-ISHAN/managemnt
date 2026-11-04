"""Authorize a personal Google/Outlook calendar using a registered desktop client.
Uses the system browser, loopback callback, one-time state and PKCE. No token files are written.
The resulting refresh token is a secret: paste it into LifeOS Connections and clear the terminal.
"""
import argparse
import base64
import getpass
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
import secrets
import sys
import time
import urllib.parse
import urllib.request
import webbrowser


def authorize(args):
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()
    state = secrets.token_urlsafe(32)
    result = {}

    class Callback(BaseHTTPRequestHandler):
        def log_message(self, *args): pass
        def do_GET(self):
            parts = urllib.parse.urlsplit(self.path)
            values = urllib.parse.parse_qs(parts.query)
            valid = parts.path == '/callback' and secrets.compare_digest(values.get('state', [''])[0], state)
            self.send_response(200 if valid else 400)
            self.send_header('Content-Type', 'text/plain; charset=utf-8')
            self.send_header('Cache-Control', 'no-store')
            self.end_headers()
            if valid:
                result.update(values)
                self.wfile.write(b'Authorization received. Return to the terminal to finish LifeOS calendar setup.')
            else:
                self.wfile.write(b'Invalid authorization callback.')

    google = args.provider == 'google'
    if not all(char.isalnum() or char in '.-' for char in args.tenant):
        raise ValueError('Invalid tenant')
    with HTTPServer(('127.0.0.1', args.port), Callback) as server:
        redirect = 'http://127.0.0.1:%d/callback' % server.server_port
        print('Registered loopback redirect URI: ' + redirect, file=sys.stderr)
        print('Register this URI in your provider desktop app before continuing.', file=sys.stderr)
        scope = 'https://www.googleapis.com/auth/calendar.events' if google else 'offline_access https://graph.microsoft.com/Calendars.ReadWrite'
        values = {'client_id': args.client_id, 'redirect_uri': redirect, 'response_type': 'code', 'scope': scope, 'state': state, 'code_challenge': challenge, 'code_challenge_method': 'S256'}
        if google:
            values.update(access_type='offline', prompt='consent')
        endpoint = 'https://accounts.google.com/o/oauth2/v2/auth' if google else 'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize' % args.tenant
        url = endpoint + '?' + urllib.parse.urlencode(values)
        print('Opening your system browser for calendar authorization.', file=sys.stderr)
        if not webbrowser.open(url):
            print('Open this authorization URL in your browser: ' + url, file=sys.stderr)
        server.timeout = 1
        deadline = time.monotonic() + 240
        while not result and time.monotonic() < deadline:
            server.handle_request()
    if not result.get('code'):
        raise ValueError('Authorization was declined or timed out')
    token_values = {'grant_type': 'authorization_code', 'client_id': args.client_id, 'redirect_uri': redirect, 'code': result['code'][0], 'code_verifier': verifier}
    secret = os.environ.get('LIFEOS_OAUTH_CLIENT_SECRET', '')
    if google and not secret:
        secret = getpass.getpass('Google desktop client secret (from downloaded client JSON): ')
    if secret:
        token_values['client_secret'] = secret
    token_url = 'https://oauth2.googleapis.com/token' if google else 'https://login.microsoftonline.com/%s/oauth2/v2.0/token' % args.tenant
    request = urllib.request.Request(token_url, data=urllib.parse.urlencode(token_values).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'}, method='POST')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs): return None
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=20) as response:
        raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError('OAuth response is too large')
        tokens = json.loads(raw)
    if not tokens.get('refresh_token'):
        raise ValueError('Provider did not grant offline authorization. Revoke prior consent and authorize again.')
    print(json.dumps({'provider': args.provider, 'client_id': args.client_id, 'refresh_token': tokens['refresh_token']}, indent=2))
    print('Paste the refresh token and client ID into Connections. For Google, also enter the desktop client secret.', file=sys.stderr)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--provider', required=True, choices=['google', 'outlook'])
    parser.add_argument('--client-id', required=True)
    parser.add_argument('--tenant', default='common')
    parser.add_argument('--port', type=int, default=8766)
    args = parser.parse_args()
    if not 1024 <= args.port <= 65535:
        parser.error('Choose a loopback callback port between 1024 and 65535')
    authorize(args)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Calendar authorization failed (%s). Check client registration, consent and redirect URI.' % type(error).__name__, file=sys.stderr)
        sys.exit(1)
