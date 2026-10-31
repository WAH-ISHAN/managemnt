/**
 * Tailwind Plus Application UI "Sign-in forms -> Simple card" shell shared by
 * the sign-in, sign-up and account-recovery screens. Presentation only.
 */
import type { ReactNode } from "react";
import Image from "next/image";
import Link from "next/link";

export const authLabelClass = "block text-sm/6 font-medium text-gray-900";
export const authLinkClass = "font-semibold text-primary-600 hover:text-primary-500";
export const authSubmitClass =
  "flex w-full justify-center rounded-md bg-primary-600 px-3 py-1.5 text-sm/6 font-semibold text-white shadow-xs " +
  "hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 " +
  "disabled:cursor-not-allowed disabled:opacity-50";
export const authSecondaryClass =
  "flex w-full justify-center rounded-md bg-white px-3 py-1.5 text-sm/6 font-semibold text-gray-900 shadow-xs " +
  "ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600";

export function AuthCard({ title, subtitle, children, footer, wide = false }: {
  title: ReactNode;
  subtitle?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  /** Wider card for long forms (registration). */
  wide?: boolean;
}) {
  return (
    <div className="flex min-h-screen flex-col justify-center bg-gray-50 py-12 sm:px-6 lg:px-8">
      <div className="px-6 sm:mx-auto sm:w-full sm:max-w-md sm:px-0">
        <Link href="/" className="block w-fit mx-auto" aria-label="TenderHub home">
          <Image src="/logo/2.png" alt="TenderHub" width={350} height={90} priority className="mx-auto h-10 w-auto" />
        </Link>
        <h2 className="mt-6 text-center text-2xl/9 font-bold tracking-tight text-gray-900">{title}</h2>
        {subtitle ? <p className="mt-2 text-center text-sm/6 text-gray-500">{subtitle}</p> : null}
      </div>

      <div className={`mt-10 sm:mx-auto sm:w-full ${wide ? "sm:max-w-xl" : "sm:max-w-[480px]"}`}>
        <div className="bg-white px-6 py-12 shadow-sm sm:rounded-lg sm:px-12">{children}</div>
        {footer ? <div className="mt-10 space-y-2 px-6 text-center text-sm/6 text-gray-500 sm:px-0">{footer}</div> : null}
      </div>
    </div>
  );
}

export function AuthDivider({ label = "Or continue with" }: { label?: string }) {
  return (
    <div className="mt-10 flex items-center gap-x-6">
      <div className="w-full flex-1 border-t border-gray-200" />
      <p className="text-sm/6 font-medium text-nowrap text-gray-900">{label}</p>
      <div className="w-full flex-1 border-t border-gray-200" />
    </div>
  );
}
