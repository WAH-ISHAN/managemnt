import React, { useState } from 'react';
import { KeyRound, ShieldCheck, Check, AlertCircle, Loader, LogOut } from 'lucide-react';
import { TextInput, CollapseSection, PageHeader } from './AdminFields';
import { API_BASE } from '../../context/ContentContext';

export const AdminUsersSection: React.FC = () => {
  const [currentPw, setCurrentPw] = useState('');
  const [newPw, setNewPw] = useState('');
  const [confirmPw, setConfirmPw] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);

  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (!currentPw) return setError('Enter the current admin password');
    if (newPw.length < 6) return setError('New password must be at least 6 characters');
    if (newPw !== confirmPw) return setError('New password and confirmation do not match');

    setSaving(true);
    try {
      const res = await fetch(`${API_BASE}/api/admin/password`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '',
        },
        body: JSON.stringify({ currentPassword: currentPw, newPassword: newPw }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        // Server issues a replacement token for the new credential
        const refreshed = res.headers.get('x-refreshed-token') || data.token;
        if (refreshed) sessionStorage.setItem('cfx_admin_token', refreshed);
        setSaved(true);
        setCurrentPw('');
        setNewPw('');
        setConfirmPw('');
        setTimeout(() => setSaved(false), 4000);
      } else {
        setError(data.error === 'Unauthorized'
          ? 'Current password is incorrect'
          : data.error || 'Failed to update password');
      }
    } catch {
      setError('Could not reach the API server. Start it with "node server.cjs".');
    } finally {
      setSaving(false);
    }
  };

  const handleLogout = async () => {
    const token = sessionStorage.getItem('cfx_admin_token') || '';
    // Revoke the session server-side so the token cannot be reused after logout.
    try {
      await fetch(`${API_BASE}/api/admin/logout`, {
        method: 'POST',
        headers: { 'x-admin-token': token },
      });
    } catch {
      /* even if the request fails, still clear the local token below */
    }
    sessionStorage.removeItem('cfx_admin_token');
    window.location.href = '/';
  };

  return (
    <div className="space-y-6">
      <PageHeader
        icon={ShieldCheck}
        title="Admin access"
        description="Manage your administrator password and active session."
      />

      {/* Session status */}
      <div className="p-5 bg-white border border-gray-200 rounded-lg flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-gray-900 text-white flex items-center justify-center text-xs font-semibold">
            FX
          </div>
          <div>
            <h4 className="text-sm font-semibold text-gray-900">Administrator</h4>
            <p className="text-xs text-gray-500">Single-admin account · this browser</p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-green-700 bg-green-50 border border-green-200 px-2.5 py-1 rounded-full flex items-center gap-1.5">
            <ShieldCheck className="w-3.5 h-3.5" /> Signed in
          </span>
          <button
            type="button"
            onClick={handleLogout}
            className="flex items-center gap-1.5 text-xs font-medium text-gray-600 hover:text-red-600 border border-gray-300 hover:border-red-300 px-3 py-1.5 rounded-md transition-colors"
          >
            <LogOut className="w-3.5 h-3.5" /> Sign out
          </button>
        </div>
      </div>

      {/* Change Password Form */}
      <CollapseSection title="Change password" defaultOpen>
        <form onSubmit={handleChangePassword} className="space-y-4 max-w-xl">
          <TextInput
            label="Current password"
            type="password"
            value={currentPw}
            onChange={setCurrentPw}
            placeholder="••••••••"
          />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput
              label="New password"
              type="password"
              value={newPw}
              onChange={setNewPw}
              placeholder="At least 6 characters"
              hint="Minimum 6 characters"
            />
            <TextInput
              label="Confirm new password"
              type="password"
              value={confirmPw}
              onChange={setConfirmPw}
              placeholder="Repeat new password"
            />
          </div>

          {error && (
            <p className="text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2 flex items-center gap-1.5 w-fit">
              <AlertCircle className="w-3.5 h-3.5" /> {error}
            </p>
          )}
          {saved && (
            <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2 flex items-center gap-1.5 w-fit">
              <Check className="w-3.5 h-3.5" /> Admin password updated. Use it on your next sign-in.
            </p>
          )}

          <div className="pt-1">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 bg-gray-900 text-white text-xs font-semibold px-5 py-2.5 rounded-md hover:bg-gray-700 transition-colors disabled:opacity-40"
            >
              {saving ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <KeyRound className="w-3.5 h-3.5" />}
              {saving ? 'Updating…' : 'Update password'}
            </button>
          </div>
        </form>
      </CollapseSection>

      {/* How auth works */}
      <CollapseSection title="How authentication works">
        <ul className="text-sm text-gray-500 space-y-2 list-disc pl-4">
          <li>Sign-in happens at the admin login screen and requires the current admin password.</li>
          <li>Changing the password here updates it on the server immediately; your current session stays signed in.</li>
          <li>Sessions are kept in this browser only — closing the tab ends admin access until you sign in again.</li>
        </ul>
      </CollapseSection>
    </div>
  );
};
