"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Image from "next/image";
import { Alert, Card, CardBody, CardFooter } from "@/components/console/ui";
import { Button, Field, inputClass } from "@/components/console/interactive";

export function AdminNoticeForm() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(null);
  
  const [noticeImage, setNoticeImage] = useState<string | null>(null);
  const [imageName, setImageName] = useState<string>("");

  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      setToast({ m: "Maximum image size is 10MB.", t: "bad" });
      return;
    }

    setImageName(file.name);
    const reader = new FileReader();
    reader.onload = (ev) => {
      const dataUrl = ev.target?.result as string;
      setNoticeImage(dataUrl);
    };
    reader.readAsDataURL(file);
  };

  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setBusy(true);
    const form = e.currentTarget;
    const f = Object.fromEntries(new FormData(form).entries()) as Record<string, FormDataEntryValue>;

    try {
      const res = await fetch("/api/notices/submit-rfp", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          title: f.title,
          company_name: f.company_name,
          category: f.category,
          district: f.district,
          closing_date: f.closing_date,
          published_date: f.published_date,
          description: f.description,
          contact_email: f.contact_email,
          contact_phone: f.contact_phone,
          source: f.source,
          ref_no: f.ref_no,
          notice_image: noticeImage || undefined,
        }),
      });

      const json = await res.json();
      setBusy(false);

      if (!res.ok) {
        setToast({ m: json.detail ?? "Failed to publish notice.", t: "bad" });
        return;
      }

      setToast({ m: "Notice published successfully!", t: "ok" });
      form.reset();
      setNoticeImage(null);
      setImageName("");
      router.refresh();
    } catch {
      setBusy(false);
      setToast({ m: "An error occurred.", t: "bad" });
    }
  };

  return (
    <Card>
      <form onSubmit={submit}>
        <CardBody className="space-y-4">
          {toast && (
            <Alert
              tone={toast.t === "ok" ? "success" : "error"}
              title={toast.m}
              actions={<Button variant="ghost" size="xs" onClick={() => setToast(null)}>Dismiss</Button>}
            />
          )}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Notice Title *" htmlFor="nf-title">
                <input id="nf-title" name="title" required className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Company / Authority Name *" htmlFor="nf-company_name">
                <input id="nf-company_name" name="company_name" required className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Category" htmlFor="nf-category">
                <input id="nf-category" name="category" placeholder="e.g. IT, Construction, Transport" className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="District" htmlFor="nf-district">
                <input id="nf-district" name="district" placeholder="e.g. Colombo, Kandy" className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Reference Number" htmlFor="nf-ref_no">
                <input id="nf-ref_no" name="ref_no" placeholder="Leave empty to auto-generate" className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Published Date *" htmlFor="nf-published_date">
                <input id="nf-published_date" name="published_date" type="date" required defaultValue={new Date().toISOString().split('T')[0]} className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Closing Date *" htmlFor="nf-closing_date">
                <input id="nf-closing_date" name="closing_date" type="date" required className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Contact Email *" htmlFor="nf-contact_email">
                <input id="nf-contact_email" name="contact_email" type="email" required className={inputClass} />
              </Field>
            </div>
            <div>
              <Field label="Contact Phone *" htmlFor="nf-contact_phone">
                <input id="nf-contact_phone" name="contact_phone" required className={inputClass} />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Source (e.g. Newspaper Name)" htmlFor="nf-source">
                <input id="nf-source" name="source" defaultValue="Manual Admin Entry" className={inputClass} />
              </Field>
            </div>
            <div className="sm:col-span-2">
              <Field label="Description / Summary" htmlFor="nf-description">
                <textarea id="nf-description" name="description" rows={3} className={inputClass} />
              </Field>
            </div>

            <div className="mt-2 border-t border-gray-200 pt-4 sm:col-span-2">
              <span className="block text-sm font-semibold text-gray-900">Attach Notice Image (Paper Ad)</span>
              <p className="mb-3 mt-1 text-xs text-gray-500">Upload a scanned copy of the paper ad or notice image. Only JPG, PNG, WEBP, and PDF up to 10MB.</p>
              <input type="file" accept="image/png,image/jpeg,image/webp,application/pdf" onChange={handleImageChange} className="block w-full cursor-pointer text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-200" />
              {noticeImage && (
                <div className="mt-3 flex flex-col gap-2">
                  <div className="flex items-center justify-between gap-2 rounded-md border border-gray-200 bg-gray-50 p-2">
                    <span className="min-w-0 truncate text-sm font-medium text-gray-700">{imageName}</span>
                    <Button variant="ghost" size="xs" className="text-red-600 hover:text-red-700" onClick={() => { setNoticeImage(null); setImageName(""); }}>Remove</Button>
                  </div>
                  {noticeImage.startsWith("data:image") && (
                    <Image src={noticeImage} width={800} height={600} unoptimized alt="Preview" className="max-h-48 w-auto self-start rounded-md border border-gray-200 object-contain" />
                  )}
                </div>
              )}
            </div>
          </div>
        </CardBody>
        <CardFooter className="flex justify-end">
          <Button type="submit" busy={busy}>
            {busy ? "Publishing..." : "Publish Notice"}
          </Button>
        </CardFooter>
      </form>
    </Card>
  );
}
