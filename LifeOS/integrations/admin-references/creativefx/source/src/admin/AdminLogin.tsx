import React, { useState, useEffect } from 'react';
import { Lock, Eye, EyeOff } from 'lucide-react';
import { API_BASE } from '../context/ContentContext';

interface AdminLoginProps {
  onLogin: () => void;
  onClose: () => void;
}

export const AdminLogin: React.FC<AdminLoginProps> = ({ onLogin, onClose }) => {
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    document.body.classList.add('admin-active');
    const hideChat = () => {
      document.querySelectorAll(
        '#worksuite-chat-widget, iframe[src*="worksuite"], [id*="worksuite"], [class*="worksuite"], div[class*="chat-widget"], .floating-chat-btn, .fc-widget-normal, #fc_frame'
      ).forEach(el => {
        (el as HTMLElement).style.setProperty('display', 'none', 'important');
        (el as HTMLElement).style.setProperty('visibility', 'hidden', 'important');
        (el as HTMLElement).style.setProperty('opacity', '0', 'important');
        (el as HTMLElement).style.setProperty('pointer-events', 'none', 'important');
        (el as HTMLElement).style.setProperty('z-index', '-9999', 'important');
      });
    };
    hideChat();
    const interval = setInterval(hideChat, 600);
    return () => {
      clearInterval(interval);
      document.body.classList.remove('admin-active');
    };
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    const cleanPassword = password.trim();
    try {
      const res = await fetch(`${API_BASE}/api/admin/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: cleanPassword }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data && data.token) {
        sessionStorage.setItem('cfx_admin_token', data.token);
        onLogin();
        return;
      }
      if (res.status === 429) {
        setError('Too many attempts. Please wait a moment.');
      } else {
        setError('Incorrect password. Try again.');
      }
    } catch {
      setError('Could not reach the server. Please try again in a moment.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-[200] bg-gray-900/60 flex items-center justify-center p-6">
      <div className="cfx-admin w-full max-w-sm bg-white rounded-xl shadow-xl p-8">
        {/* Logo */}
        <div className="text-center space-y-3 mb-8">
          <div className="w-12 h-12 bg-gray-900 rounded-lg flex items-center justify-center mx-auto">
            <Lock className="w-5 h-5 text-white" />
          </div>
          <h1 className="text-lg font-semibold text-gray-900">CreativeFX Admin</h1>
          <p className="text-xs text-gray-500">Sign in to manage website content</p>
        </div>

        {/* Form */}
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="relative">
            <input
              type={showPassword ? 'text' : 'password'}
              placeholder="Admin password"
              value={password}
              onChange={e => setPassword(e.target.value)}
              autoFocus
              className="w-full bg-white border border-gray-300 rounded-md px-4 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-900 transition-shadow pr-10"
            />
            <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700">
              {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
          </div>

          {error && <p className="text-red-600 text-xs text-center">{error}</p>}

          <button
            type="submit"
            disabled={loading || !password}
            className="w-full bg-gray-900 text-white text-sm font-semibold py-2.5 rounded-md hover:bg-gray-700 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {loading ? 'Verifying…' : 'Sign in'}
          </button>
        </form>

        <button onClick={onClose} className="w-full text-center text-xs text-gray-400 hover:text-gray-600 mt-6 transition-colors">
          Cancel
        </button>
      </div>
    </div>
  );
};
