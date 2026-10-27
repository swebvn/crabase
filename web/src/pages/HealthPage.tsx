import { useEffect, useState } from "react";
import type { Request } from "../types";
import { ErrorNotice, Button } from "../components/ui";

type Health = { checked_at: string; checks: { name: string; status: "ok" | "warning" | "error" | "idle"; detail: string }[] };

export function HealthPage({ request }: { request: Request }) {
  const [health, setHealth] = useState<Health>();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  async function load() {
    setLoading(true); setError(""); setHealth(undefined);
    try { setHealth(await request<Health>("health")); }
    catch (error) { setError((error as Error).message); }
    finally { setLoading(false); }
  }
  useEffect(() => { void load(); }, [request]);
  return <>
    <div className="settings-users-heading"><h2>Health</h2><Button variant="secondary" loading={loading} onClick={() => void load()}>Refresh</Button></div>
    <ErrorNotice message={error} />
    {loading && <p role="status">Checking health…</p>}
    {health && <><ul className="settings-health">
      {health.checks.map(check => <li key={check.name}>
        <div><strong>{check.name}</strong><small>{check.detail}</small></div>
        <span data-status={check.status}>{{ ok: "OK", warning: "Check", error: "Error", idle: "Idle" }[check.status]}</span>
      </li>)}
    </ul><p className="muted">Checked {new Date(health.checked_at).toLocaleTimeString()}. Read-only checks; no test jobs are run.</p></>}
  </>;
}
