"""Explicit HTTPS-only LAN companion. PHP stays loopback-only.

Set LIFEOS_MOBILE_TOKEN to a scoped tasks.read/tasks.write token from Connections.
Supply a TLS certificate trusted by your phone; never expose this listener to the Internet.
"""
import argparse
import hmac
import http.cookies
import ipaddress
import json
import os
from pathlib import Path
import secrets
import ssl
import threading
import time
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, ProxyHandler, HTTPRedirectHandler
from urllib.error import HTTPError

ASSETS = Path(__file__).resolve().parents[1] / 'public' / 'companion'

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--listen', required=True, help='Private LAN IPv4 address, e.g. 192.168.1.10')
    p.add_argument('--port', type=int, default=9443)
    p.add_argument('--local-url', required=True, help='LifeOS loopback URL, e.g. http://127.0.0.1:8787')
    p.add_argument('--profile', default='default')
    p.add_argument('--cert', required=True)
    p.add_argument('--key', required=True)
    p.add_argument('--loopback-test', action='store_true', help=argparse.SUPPRESS)
    args = p.parse_args()
    address = ipaddress.ip_address(args.listen)
    if address.version != 4 or not address.is_private or address.is_loopback and not args.loopback_test or address.is_unspecified:
        p.error('Choose a specific private LAN IPv4 address.')
    local = urlsplit(args.local_url)
    if local.scheme != 'http' or local.hostname != '127.0.0.1' or local.path not in ('', '/') or local.username or local.query or local.fragment:
        p.error('Local URL must be http://127.0.0.1:PORT without a path.')
    token = os.environ.get('LIFEOS_MOBILE_TOKEN', '')
    if len(token) != 64 or any(c not in '0123456789abcdef' for c in token):
        p.error('Set a scoped LIFEOS_MOBILE_TOKEN in this process environment.')
    origin = f'https://{args.listen}:{args.port}'
    code, expires = secrets.token_urlsafe(12), time.monotonic() + 300
    sessions, attempts, lock = {}, {}, threading.Lock()
    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, *a, **kw): return None
    opener = build_opener(ProxyHandler({}), NoRedirect())
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args): pass  # Never record pairing codes or task bodies.
        def send(self, status, body, content_type='application/json', cookie=None):
            self.send_response(status)
            self.send_header('Content-Type', content_type)
            self.send_header('Content-Length', str(len(body)))
            self.send_header('Cache-Control', 'no-store')
            self.send_header('X-Content-Type-Options', 'nosniff')
            self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; frame-ancestors 'none'")
            if cookie: self.send_header('Set-Cookie', cookie)
            self.end_headers()
            self.wfile.write(body)
        def do_GET(self): self.handle_request()
        def do_POST(self): self.handle_request()
        def handle_request(self):
            nonlocal code
            if self.headers.get('Host') != f'{args.listen}:{args.port}':
                return self.send(403, b'{"error":"Invalid host"}')
            peer = ipaddress.ip_address(self.client_address[0])
            if not peer.is_private: return self.send(403, b'{"error":"Private LAN only"}')
            if self.command == 'POST' and self.headers.get('Origin') != origin:
                return self.send(403, b'{"error":"Origin mismatch"}')
            try: length = int(self.headers.get('Content-Length', '0'))
            except ValueError: return self.send(400, b'{}')
            if not 0 <= length <= 65536: return self.send(413, b'{}')
            body = self.rfile.read(length)
            if self.path in ('/', '/app.js', '/style.css', '/sw.js', '/manifest.json') and self.command == 'GET':
                name = 'index.html' if self.path == '/' else self.path[1:]
                mime = {'.html':'text/html; charset=utf-8','.js':'text/javascript','.css':'text/css','.json':'application/manifest+json'}[Path(name).suffix]
                return self.send(200, (ASSETS/name).read_bytes(), mime)
            cookie = http.cookies.SimpleCookie()
            try: cookie.load(self.headers.get('Cookie', ''))
            except http.cookies.CookieError: return self.send(400, b'{}')
            sid = cookie.get('companion').value if cookie.get('companion') else ''
            if self.path == '/pair' and self.command == 'POST':
                try: entered = json.loads(body).get('code', '')
                except (ValueError, AttributeError): return self.send(400, b'{}')
                with lock:
                    key = self.client_address[0]
                    attempts[key] = attempts.get(key, 0) + 1
                    if attempts[key] > 10 or time.monotonic() > expires or not code or not isinstance(entered, str) or not hmac.compare_digest(code, entered):
                        return self.send(403, b'{"error":"Pairing failed or expired"}')
                    code = ''
                    sid = secrets.token_urlsafe(32)
                    sessions[sid] = time.monotonic() + 12*3600
                return self.send(200, b'{"ok":true}', cookie=f'companion={sid}; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=43200')
            if sessions.get(sid, 0) < time.monotonic(): return self.send(401, b'{"error":"Pair this device first"}')
            if self.path == '/logout' and self.command == 'POST':
                with lock: sessions.pop(sid, None)
                return self.send(200, b'{}', cookie='companion=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0')
            if self.path != '/tasks' or self.command not in ('GET', 'POST'): return self.send(404, b'{}')
            path = '/api/v1/tasks' if self.command == 'GET' else '/api/v1/mobile/tasks'
            request = Request(args.local_url.rstrip('/') + path, data=body if self.command == 'POST' else None,
                headers={'Authorization':'Bearer '+token,'X-LifeOS-Profile':args.profile,'Content-Type':'application/json'},method=self.command)
            try:
                with opener.open(request, timeout=15) as response:
                    data = response.read(1048577)
                    if len(data)>1048576: return self.send(502, b'{"error":"Response too large"}')
                    return self.send(response.status, data)
            except HTTPError as error: return self.send(error.code, b'{"error":"LifeOS refused this request; check token and modules"}')
            except OSError: return self.send(503, b'{"error":"LifeOS is offline"}')
    server = ThreadingHTTPServer((args.listen,args.port),Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(args.cert,args.key)
    server.socket = context.wrap_socket(server.socket,server_side=True)
    print(f'Open {origin} on your phone. Pair within five minutes using: {code}', flush=True)
    try: server.serve_forever()
    except KeyboardInterrupt: pass
    finally: server.server_close()

if __name__ == '__main__': main()
