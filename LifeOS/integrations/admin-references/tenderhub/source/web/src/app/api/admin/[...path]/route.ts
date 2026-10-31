import { NextRequest } from "next/server";
import { forward } from "../../workspace/[...path]/route";

export const dynamic = "force-dynamic";

/**
 * Normalizes admin API request path segments.
 *
 * In CodeIgniter 4, staff console endpoints are registered under the `/admin/`
 * route group (e.g. `/api/v1/admin/users`, `/api/v1/admin/organisations`).
 * Because this route is mounted at `/api/admin/[...path]`, Next.js passes
 * path segments relative to `/api/admin/` (e.g. `['users']`).
 *
 * This normalizer ensures both `/api/admin/users` and legacy `/api/admin/admin/users`
 * resolve cleanly to `/api/v1/admin/users` without 404 or path duplication.
 */
function normalizeAdminPath(path: string[]): string[] {
  if (path.length > 0 && path[0] === "admin") {
    return path;
  }
  return ["admin", ...path];
}

export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const path = normalizeAdminPath((await ctx.params).path);
  return forward(req, path, "GET");
}

export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const path = normalizeAdminPath((await ctx.params).path);
  return forward(req, path, "POST");
}

export async function PUT(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const path = normalizeAdminPath((await ctx.params).path);
  return forward(req, path, "PUT");
}

export async function PATCH(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const path = normalizeAdminPath((await ctx.params).path);
  return forward(req, path, "PATCH");
}

export async function DELETE(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  const path = normalizeAdminPath((await ctx.params).path);
  return forward(req, path, "DELETE");
}
