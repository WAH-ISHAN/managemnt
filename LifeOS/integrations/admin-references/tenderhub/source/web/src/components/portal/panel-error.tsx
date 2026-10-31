"use client";

import { useRouter } from "next/navigation";
import { useTransition } from "react";
import { RefreshCw } from "lucide-react";
import { Alert } from "@/components/console/ui";
import { Button } from "@/components/console/interactive";

export function PanelError({ status }: { status: number }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const message = status === 401 ? "Your session has expired. Please sign in again."
    : status === 403 ? "You do not have permission to access this information."
    : status === 402 ? "Your subscription does not include this area."
    : "We could not load this information. Please try again.";
  return (
    <section className="py-8">
      <Alert
        tone="error"
        title="Information unavailable"
        actions={
          <Button variant="secondary" size="sm" busy={pending} disabled={pending} onClick={() => startTransition(() => router.refresh())}>
            {pending ? null : <RefreshCw className="size-4" aria-hidden="true" />} Retry
          </Button>
        }
      >
        {message}
      </Alert>
    </section>
  );
}
