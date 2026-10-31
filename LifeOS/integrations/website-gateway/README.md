# LifeOS website provider gateway

Deploy this service once on a PHP VPS/hosting account controlled by your agency. Clients receive a gateway address, client username and generated client password. They never receive hosting, Vercel, email, GitHub or website-origin connector credentials.

LifeOS → agency gateway → assigned websites / public content database.

## Deployment

Requires PHP 8.3+, PDO SQLite, OpenSSL, mbstring and cURL. Set the document root to this package's **public** directory and configure HTTPS. The SQLite database must be in a writable private directory outside the document root. No public installer or default admin password exists.

Set server-only environment variables for both PHP-FPM and the management CLI:

- `LIFEOS_GATEWAY_DB`: absolute path, e.g. `/srv/lifeos-private/gateway.db`.
- `LIFEOS_GATEWAY_KEY`: base64 encoding of 32 random bytes, generated with `php -r "echo base64_encode(random_bytes(32));"`. Keep it stable and back it up separately from the database.
- `LIFEOS_GATEWAY_URL`: public endpoint, e.g. `https://manage.example.com/index.php`.

Enable PATH_INFO: `/index.php/sites/orean` and `/index.php/content/orean` must route to `public/index.php` with that suffix in `PATH_INFO`. For Nginx use `fastcgi_split_path_info ^(.+\.php)(/.*)$;`, pass `PATH_INFO $fastcgi_path_info`, and restrict PHP execution to the public entry point. For Apache enable `AcceptPathInfo On` and forward the Authorization header (`CGIPassAuth On` where needed). Terminate TLS at the server; trusted reverse-proxy deployments must set PHP's HTTPS flag through server configuration. Incoming forwarded headers are not trusted.

Keep the gateway source, private database, backups and management commands outside the website document root. Back up the SQLite database using SQLite's online backup API. Back up the encryption key securely; losing it prevents decrypting origin tokens.

## Provision websites (operator only)

Send a JSON definition on stdin: `php bin/manage.php site < site.json`. Provisioning creates a new site and refuses to overwrite existing live content.

**Proxy mode** integrates a real WordPress/PHP/Next/custom backend adapter supporting `lifeos-admin-v1`:

```json
{"slug":"shop","name":"Client shop","business_name":"Client business","base_url":"https://shop.example.com","mode":"proxy","endpoint":"https://shop.example.com/api/lifeos-admin","token":"ORIGIN_SECRET_CONFIGURED_BY_OPERATOR"}
```

The endpoint is registered by the operator, never supplied by a client request. Only public HTTPS endpoints are accepted, redirects/private IPs are blocked, and origin tokens are encrypted at rest. An arbitrary URL without a compatible API cannot expose an admin panel.

For adapters using username/password login (including the WordPress adapter), replace `token` with `username` and `password`. For WordPress use a dedicated editor account and its Application Password, not hosting credentials. These origin credentials are encrypted at rest and remain on the gateway. The gateway logs in upstream, encrypts/caches the resulting session, and renews it before expiration. Revoked upstream credentials cause requests to fail closed; clients never receive them. Site permissions in the gateway still restrict the actions of each client independently of the operator account.

**Content mode** stores website copy directly in the gateway SQLite database. Seed it with a schema (`collections`, each with `list` and `update` capabilities) and a `document` containing public records. Supports text/textarea fields and existing-record updates; clients cannot create/delete structural records. The public website reads `/index.php/content/SITE_SLUG`. This URL exposes **only the intentionally public website copy** for a content-mode site. Proxy sites have no public content endpoint.

For Orean, run `node scripts/export-gateway.mjs` in its project and pipe/write the JSON output to `php bin/manage.php site` on your gateway host. Its seven existing content sections are preserved. No GitHub token or GitHub Actions are used for edits.

## Provision client accounts (operator only)

`php bin/manage.php account < client.json` with:

```json
{"username":"client1","sites":{"orean":["list","update"],"shop":["list","create","update","delete"]}}
```

Assign up to 30 sites. `list` is required; grant only needed actions. The command prints the generated client password once so the operator can hand it to that client privately. It stores a password hash, not the password. Re-running account provisioning rotates the password and immediately revokes existing sessions. `php bin/manage.php disable client1` disables the account and revokes sessions.

In LifeOS → Website Manager → **Sign in with your website provider**, enter the gateway URL and client account. One login discovers only assigned websites, imports/reuses their profiles, and opens separate 30-minute sessions for each. Signing out one website leaves other website sessions active. Local maintenance costs stay in LifeOS. Profiles already using another endpoint are not silently reassigned.

Account access is checked on every request; guessing another site slug does not grant access. Login attempts are rate limited. Passwords are not retained in LifeOS, bearer sessions are hashed in gateway storage, and passwords/origin tokens are omitted from client discovery responses. Normal local desktop data remains separate from agency gateway data.

## Orean deployment

After provisioning its public content in the gateway, configure **only** `LIFEOS_PUBLIC_CONTENT_URL=https://manage.example.com/index.php/content/orean` on the Orean Next.js server and deploy the prepared project once through your existing hosting workflow. The server reads fresh gateway content on each page request. Subsequent LifeOS edits are SQLite writes and require no GitHub commit, workflow or site rebuild. Visitors see changes on their next page load. If the gateway is unavailable, the site displays its bundled original copy until it recovers.

This package and the local source changes do not deploy themselves. A live agency gateway URL and the initial website deployment are required before production can connect.
