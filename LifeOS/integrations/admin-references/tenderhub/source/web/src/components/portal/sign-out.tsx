"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

export function SignOutButton() {
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  return (
    <button
      disabled={loading}
      onClick={async () => {
        setLoading(true);
        await fetch("/api/auth/signout", { method: "POST" });
        router.push("/");
        router.refresh();
      }}
      className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-red-50 hover:border-red-200 hover:text-red-700 transition-all cursor-pointer shadow-2xs disabled:opacity-50"
      title="Sign out of current session"
    >
      {loading ? "Signing out…" : "Sign out"}
    </button>
  );
}
