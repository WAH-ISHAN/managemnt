# `lifeos-admin-v1` protocol

The protocol is a backend-independent contract. Implement it against authorized site models in any language. The reference PHP implementation uses its own SQLite content store; connecting another site's content requires its own adapter.

## Transport

Send all actions to the configured exact **HTTPS** endpoint with:

```text
POST /api/lifeos-admin.php
Content-Type: application/json
Authorization: Bearer <secret>
```

The token never belongs in the URL or content responses. Only the login response issues a new short-lived token. Do not redirect an authenticated request to another destination. The reference accepts request bodies up to 256 KiB, JSON nesting up to 32 levels and list pages up to 50 records. LifeOS may enforce its own stricter response/schema limits. A successful response is JSON containing `"protocol":"lifeos-admin-v1"`.

## Client login and logout

The login action uses the same HTTPS endpoint and JSON headers, without a bearer header:

```json
{"protocol":"lifeos-admin-v1","action":"login","username":"client-name","password":"client-password"}
```

A successful response supplies a short-lived opaque bearer token and an absolute Unix expiry in seconds:

```json
{"protocol":"lifeos-admin-v1","token":"<random secret of at least 32 visible ASCII characters>","expires_at":1900000000,"username":"client-name"}
```

Use that bearer for manifest and content actions. LifeOS stores the token encrypted in its current local session and caps its lifetime to 30 minutes. It never saves the submitted password. Every website profile has its own bearer, manifest, expiry and content cache, so multiple websites can stay connected in one LifeOS installation. These are independent website logins, not shared single sign-on.

The server must authenticate against that site's users and enforce their collection/action permissions on every request. The manifest includes only the current account's permitted collections and capabilities. Never let a client choose its own permissions or target another site's database. Return 401 for invalid/expired authentication, 403 for denied actions, and 429 for excessive login attempts. Do not echo passwords or internal authentication errors.

Revoke a session with an authenticated request:

```json
{"protocol":"lifeos-admin-v1","action":"logout"}
```

Return `{"protocol":"lifeos-admin-v1","logged_out":true}` after revocation. The reference hashes all bearer tokens in its private database and revokes all account sessions on password reset, role changes or disabling. The optional configured legacy administrator token remains supported for existing connectors; it has full reference-collection access and must not be shared with restricted client accounts.

## Manifest

Request:

```json
{"protocol":"lifeos-admin-v1","action":"manifest"}
```

Response example:

```json
{
  "protocol":"lifeos-admin-v1",
  "site":{"name":"My website"},
  "collections":[{
    "id":"pages",
    "label":"Pages",
    "fields":[
      {"name":"title","label":"Title","type":"text","required":true,"max_length":200},
      {"name":"body","label":"Page text","type":"textarea","required":false,"max_length":20000},
      {"name":"published","label":"Published","type":"boolean","required":false}
    ],
    "capabilities":["list","create","update","delete"]
  }]
}
```

Collection IDs and field names use lowercase identifiers matching `[a-z][a-z0-9_]{0,63}`. Labels are display text, never HTML or code. Supported field types are `text`, `textarea`, `number`, `boolean` and `date`. `max_length` is a string limit, up to 20000 characters in the LifeOS adapter. Dates use `YYYY-MM-DD`, or an empty string when optional. Numbers must be finite JSON numbers within ±1e12; booleans must be JSON booleans. A required string cannot be blank.

The reference's actual manifest additionally defines page slugs/publication dates and product fields. Generate forms from the response rather than hardcoding those example collections in the client. File upload, executable HTML, nested objects, SQL queries and arbitrary shell/filesystem actions are not part of this protocol.

Capabilities describe the operations the authenticated caller may use. Enforce them in the server's action handler too. Keep authorization and collection-specific validation on the server; never trust only the generated UI.

## List

Request:

```json
{"protocol":"lifeos-admin-v1","action":"list","collection":"pages","offset":0,"limit":50}
```

Response:

```json
{
  "protocol":"lifeos-admin-v1",
  "records":[{"id":"record-123","version":1,"data":{"title":"About","body":"Our story","published":false}}],
  "total":1,
  "offset":0,
  "limit":50
}
```

Use a stable record order and a nonnegative integer total/offset. Return at most the requested limit. A record's ID is an opaque string matching `[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}`; its version is a positive integer. `data` contains the declared scalar fields. Do not return server credentials or internal filesystem paths.

## Create, update and delete

Create request:

```json
{"protocol":"lifeos-admin-v1","action":"create","collection":"pages","data":{"title":"About","slug":"about","body":"Our story","published":false,"published_on":""}}
```

Return the saved record as `{"protocol":"lifeos-admin-v1","record":{"id":"...","version":1,"data":{...}}}`. Apply the site's own validation, uniqueness and business rules. Do not invent income, orders or user identities simply because a client submits a content record.

Update request:

```json
{"protocol":"lifeos-admin-v1","action":"update","collection":"pages","id":"record-123","version":1,"data":{"title":"Updated title","slug":"about","body":"Updated story","published":true,"published_on":"2026-10-29"}}
```

`data` is the full record field set, not a JSON patch. Return the actual saved record with its incremented version. Check the expected version atomically with the update. A stale version returns HTTP `409` and must leave the record unchanged.

Delete request:

```json
{"protocol":"lifeos-admin-v1","action":"delete","collection":"pages","id":"record-123","version":2}
```

Return `{"protocol":"lifeos-admin-v1","deleted":true}` only when the requested authorized deletion was accepted. Scope IDs to their specified collection. Use a transaction for the write and any associated history/audit entry.

## Error contract

Use an appropriate non-2xx HTTP status and a small JSON body:

```json
{"protocol":"lifeos-admin-v1","error":"The record has changed. Refresh before trying again."}
```

Typical statuses: `400` malformed JSON; `401` failed authentication; `403` unauthorized action or insecure transport; `404` missing record; `405` wrong method; `409` version/uniqueness conflict; `413` oversized body; `415` wrong content type; `422` invalid fields; `503` unavailable server configuration/storage. Do not expose stack traces, tokens, SQL errors or private paths. LifeOS treats remote error text as untrusted and may display only its own generic status message.

## Adapter acceptance checks

Before pointing LifeOS at production, verify a normal write, a rejected invalid write, a stale update/delete, wrong-collection ID, pagination, persistent state after server restart, draft/public visibility and the site's actual rendered result. Verify unauthorized requests cannot read or mutate private content. Use disposable staging data and the site's recovery procedure for destructive tests.
