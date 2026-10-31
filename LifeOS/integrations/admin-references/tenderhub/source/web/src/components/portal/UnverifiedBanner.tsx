"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertTriangle, CheckCircle2 } from "lucide-react";
import { useToast } from "@/components/ui/Toaster";

export function UnverifiedBanner({ email }: { email?: string }) {
  const router = useRouter();
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const toast = useToast();

  async function handleResend() {
    if (!email) {
      router.push("/verify-email");
      return;
    }

    setSending(true);
    try {
      const res = await fetch("/api/auth/resend-verification", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: email.toLowerCase().trim() }),
      });
      const json = await res.json().catch(() => null);
      setSending(false);

      if (!res.ok) {
        toast.error("Failed", json?.detail ?? "Could not send verification email.");
        return;
      }

      setSent(true);
      toast.success("Verification Link Sent", `A verification link has been dispatched to ${email}.`);
    } catch {
      setSending(false);
      toast.error("Error", "Could not dispatch verification email. Try again shortly.");
    }
  }

  return (
    <div className="border-b border-yellow-200 bg-yellow-50 px-4 py-3 sm:px-6">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2">
        <div className="flex min-w-0 items-start gap-3">
          <AlertTriangle aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-yellow-500" />
          <p className="text-sm text-yellow-800">
            <strong className="font-semibold">Your email address is not verified yet.</strong> Official document downloads and tender alerts remain locked until verified.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3 pl-8 sm:pl-0">
          {sent ? (
            <span className="inline-flex items-center gap-1.5 text-sm font-medium text-green-700">
              <CheckCircle2 aria-hidden="true" className="size-4" /> Check your inbox — Click link to verify!
            </span>
          ) : (
            <button
              type="button"
              disabled={sending}
              onClick={handleResend}
              className="text-sm font-semibold text-yellow-800 underline hover:text-yellow-900 disabled:opacity-50"
            >
              {sending ? "Sending Link…" : "Resend Verification Link"}
            </button>
          )}
          <Link
            href="/verify-email"
            className="rounded-md bg-yellow-100 px-2.5 py-1.5 text-sm font-semibold text-yellow-900 hover:bg-yellow-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-yellow-600"
          >
            Verify Email <span aria-hidden="true">&rarr;</span>
          </Link>
        </div>
      </div>
    </div>
  );
}
