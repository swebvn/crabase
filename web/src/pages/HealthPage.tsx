import { useEffect, useState } from "react";
import { CircleCheck, Circle, CircleAlert, RefreshCw, TriangleAlert } from "lucide-react";
import type { Request } from "../types";
import { ErrorNotice, Button } from "../components/ui";

type Health = { checked_at: string; checks: { name: string; status: "ok" | "warning" | "error" | "idle"; detail: string }[] };
const statuses = {
  ok: { label: "Healthy", Icon: CircleCheck },
  warning: { label: "Warning", Icon: TriangleAlert },
  error: { label: "Error", Icon: CircleAlert },
  idle: { label: "Idle", Icon: Circle },
};

export function HealthPage({ request }: { request: Request }) {
  const [health, setHealth] = useState<Health>();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [version, setVersion] = useState(0);
  const issues = health?.checks.filter(check => check.status === "error" || check.status === "warning").length ?? 0;
  useEffect(() => {
    let stale = false;
    setLoading(true); setError("");
    request<Health>("health")
      .then(result => { if (!stale) setHealth(result); })
      .catch(error => { if (!stale) setError(error.message); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [request, version]);
  return <section className="settings-section" aria-labelledby="settings-health-title">
    <header className="settings-section-header">
      <div><h2 id="settings-health-title">Health</h2><p>Workspace services and connection status.</p></div>
      <Button loading={loading} onClick={() => setVersion(value => value + 1)}><RefreshCw size={15} className={loading ? "spin" : ""} aria-hidden="true" />{error ? "Retry" : "Refresh"}</Button>
    </header>
    <ErrorNotice message={error} />
    {loading && !health && <p className="settings-empty" role="status">Checking health…</p>}
    {health && <>
      <div className="settings-health-summary" role="status">
        {issues ? <TriangleAlert size={18} aria-hidden="true" /> : <CircleCheck size={18} aria-hidden="true" />}
        <span>{issues ? `${issues} ${issues === 1 ? "check needs" : "checks need"} attention` : "No issues reported"}</span>
      </div>
      <table className="settings-table settings-health" aria-label="Health checks" aria-busy={loading}>
        <thead><tr><th scope="col">Service</th><th scope="col">Status</th></tr></thead>
        <tbody>{health.checks.map(check => {
          const { label, Icon } = statuses[check.status];
          return <tr key={check.name}>
            <th scope="row" className="settings-row-copy"><strong>{check.name}</strong><small>{check.detail}</small></th>
            <td><span className="settings-status" data-status={check.status}><Icon size={14} aria-hidden="true" />{label}</span></td>
          </tr>;
        })}</tbody>
      </table>
      <p className="settings-hint" role="status">{loading ? "Refreshing health checks…" : `Last checked ${new Date(health.checked_at).toLocaleTimeString()}.`}</p>
    </>}
  </section>;
}
