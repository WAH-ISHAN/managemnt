# Sanitized admin source references

These snapshots preserve selected admin source and shared implementation concepts from the two local projects supplied by the user. They are reference material for LifeOS Website Manager, **not standalone installed admin panels and not proof that both sites are connected**.

| Reference | Source | Snapshot |
| --- | --- | --- |
| CreativeFX | `D:\OFZ\creative-_6` | `creativefx/source/` |
| TenderHub | `D:\OFZ\tender` | `tenderhub/source/` |

`capabilities.json` describes the complete main admin-navigation inventory, source paths, API families and integration blockers. `../../qa/admin-reference-audit.md` explains the feature inventory and adapter differences in detail.

## What was included

CreativeFX: all files under `src/admin`, shared field/types/provider/persistence and directly imported source utilities, `server.cjs` and the package manifest.

TenderHub: all console pages, admin entry and admin API proxy source, directly imported shared frontend components/libraries, PHP admin/auth controllers, models and filters, route declarations, API base controller and package manifests.

## What was omitted or sanitized

No environment files, deployed configuration, runtime databases, authored content JSON, session files, user records, uploads, media, node_modules, build output, cache or logs were copied. The CreativeFX content provider bundles a large default site-content object in source; that object was replaced with a schema placeholder while its persistence implementation was retained. Embedded legacy credential definitions and source email addresses were sanitized. No production credential is needed to read these references.

Each project has a `manifest.json` containing the original source SHA-256, original SHA-256 after capture, copied SHA-256, redaction categories/counts and excluded imported data paths. Every selected original file was re-read after capture and its hash remained unchanged. Sanitized copies can intentionally have a different hash and may require target-site schemas/configuration before compiling.

`snapshot.py` is the reproducible read-only capture helper. It writes only into this directory. It does not run either reference server, execute their scripts, contact their domains or change either source project.

## Connecting the real sites

CreativeFX's API uses `x-admin-token`, full nested content-document saves and separate multipart media/inquiry operations. TenderHub's API uses staff JWT/tenant/role/entitlement checks and action-specific services. Neither API implements the generic `lifeos-admin-v1` connector protocol automatically.

A site-specific adapter or an explicitly installed connector is required. Domain/IP metadata does not confer access. Preserve server-side permissions and unknown content fields; do not replace production documents with blank template data. Before enabling writes, validate the adapter against isolated data and an authorized site connection.

The local source projects and their deployments remain independent of LifeOS. No deployment, live write, source mutation or credential transfer occurred during this snapshot.
