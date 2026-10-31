import { PageHeading } from "@/components/console/ui";
import { BenefitsMarketingClient } from "@/components/portal/benefits-marketing-client";

export const metadata = { title: "Campaign | TenderHub Console" };

export default function CampaignPage() {
  return <>
    <PageHeading title="Campaign" description="Create and review benefit posters. Approved campaigns can publish through the existing social connections." />
    <BenefitsMarketingClient />
  </>;
}
