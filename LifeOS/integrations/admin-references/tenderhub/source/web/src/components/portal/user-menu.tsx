"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { UserProfileModal } from "./user-profile-modal";

export function UserMenu({
  orgName,
  userName,
  plan,
  isStaff,
  userEmail,
}: {
  orgName: string;
  userName: string;
  plan?: string;
  isStaff?: boolean;
  userEmail?: string;
}) {
  const [profileOpen, setProfileOpen] = useState(false);

  const initials = (orgName || userName || "?")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? "")
    .join("");

  return (
    <>
      <button
        type="button"
        onClick={() => setProfileOpen(true)}
        className="group flex min-w-0 items-center gap-x-3 rounded-md p-1.5 text-left hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
      >
        <span className="sr-only">Open account menu</span>
        <span
          aria-hidden="true"
          className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700 ring-1 ring-primary-600/20"
        >
          {initials}
        </span>
        <span className="hidden min-w-0 lg:flex lg:flex-col">
          <span className="max-w-36 truncate text-sm/5 font-semibold text-gray-900">{orgName}</span>
          <span className="max-w-36 truncate text-xs/4 text-gray-500">
            {userName}
            {plan ? ` · ${plan}` : ""}
          </span>
        </span>
        <ChevronDown aria-hidden="true" className="hidden size-5 shrink-0 text-gray-400 group-hover:text-gray-500 lg:block" />
      </button>

      <UserProfileModal
        open={profileOpen}
        onClose={() => setProfileOpen(false)}
        userName={userName}
        orgName={orgName}
        userEmail={userEmail}
        plan={plan}
        isStaff={isStaff}
      />
    </>
  );
}
