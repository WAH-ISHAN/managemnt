/** Human labels for email lifecycle states (apps/api EmailOutbox::STATUSES). */
export const EMAIL_STATUS_LABELS: Record<string, string> = {
  queued: "Queued",
  processing: "Sending now",
  provider_accepted: "Accepted by mail server",
  delivered: "Delivered (provider confirmed)",
  bounced: "Bounced",
  complained: "Spam complaint",
  failed: "Failed",
  cancelled: "Not sent (suppressed)",
};

export const EMAIL_KIND_LABELS: Record<string, string> = {
  tender_alert: "Tender alert",
  login_digest: "Login digest",
  admin_alert: "Staff alert",
  notification: "Notification",
  auth: "Sign-in / security",
  welcome: "Welcome",
  test: "Diagnostic test",
};

export function emailStatusLabel(status: string, bounceType?: string | null): string {
  const label = EMAIL_STATUS_LABELS[status] ?? status;
  return status === "bounced" && bounceType ? `${label} (${bounceType})` : label;
}
