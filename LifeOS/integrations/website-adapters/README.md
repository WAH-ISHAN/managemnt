# Connect different website technologies

LifeOS is one owner workspace for many businesses and websites. Each website supplies its own authorized `lifeos-admin-v1` endpoint. The framework used to build the public frontend does not limit the protocol. A public URL or an unrelated site's login form is not a content API.

| Website backend | Adapter | Endpoint | Authentication |
| --- | --- | --- | --- |
| PHP | [Reference connector](../website-connector/README.md) | `/api/lifeos-admin.php` | Website account or legacy API token |
| WordPress | [Installable plugin](wordpress/README.md) | `/wp-json/lifeos/v1/admin` | WordPress username + Application Password |
| Next.js / Node.js | [Server adapter](nextjs/README.md) | `/api/lifeos-admin` | Server-side API token using Advanced in LifeOS |
| Laravel, Django, Java, other backends | Implement the [HTTP contract](../website-connector/PROTOCOL.md) against the site's own models | Configurable HTTPS endpoint | Site-controlled accounts or API tokens |

Add each website and its business name in LifeOS, install/configure the matching backend integration, then connect its exact HTTPS endpoint. Use the business selector for one business, or All businesses for the whole portfolio. Each website retains independent permissions, content and connection state.

The supplied adapters expose specific capabilities. They do not automatically provide payment administration, hosting control, file-system access, every third-party plugin or arbitrary database edits. Add those features through explicit site-specific services and permissions.
