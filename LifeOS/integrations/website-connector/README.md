# LifeOS website connector reference

This package is an example adapter for the Website Manager in LifeOS. It exposes a small authenticated content API and describes its editable collections. LifeOS reads that description and builds the corresponding forms.

**A domain or server address alone does not provide administration access.** You must own the website, be authorized to change it, and connect an API to the site's real content/models. Any backend can implement the protocol in [PROTOCOL.md](PROTOCOL.md); this reference implementation uses PHP 8.3 and SQLite. It is not a WordPress plugin, a general hosting control panel, a shell, or an automatic database discovery tool.

## What is included

| Path | Purpose |
| --- | --- |
| `public/api/lifeos-admin.php` | Authenticated JSON endpoint |
| `src/Connector.php` | Manifest, typed validation, paginated CRUD and revision history |
| `src/config.php` | Explicit private storage configuration |
| `src/content.php` | Read-only helpers exposing published pages/products to a frontend |
| `public/index.php` | Small sample website that reads and escapes the published content |

The reference collections are **Pages** and **Products**. Draft records remain private. Page bodies and product descriptions are plain text, not executable HTML. Products here are content records; the package does not implement carts, payments, orders or inventory. Website-local client accounts authenticate LifeOS editors.

## 1. Choose how it connects to your website

For an existing website, choose one of these approaches:

1. **Adapt the endpoint to existing models.** Replace this reference storage layer with your site's existing page/product services, permissions and transactions. Return their editable schema using the same protocol. This keeps one source of truth.
2. **Use the reference content store.** Connect your frontend to `src/content.php` or an equivalent read-only content API. Update the existing templates to read these records. The sample `public/index.php` demonstrates the connection.

Uploading the connector without either integration will only edit a separate content store. It will not rewrite an existing website's HTML, database, React bundle or business rules. Avoid pointing it at an unrelated application's database file.

For Node.js, Python, Java, WordPress or another stack, implement/adapt the same HTTP contract. A backend-specific adapter is needed for that site's models and access rules. LifeOS can generate forms for the collections that adapter explicitly advertises; it cannot infer safe write permissions from a public page.

## 2. Configure the server privately

Requirements: PHP 8.3, PDO SQLite, SQLite3 and mbstring; HTTPS at the public endpoint; and a writable private directory outside the document root.

Set these values through your hosting environment or another server-only configuration mechanism:

```text
LIFEOS_CONNECTOR_DB=/absolute/private/directory/content.sqlite
LIFEOS_CONNECTOR_TOKEN=<optional legacy administrator token; omit for client-account-only access>
LIFEOS_CONNECTOR_SITE_NAME=My website
```

If using the optional legacy administrator token, generate a random token on a trusted machine, for example with `bin2hex(random_bytes(32))` in PHP. Treat it as a password. Do not put it in the website URL, frontend JavaScript, screenshots, public files, source control or shared logs. Configure the HTTP server to pass the `Authorization` header to PHP.

The private directory must already exist. Restrict it to the website's service account; on Unix, an owner-only directory such as mode `0700` protects the database and its WAL/SHM files. The connector refuses relative database paths and databases inside the resolved website document root. It does not guess or create a public default database.

Keep `LIFEOS_CONNECTOR_ALLOW_LOCAL_HTTP` **unset in production**. It is a narrowly scoped loopback-only test escape hatch. A public endpoint requires HTTPS; the reference does not trust arbitrary `X-Forwarded-Proto` headers. If a trusted reverse proxy terminates TLS, configure the web server/PHP environment to report HTTPS correctly.

## 3. Deploy the endpoint

For the included sample site, set the server's document root to this package's `public/` directory. Keep `src/` and the content database outside it.

For shared hosting or an existing site, keep the library in a private directory and expose only the small endpoint bootstrap at a path such as `/api/lifeos-admin.php`. Adjust its `require` path to the private library's actual absolute location. Do not expose the whole package, private storage directory or revision database under the public web root.

Do not replace the site's existing index page with the sample unless that is the intended integration. The sample is a demonstration, not an automatic migration of the existing site.

## 4. Create a client account on this website

Run from this package directory on the website server after configuring the private database path:

```text
php bin/account.php set client-name admin pages,products
```

This generates a random password, stores only its password hash, and prints the password once for private delivery to the client. There are no default accounts. Roles are `viewer` (list), `editor` (list/create/update), and `admin` (list/create/update/delete). The final argument selects which reference collections the account can access. Keep `bin/` and `src/` outside the public web root.

Run `set` again to reset the password or change permissions; all existing sessions for that account are revoked. To disable access:

```text
php bin/account.php disable client-name
```

The server issues random sessions lasting 30 minutes, stores only their digests, checks account permissions for every API request, and revokes sessions on logout. Five failed login attempts per username or 20 login attempts per source IP within a 15-minute window are rate limited. Behind a reverse proxy, the reference uses the immediate peer address; configure appropriate per-client rate limiting at your trusted proxy for larger deployments.

For an existing website with its own users, implement the login contract against those users and its existing permissions instead of duplicating accounts. Every website uses its own content database and account/session store.

## 5. Add all your business websites in LifeOS

Create a Website Manager profile with:

- The website's name and public HTTPS URL.
- An optional business name to group related sites in your LifeOS workspace.
- Server address/hosting notes for your own reference.
- The exact HTTPS connector URL, such as `https://your-domain.example/api/lifeos-admin.php`.

Sign in with the website account username and generated password. The advanced API-token form remains available for existing integrations. The returned manifest supplies the collections and field definitions for the generated panel. Use **Refresh/load** to fetch records, then explicitly create, update or delete them through the generated forms.

The server address is informational; it is not SSH access or a substitute for the connector URL. LifeOS's public connector client requires a public HTTPS destination and does not use this reference's local HTTP test switch. Test an actual desktop connection against an authorized HTTPS staging site before connecting production content.

Repeat for all 10 business websites (or more) in your own LifeOS installation. Use the Business selector to focus on one business, or All businesses to manage the whole portfolio. Each business can have multiple website profiles. The overview includes a combined recent-activity feed from actions recorded in LifeOS. Each profile has an independent login, permissions and content cache. All websites shows each site's local sign-in status; the website switcher opens another site's panel without disconnecting the others. Signing out of one site leaves other sessions active. Locking/restarting LifeOS clears all local sessions; remote sessions expire automatically. Passwords are never saved in LifeOS. Profiles and endpoints remain available after restart.

This is a local multi-website workspace, not a hosted client directory or shared cross-device account service. The profile list is visible to the user of that LifeOS installation. Real website content still requires the integration described above; the generated admin panel contains only collections exposed by each backend.

## Frontend example

An existing PHP template can read a published page through the helper:

```php
require '/absolute/private/path/to/website-connector/src/content.php';
$page = lifeos_page_by_slug('about');
if ($page) {
    echo htmlspecialchars($page['data']['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo nl2br(htmlspecialchars($page['data']['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}
```

The helper uses a read-only SQLite handle and omits drafts. You can also call `lifeos_published_pages()` and `lifeos_published_products()`. The sample lists products as content only, with no checkout or payment claims. Add caching and pagination appropriate to the public site's traffic and content volume.

## Conflicts, history and recovery

Updates and deletes require the record's current integer `version`. If another editor changes it first, the endpoint returns HTTP `409`; refresh and review before retrying. The connector performs the accepted write and its revision entry in one SQLite transaction. Page slugs and product SKUs are unique within their collection; duplicate values also return `409`.

`lifeos_revisions` retains the previous/current data for accepted changes, including deleted content. This is operator recovery evidence, not an exposed rollback endpoint. Keep consistent backups of the private database and any separate site assets. This package does not back up the whole website or test your production restoration procedure for you.

Rotate a token in the server environment to revoke existing access. Reconnect authorized clients with the new token. For multiple editors, add the site's normal per-user/per-action authorization instead of sharing one broad token. Collections advertised as read-only must also reject writes on the server; a disabled UI button alone is not access control.

## Local verification

From the LifeOS repository root:

```text
python tests/website_connector.py
php tests/website_accounts.php
```

The test uses a temporary copy of this package, a fresh token and private SQLite database, and a loopback PHP server. It checks authentication, HTTPS enforcement, JSON/body limits, schema validation, CRUD, pagination, version conflicts, revisions, public draft filtering, escaped output and private storage configuration. It never uses a real domain, production token or existing website database. The local HTTP switch is enabled only for those tests and cannot make the production LifeOS client accept an HTTP connector.

For deployment QA, use your own staging domain/data and validate the site's actual templates, permissions and business rules. Passing this package's tests establishes this reference implementation's behavior, not compatibility with every website.

## සිංහලෙන් කෙටියෙන්

Domain/IP එක දුන්නාම ඕනෑම website එකේ admin access ලැබෙන්නේ නැහැ. Website එකට ඔයාගේ අවසරය සහ API/connector setup එකක් අවශ්‍යයි. මේ connector එක දෙන schema එක අනුව LifeOS එකේ forms auto හැදෙනවා. දැනට තියෙන website එකේ data/models එක්ක adapter එක සම්බන්ධ කරන්න, නැත්නම් frontend එක මේ content store එකෙන් data කියවන විදිහට වෙනස් කරන්න. Sample connector එක upload කළා කියලා පරණ website එක තනිවම වෙනස් වෙන්නේ නැහැ.
