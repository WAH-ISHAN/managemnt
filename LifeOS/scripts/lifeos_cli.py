"""Scoped loopback LifeOS CLI. Set LIFEOS_API_TOKEN; credentials never go in URLs."""
import argparse
import json
import os
import sys
import urllib.parse
import urllib.request


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url', default=os.environ.get('LIFEOS_API_URL', 'http://127.0.0.1:8787'))
    p.add_argument('--profile', default=os.environ.get('LIFEOS_PROFILE', ''))
    commands = p.add_subparsers(dest='command', required=True)
    commands.add_parser('tasks')
    create = commands.add_parser('add')
    create.add_argument('title')
    create.add_argument('--due', default='')
    complete = commands.add_parser('complete')
    complete.add_argument('id', type=int)
    args = p.parse_args()
    base = urllib.parse.urlsplit(args.url)
    if base.scheme != 'http' or base.hostname != '127.0.0.1' or base.username or base.password:
        p.error('Use a plain loopback URL such as http://127.0.0.1:8787')
    token = os.environ.get('LIFEOS_API_TOKEN', '')
    if not token:
        p.error('Set LIFEOS_API_TOKEN to a scoped token from Connections')
    path = '/api/v1/tasks'
    data = None
    if args.command == 'add':
        data = {'title': args.title, 'due': args.due}
    elif args.command == 'complete':
        path += '/%s/complete' % args.id
        data = {}
    headers = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'}
    if args.profile:
        headers['X-LifeOS-Profile'] = args.profile
    request = urllib.request.Request(args.url.rstrip('/') + path, data=json.dumps(data).encode() if data is not None else None, headers=headers)
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=15) as response:
        print(json.dumps(json.load(response), ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('LifeOS request failed: %s' % type(error).__name__, file=sys.stderr)
        sys.exit(1)
