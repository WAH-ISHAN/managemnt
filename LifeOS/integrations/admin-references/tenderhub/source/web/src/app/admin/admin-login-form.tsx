"use client";

import { useState } from "react";
import Link from "next/link";
import { Captcha, captchaFetch } from "@/components/auth/Captcha";
import { useRouter } from "next/navigation";
import { useToast } from "@/components/ui/Toaster";
import { AuthCard, authLinkClass } from "@/components/auth/AuthCard";
import { Alert } from "@/components/console/ui";
import { Button, inputClass } from "@/components/console/interactive";

export function AdminLoginForm() {
  const router = useRouter();
  const toast = useToast();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    const cleanEmail = email.trim();
    if (!cleanEmail || !cleanEmail.includes("@") || !cleanEmail.includes(".")) {
      const msg = "Please enter a valid administrator email address.";
      setErrorMessage(msg);
      toast.error("Validation Error", msg);
      return;
    }

    if (!password) {
      const msg = "Please enter your administrator password.";
      setErrorMessage(msg);
      toast.error("Validation Error", msg);
      return;
    }

    setIsLoading(true);

    try {
      const res = await captchaFetch(e.currentTarget as HTMLFormElement, "/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: cleanEmail, password }),
      });

      const json = await res.json().catch(() => null);

      if (!res.ok) {
        setIsLoading(false);
        const detail =
          res.status === 429
            ? "Too many sign-in attempts. Please try again shortly."
            : json?.detail || "Invalid email or password. Please verify your credentials.";
        setErrorMessage(detail);
        toast.error("Authentication Failed", detail);
        return;
      }

      const user = json?.data?.user;
      if (!user || user.group !== "staff") {
        setIsLoading(false);
        const detail = "Access restricted: This account does not have staff administrator privileges.";
        setErrorMessage(detail);
        toast.error("Access Denied", detail);
        return;
      }

      toast.success("Administrator Access Verified", "Welcome back. Opening Admin Console…");
      router.push("/console");
    } catch {
      setIsLoading(false);
      const networkError = "Sign-in is temporarily unavailable. Please try again shortly.";
      setErrorMessage(networkError);
      toast.error("System Error", networkError);
    }
  };

  return (
    <AuthCard
      title="Sign in to the admin console"
      subtitle="Staff & system administration"
      footer={
        <p>
          Need contractor or bidder sign-in?{" "}
          <Link href="/login" className={authLinkClass}>Standard sign in</Link>
        </p>
      }
    >
      {errorMessage && (
        <div className="mb-6">
          <Alert tone="error" title={errorMessage} />
        </div>
      )}

      <form onSubmit={handleLogin} className="space-y-6">
        <div>
          <label htmlFor="admin-email" className="block text-sm/6 font-medium text-gray-900">
            Administrator email
          </label>
          <div className="mt-2">
            <input
              id="admin-email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="redacted@example.invalid"
              className={inputClass}
            />
          </div>
        </div>

        <div>
          <div className="flex items-center justify-between">
            <label htmlFor="admin-password" className="block text-sm/6 font-medium text-gray-900">
              Password
            </label>
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              className="text-sm font-semibold text-primary-600 hover:text-primary-500"
            >
              {showPassword ? "Hide" : "Show"}
            </button>
          </div>
          <div className="mt-2">
            <input
              id="admin-password"
              type={showPassword ? "text" : "password"}
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className={inputClass}
            />
          </div>
          <div className="mt-2 text-right text-sm">
            <Link href="/forgot-password" className="font-semibold text-primary-600 hover:text-primary-500">Forgot password?</Link>
          </div>
        </div>

        <Captcha action="login" />
        <Button type="submit" busy={isLoading} className="w-full">
          {isLoading ? "Verifying credentials…" : "Sign in to admin console"}
        </Button>
      </form>
    </AuthCard>
  );
}
