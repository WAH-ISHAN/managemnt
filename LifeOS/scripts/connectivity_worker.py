"""Optional local adapters, Python 3 standard library only. Configuration arrives via stdin.
Never writes credentials or mail/calendar payloads to files or diagnostic output.
"""
import datetime as dt
import email
import email.header
import email.policy
import hashlib
import imaplib
import json
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

COLOMBO = dt.timezone(dt.timedelta(hours=5, minutes=30))


def header(value):
    return str(email.header.make_header(email.header.decode_header(value or '')))


def imap_poll(c):
    context = ssl.create_default_context()
    if not c['subject']:
        raise ValueError('A subject filter is required')
    quote = lambda s: '"' + str(s).replace('\\', '\\\\').replace('"', '\\"') + '"'
    with imaplib.IMAP4_SSL(c['host'], int(c['port']), ssl_context=context, timeout=15) as box:
        box.login(c['user'], c['password'])
        result, _ = box.select(c['folder'], readonly=True)
        if result != 'OK':
            raise ValueError('Mailbox unavailable')
        validity = (box.response('UIDVALIDITY')[1] or [b'unknown'])[0].decode()
        criteria = ['SUBJECT', quote(c['subject'])]
        if c.get('sender'):
            criteria += ['FROM', quote(c['sender'])]
        result, values = box.uid('search', 'CHARSET', 'UTF-8', *(v.encode('utf-8') for v in criteria))
        if result != 'OK':
            raise ValueError('Mailbox search failed')
        messages = []
        for uid in values[0].split()[-50:]:
            result, response = box.uid('fetch', uid, '(BODY.PEEK[]<0.131072>)')
            if result != 'OK':
                continue
            raw = next((part[1] for part in response if isinstance(part, tuple)), b'')
            msg = email.message_from_bytes(raw, policy=email.policy.default)
            sender, subject = header(msg.get('From')), header(msg.get('Subject'))
            # Server search is advisory: recheck decoded headers locally before previewing.
            if c['subject'].casefold() not in subject.casefold():
                continue
            if c.get('sender') and c['sender'].casefold() not in sender.casefold():
                continue
            body = msg.get_body(preferencelist=('plain',)) if msg.is_multipart() else msg
            try:
                text = body.get_content() if body and body.get_content_type() == 'text/plain' else ''
            except (LookupError, UnicodeError):
                text = ''
            identity = hashlib.sha256(('\0'.join([c['host'], c['user'], c['folder'], validity, uid.decode()])).encode()).hexdigest()
            messages.append({'identity': identity, 'sender': sender[:500], 'subject': subject[:200], 'body': text[:5000]})
        return {'messages': messages}


class Api:
    def __init__(self, token, host):
        self.token, self.host = token, host

    def request(self, method, url, body=None, version=None):
        if urllib.parse.urlsplit(url).scheme != 'https' or urllib.parse.urlsplit(url).netloc != self.host:
            raise ValueError('Unexpected provider URL')
        headers = {'Authorization': 'Bearer ' + self.token, 'Content-Type': 'application/json', 'Prefer': 'outlook.timezone="UTC"'}
        if version:
            headers['If-Match'] = version
        request = urllib.request.Request(url, data=json.dumps(body).encode() if body is not None else None, headers=headers, method=method)
        # Refuse redirects rather than forwarding provider credentials.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *args, **kwargs):
                return None
        opener = urllib.request.build_opener(NoRedirect())
        with opener.open(request, timeout=15) as response:
            raw = response.read(4 * 1024 * 1024 + 1)
            if len(raw) > 4 * 1024 * 1024:
                raise ValueError('Provider response is too large')
            return json.loads(raw) if raw else {}


def parse_time(value, zone=None):
    # Providers are requested in UTC. Google supplies an explicit RFC3339 offset.
    date = dt.datetime.fromisoformat(value.replace('Z', '+00:00'))
    if date.tzinfo is None:
        if zone not in ('UTC', 'Etc/UTC', None):
            raise ValueError('Unexpected provider timezone')
        date = date.replace(tzinfo=dt.timezone.utc)
    return date.astimezone(COLOMBO).strftime('%Y-%m-%d %H:%M')


def calendar_fetch(c):
    google = c['provider'] == 'google'
    api = Api(c['token'], 'www.googleapis.com' if google else 'graph.microsoft.com')
    now = dt.datetime.now(dt.timezone.utc)
    start, end = (now - dt.timedelta(days=30)).isoformat(), (now + dt.timedelta(days=365)).isoformat()
    if google:
        base = 'https://www.googleapis.com/calendar/v3/calendars/' + urllib.parse.quote(c['calendar'], safe='') + '/events'
        params = {'timeMin': start, 'timeMax': end, 'singleEvents': 'true', 'showDeleted': 'true', 'maxResults': 250}
        url = base + '?' + urllib.parse.urlencode(params)
    else:
        calendar = '' if c['calendar'] in ('', 'primary') else '/calendars/' + urllib.parse.quote(c['calendar'], safe='')
        base = 'https://graph.microsoft.com/v1.0/me' + calendar + '/calendarView'
        url = base + '?' + urllib.parse.urlencode({'startDateTime': start, 'endDateTime': end, '$top': 250})
    events, skipped = [], 0
    for _ in range(20):
        result = api.request('GET', url)
        for item in result.get('items' if google else 'value', []):
            canceled = item.get('status') == 'cancelled' if google else bool(item.get('isCancelled'))
            event = {'remote_id': item['id'], 'version': item.get('etag' if google else '@odata.etag', ''), 'cancelled': canceled}
            if canceled:
                events.append(event)
                continue
            if (google and 'dateTime' not in item.get('start', {})) or (not google and item.get('isAllDay')):
                skipped += 1
                continue
            event.update({'title': item.get('summary' if google else 'subject', '') or 'Calendar event',
                          'starts_at': parse_time(item['start']['dateTime'], item['start'].get('timeZone')),
                          'ends_at': parse_time(item['end']['dateTime'], item['end'].get('timeZone')),
                          'location': item.get('location', '') if google else item.get('location', {}).get('displayName', ''),
                          'notes': (item.get('description', '') if google else item.get('bodyPreview', ''))[:2000]})
            events.append(event)
        if google and result.get('nextPageToken'):
            params['pageToken'] = result['nextPageToken']
            url = base + '?' + urllib.parse.urlencode(params)
        elif not google and result.get('@odata.nextLink'):
            url = result['@odata.nextLink']
        else:
            return {'events': events, 'skipped_all_day': skipped}
    raise ValueError('Calendar exceeds 5000-event sync limit')


def calendar_write(c):
    google = c['provider'] == 'google'
    api = Api(c['token'], 'www.googleapis.com' if google else 'graph.microsoft.com')
    row = c['event']
    utc = lambda value: dt.datetime.fromisoformat(value).replace(tzinfo=COLOMBO).astimezone(dt.timezone.utc).isoformat()
    start, end = utc(row['starts_at']), utc(row['ends_at'])
    if google:
        base = 'https://www.googleapis.com/calendar/v3/calendars/' + urllib.parse.quote(c['calendar'], safe='') + '/events'
        body = {'summary': row['title'], 'start': {'dateTime': start}, 'end': {'dateTime': end}, 'location': row.get('location', ''), 'description': row.get('notes', '')}
    else:
        calendar = '' if c['calendar'] in ('', 'primary') else '/calendars/' + urllib.parse.quote(c['calendar'], safe='')
        base = 'https://graph.microsoft.com/v1.0/me' + calendar + '/events'
        body = {'subject': row['title'], 'start': {'dateTime': start, 'timeZone': 'UTC'}, 'end': {'dateTime': end, 'timeZone': 'UTC'}, 'location': {'displayName': row.get('location', '')}, 'body': {'contentType': 'text', 'content': row.get('notes', '')}}
    remote_id = c.get('remote_id')
    if remote_id:
        result = api.request('PATCH', base + '/' + urllib.parse.quote(remote_id, safe=''), body, c.get('version'))
    else:
        stable = hashlib.sha256(c['identity'].encode()).hexdigest()
        body['id' if google else 'transactionId'] = stable if google else str(uuid.UUID(stable[:32]))
        try:
            result = api.request('POST', base, body)
        except urllib.error.HTTPError as error:
            if not google or error.code != 409:
                raise
            result = api.request('GET', base + '/' + stable)
    return {'remote_id': result['id'], 'version': result.get('etag' if google else '@odata.etag', '')}


def oauth_refresh(c):
    google = c['provider'] == 'google'
    tenant = c.get('tenant', 'common')
    if not all(char.isalnum() or char in '.-' for char in tenant):
        raise ValueError('Invalid Microsoft tenant')
    url = 'https://oauth2.googleapis.com/token' if google else 'https://login.microsoftonline.com/' + tenant + '/oauth2/v2.0/token'
    values = {'grant_type': 'refresh_token', 'client_id': c['client_id'], 'refresh_token': c['refresh_token']}
    if c.get('client_secret'):
        values['client_secret'] = c['client_secret']
    if not google:
        values['scope'] = 'offline_access https://graph.microsoft.com/Calendars.ReadWrite'
    request = urllib.request.Request(url, data=urllib.parse.urlencode(values).encode(), headers={'Content-Type': 'application/x-www-form-urlencoded'}, method='POST')
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=15) as response:
        raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError('OAuth response is too large')
        return json.loads(raw)


def main():
    data = sys.stdin.read(131073)
    if len(data) > 131072:
        raise ValueError('Input too large')
    config = json.loads(data)
    function = {'imap': imap_poll, 'calendar-fetch': calendar_fetch, 'calendar-write': calendar_write, 'oauth-refresh': oauth_refresh}[sys.argv[1]]
    print(json.dumps(function(config), ensure_ascii=False))


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Never include upstream error text: providers can echo sensitive request content.
        print(json.dumps({'error': 'Adapter could not complete the request. Check credentials, permissions and connectivity.'}))
        sys.exit(1)
