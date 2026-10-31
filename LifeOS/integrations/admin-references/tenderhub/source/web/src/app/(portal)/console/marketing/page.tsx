import { PageHeading } from "@/components/console/ui";
import { SocialMarketingClient } from "@/components/portal/social-marketing-client";

export const metadata = { title: "Social Marketing | TenderHub Console" };

type Search = Promise<Record<string, string | string[] | undefined>>;
const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);

export default async function MarketingConsolePage({ searchParams }: { searchParams: Search }) {
  const q = await searchParams;
  const connected = one(q.social_connected);
  const error = one(q.social_error);
  const flash = error
    ? { tone: "bad" as const, message: error.slice(0, 300) }
    : connected === "facebook" || connected === "instagram" || connected === "tiktok"
      ? { tone: "ok" as const, message: connected === "facebook" ? "Facebook login successful. Select Tenderhub.lk below and click Connect selected Page." : `${{ instagram: "Instagram", tiktok: "TikTok" }[connected]} connected.` }
      : null;

  return (
    <>
      <PageHeading
        title="Automated Social Marketing & Facebook Engine"
        description="Today's TenderHub tenders, with Facebook, Instagram and TikTok posting enabled per tender and per platform — published now or at the time you set, by the backend worker."
      />
      <SocialMarketingClient initialTab={one(q.tab)} flash={flash} />
    </>
  );
}
