import { NextResponse } from "next/server";
import { apiFetch, apiUnavailable, token } from "./api";
import { readSession } from "./session";

/** Privileged services cannot rely on a role cached in a still-valid JWT. */
export async function requireLiveAdministrator() {
  const session = await readSession();
  if (!session || session.user.group !== "staff" || !["admin", "owner"].includes(session.user.role)) {
    return { response: NextResponse.json({ success: false, error: "Administrator access is required." }, { status: 403 }), session: null };
  }
  const result = await apiFetch("/api/v1/admin/session", { token: await token() });
  if (!result.ok) {
    return { response: result.status === 401 || result.status === 403
      ? NextResponse.json({ success: false, error: "Administrator access is no longer active." }, { status: result.status })
      : apiUnavailable(), session: null };
  }
  const actor = result.body?.data;
  if (Number(actor?.id) !== session.user.id || actor?.group !== "staff" || !["owner", "admin"].includes(actor?.role)) {
    return { response: NextResponse.json({ success: false, error: "Administrator access is required." }, { status: 403 }), session: null };
  }
  return { response: null, session: { ...session, user: { ...session.user, role: actor.role } } };
}
