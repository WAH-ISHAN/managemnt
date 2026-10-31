import { redirect } from "next/navigation";
import { readSession } from "@/lib/session";
import { AdminLoginForm } from "./admin-login-form";

export const metadata = {
  title: "Admin Console Sign In — TenderHub Sri Lanka",
  description: "Administrative console gateway for TenderHub National Procurement Intelligence Platform.",
  robots: { index: false, follow: false },
};

export default async function AdminPage() {
  const session = await readSession();
  if (session && session.user.group === "staff") {
    redirect("/console");
  }

  return <AdminLoginForm />;
}
