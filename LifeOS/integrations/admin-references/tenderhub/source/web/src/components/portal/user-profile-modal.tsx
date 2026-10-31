"use client";

import Link from "next/link";
import type React from "react";
import { Modal } from "@/components/console/interactive";
import { Badge, DescriptionList, buttonClass } from "@/components/console/ui";
import { SignOutButton } from "@/components/portal/sign-out";
import { Settings, ExternalLink } from "lucide-react";

export function UserProfileModal({
  open,
  onClose,
  userName,
  orgName,
  userEmail,
  plan,
  isStaff,
}: {
  open: boolean;
  onClose: () => void;
  userName: string;
  orgName: string;
  userEmail?: string;
  plan?: string;
  isStaff?: boolean;
}) {
  const profileLink = isStaff ? "/console" : plan === "publish" ? "/workspace/profile" : "/app/profile";

  return (
    <Modal open={open} onClose={onClose} title="My Profile" size="sm">
      <div className="space-y-6">
        <DescriptionList
          items={[
            ["Name", userName],
            ...(userEmail ? ([["Email", userEmail]] as [string, string][]) : []),
            ["Organisation", orgName],
            ...(plan || isStaff
              ? ([[
                  "Access",
                  <span key="access" className="flex flex-wrap gap-2">
                    {plan ? <Badge tone="gray">{plan}</Badge> : null}
                    {isStaff ? <Badge tone="purple">Staff Administrator</Badge> : null}
                  </span>,
                ]] as [string, React.ReactNode][])
              : []),
          ]}
        />

        {!isStaff && (
          <Link href={profileLink} onClick={onClose} className={buttonClass("secondary", "md", "w-full justify-between")}>
            <span className="flex items-center gap-2">
              <Settings aria-hidden="true" className="size-4 text-gray-400" />
              Edit Profile & Alert Categories
            </span>
            <ExternalLink aria-hidden="true" className="size-4 text-gray-400" />
          </Link>
        )}

        <div className="flex justify-end border-t border-gray-200 pt-4">
          <SignOutButton />
        </div>
      </div>
    </Modal>
  );
}
