import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import type { Account } from "../types";
import { ErrorNotice, ButtonLink, Button, Input } from "./ui";

const AuthContext = createContext<{ user: Account; refresh: () => Promise<void>; logout: () => Promise<void> } | null>(null);
export function useAuth() {
  const auth = useContext(AuthContext);
  if (!auth) throw new Error("Authentication required.");
  return auth;
}
export function AuthGate({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<Account | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [oauth, setOauth] = useState(false);
  const [passwordLogin, setPasswordLogin] = useState(false);
  async function refresh() {
    const response = await fetch('/auth/session', { credentials: 'same-origin' });
    if (!response.ok && response.status !== 401) throw new Error('Unable to check your session.');
    const result = await response.json();
    setOauth(!!result.oauth);
    setUser(result.user);
  }
  useEffect(() => {
    document.documentElement.dataset.theme = localStorage.getItem('crabase.theme') === 'dark' ? 'dark' : 'light';
    void refresh().catch(error => setError(error.message)).finally(() => setLoaded(true));
    const expired = () => setUser(null);
    window.addEventListener('crabase:unauthorized', expired);
    return () => window.removeEventListener('crabase:unauthorized', expired);
  }, []);
  async function logout() {
    const response = await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin' });
    if (!response.ok) throw new Error('Unable to sign out. Try again.');
    setUser(null);
    setPasswordLogin(false);
  }
  if (!loaded) return <div className="login-page" role="status">Loading…</div>;
  if (user) {
    return <AuthContext.Provider value={{ user, refresh, logout }}>{children}</AuthContext.Provider>;
  }
  return <main className="login-page"><form className="login-form" onSubmit={async event => {
    event.preventDefault();
    if (busy) return;
    const data = new FormData(event.currentTarget);
    setBusy(true); setError('');
    try {
      const response = await fetch('/auth/login', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: data.get('email'), password: data.get('password') }) });
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Unable to sign in.');
      setUser(result.user);
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }}>
    <h1>Sign in to Crabase</h1>
    {oauth && <ButtonLink variant="primary" href="/auth/oauth/start">Sign in with TDA</ButtonLink>}
    {oauth && <Button type="button" variant="text" aria-expanded={passwordLogin} aria-controls="password-login" onClick={() => { setPasswordLogin(!passwordLogin); setError(''); }}>
      {passwordLogin ? 'Hide email and password' : 'Use email and password'}
    </Button>}
    {(!oauth || passwordLogin) && <div id="password-login" className="login-form">
    <label>Email<Input name="email" type="email" autoComplete="username" required autoFocus /></label>
    <label>Password<Input name="password" type="password" autoComplete="current-password" required /></label>
    <Button type="submit" variant={oauth ? 'secondary' : 'primary'} loading={busy}>{busy ? 'Signing in…' : 'Sign in'}</Button>
    </div>}
    <ErrorNotice message={error} />
  </form></main>;
}
