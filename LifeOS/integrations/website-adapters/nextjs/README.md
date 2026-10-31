# Next.js / Node.js adapter

`connector.mjs` handles the LifeOS protocol using standard Web Request/Response objects and Node's built-in crypto. It supports manifest, paginated lists, create, update and delete, schema validation, HTTPS enforcement, constant-time bearer authentication and bounded request bodies. It has no framework dependency. Copy the module into an existing Next.js App Router project or call its handler from another compatible Node backend.

## Wire the real content store

1. Copy `connector.mjs` and `github-store.mjs` into `src/lib/lifeos/`.
2. Copy `route.example.js` into `src/app/api/lifeos-admin/route.js` and change the site name, allowed collections and fields for your models.
3. For the example content-as-code store, copy `content.example.json` into `src/content/site-content.json`. Make the website frontend read that document. Uploading an API file alone does not change an existing site's content model.
4. Configure server-only environment variables: `LIFEOS_CONNECTOR_TOKEN` (at least 32 random visible characters), `LIFEOS_GITHUB_TOKEN` (fine-grained repository Contents read/write), `LIFEOS_CONTENT_OWNER`, `LIFEOS_CONTENT_REPO`, and optionally `LIFEOS_CONTENT_BRANCH`.
5. Deploy and enter `https://your-domain/api/lifeos-admin` in LifeOS. Use **Advanced: connect with an API token**. This example does not implement username/password login; use your site's own account/session service when implementing that optional protocol action.

Never use `NEXT_PUBLIC_` for secrets. Generate the connector token on a trusted machine, for example `node -e "process.stdout.write(require('crypto').randomBytes(32).toString('hex'))"`. Do not commit tokens or paste them into URLs.

The GitHub store reads fresh content on every request and supplies the expected file SHA on writes. Conflicts return 409. A save creates a content commit. A connected Vercel Git deployment can publish that commit after its build; saved content and deployed content can temporarily differ while deployment runs. Revert a content commit through the normal repository review/recovery flow when needed. This is intended for relatively infrequent content edits, not order processing or high-volume transactional data.

For an existing database, replace the store with `read()` returning `{document, revision}` and `write(document, expectedRevision, message)` that atomically compares the revision before committing. Throw `ConnectorError(409, ...)` on conflict. Keep all records scoped to this website. No filesystem writes or in-memory content/session store are used, so the adapter can run in a serverless Node runtime. Static export hosting cannot run this server endpoint.

The included `orean.tech` integration in `D:\orean.tech` reads its real homepage content from a versioned JSON document and exposes seven editable collections. It remains unconnected in production until the new route/content are deployed and both server secrets are configured. The existing public website has not been modified by a live API write.

References: [Next.js Route Handlers](https://nextjs.org/docs/app/api-reference/file-conventions/route), [GitHub repository content API](https://docs.github.com/en/rest/repos/contents).
