# WordPress plugin

Install the `lifeos-wordpress.php` plugin on a self-hosted WordPress website with HTTPS. The plugin uses WordPress's own REST post controllers, account capabilities and content models. It exposes **Pages** and **Posts**, not an unrelated SQLite database.

1. Upload/activate the LifeOS Website Connector plugin.
2. In WordPress, open your user's profile and create an **Application Password** named LifeOS. The user needs permission to edit the corresponding pages/posts.
3. Set the LifeOS website endpoint to `https://your-domain/wp-json/lifeos/v1/admin`.
4. Sign in with the WordPress username and that Application Password. Use a dedicated application password, not the interactive admin password.

The returned session expires in 30 minutes. Logout revokes it. Revoking the application password or changing the WordPress account password also invalidates sessions. Role/capability checks run for every operation, including individual records. A site's security plugins or host can require an allow rule for this authenticated REST route. WordPress must correctly report HTTPS behind a trusted proxy.

Editable fields are title, slug, raw WordPress body/block markup and status. Unknown metadata, taxonomies, featured media and plugin fields are preserved by partial native REST updates. Review block markup carefully; this is a text editor, not Gutenberg. Content over 20,000 characters must be edited in WordPress. WooCommerce orders/products, media uploads, payments, plugin settings and hosting administration require separate adapters.

Deletes move content to WordPress Trash. Native WordPress revisions and backups remain the recovery tools. The plugin tracks content revisions using `save_post` and serializes LifeOS writes per post; stale versions and posts locked by another WordPress editor are rejected. An unrelated external API writer can still race the native WordPress save flow, which does not provide a cross-system transaction. Validate on staging before enabling writes to business-critical content. A terminated PHP worker can leave a `lifeos_edit_POST_ID` option; after verifying no edit is active, an administrator can remove that stale option with WordPress's normal tools.

The login limiter uses WordPress transients. Use server/proxy-level rate limiting as well on publicly exposed deployments; transient increments are not a distributed atomic counter. Keep the HTTP request body limit at 256 KiB or lower at the server/proxy. WordPress transients must be persistent/shared across workers for sessions to survive multiple requests (the default database-backed implementation satisfies this).

Validation here includes protocol/permission contract tests and PHP syntax checks; a live WordPress staging install is still required to verify compatibility with your site's plugins, roles and hosting environment.

References: [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/), [REST route permissions](https://developer.wordpress.org/reference/functions/register_rest_route/).
