import { NextRequest, NextResponse } from "next/server";
import { requireLiveAdministrator } from "@/lib/admin-access";

const MARKETING_ENGINE_URL = process.env.MARKETING_ENGINE_URL || "http://127.0.0.1:4000";

export async function GET(req: NextRequest, { params }: { params: Promise<{ file: string[] }> }) {
  const access = await requireLiveAdministrator();
  if (access.response) return access.response;

  const { file } = await params;
  if ((file || []).some((part) => part === ".." || part === "." || part.includes("\\") || part.includes("\0") || part.includes("/"))) {
    return new NextResponse("Bad path", { status: 400 });
  }
  if (!file?.length || !["posters", "raw_images"].includes(file[0])) return new NextResponse("Not found", { status: 404 });
  const fileStr = file.map(encodeURIComponent).join("/");
  const targetUrl = `${MARKETING_ENGINE_URL}/storage/${fileStr}`;

  try {
    const marketingKey = process.env.MARKETING_API_KEY;
    if (!marketingKey) return new NextResponse("Marketing service authentication is not configured", { status: 503 });
    const res = await fetch(targetUrl, { cache: "no-store", signal: AbortSignal.timeout(30_000), headers: { "x-marketing-key": marketingKey } });
    if (!res.ok) {
      return new NextResponse("File not found", { status: res.status });
    }
    const contentType = res.headers.get("content-type") || "image/jpeg";
    const arrayBuffer = await res.arrayBuffer();
    return new NextResponse(arrayBuffer, {
      headers: {
        "Content-Type": contentType,
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
      },
    });
  } catch {
    return new NextResponse("Failed to fetch storage file", { status: 502 });
  }
}
