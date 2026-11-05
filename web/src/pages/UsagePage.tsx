import { useEffect, useState } from "react";
import { CircleAlert, CircleCheck, CircleMinus, RefreshCw } from "lucide-react";
import type { Request } from "../types";
import { ErrorNotice, Button } from "../components/ui";
import { Avatar } from "../components/Avatar";
import { time } from "../lib/format";

type UsageRow = { id: string | null; name: string; email: string; enabled: boolean; avatar_url: string; avatar_fallback: string; tokens: number; calls: number; last_used: string | null };
type Usage = { days: number; total: number; users: UsageRow[]; relay: { enabled: boolean; pending: number; failed: number } };
const periods = [{ days: 1, label: "24 hours" }, { days: 7, label: "7 days" }, { days: 30, label: "30 days" }, { days: 0, label: "All time" }];
const tokens = new Intl.NumberFormat();

export function UsagePage({ request }: { request: Request }) {
  const [days, setDays] = useState(30);
  const [usage, setUsage] = useState<Usage>();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [version, setVersion] = useState(0);
  useEffect(() => {
    let stale = false;
    setLoading(true); setError("");
    request<Usage>("usageReport", { days })
      .then(result => { if (!stale) setUsage(result); })
      .catch(error => { if (!stale) setError(error.message); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [request, days, version]);
  const used = usage?.users.filter(row => row.tokens > 0) ?? [];
  const idle = (usage?.users.length ?? 0) - used.length;
  const relay = usage?.relay;
  return <section className="settings-section" aria-labelledby="settings-usage-title">
    <header className="settings-section-header">
      <div><h2 id="settings-usage-title">Usage</h2><p>Fresh AI tokens credited to the person who drove each turn. Cached re-reads of chat history are excluded.</p></div>
      <Button loading={loading} onClick={() => setVersion(value => value + 1)}><RefreshCw size={16} className={loading ? "spin" : ""} aria-hidden="true" />{error ? "Retry" : "Refresh"}</Button>
    </header>
    <div className="settings-toolbar">
      <div className="settings-segmented" role="group" aria-label="Usage period">
        {periods.map(period => <Button key={period.days} variant="text" aria-pressed={days === period.days} onClick={() => setDays(period.days)}>{period.label}</Button>)}
      </div>
      {usage && <span className="settings-hint" role="status">{tokens.format(usage.total)} tokens</span>}
    </div>
    <ErrorNotice message={error} />
    {loading && !usage && <p className="settings-empty" role="status">Loading usage…</p>}
    {usage && <>
      {!!used.length && <table className="settings-table settings-directory settings-usage" aria-label="Token usage by user" aria-busy={loading}>
        <thead><tr><th scope="col">User</th><th scope="col">Tokens</th><th scope="col">Last used</th></tr></thead>
        <tbody>{used.map(row => <tr key={row.email}>
          <td className="settings-table-identity"><div className="settings-person">
            <Avatar user={row.name} avatars={{ [row.name]: row.avatar_url || row.avatar_fallback }} />
            <div className="settings-row-copy"><strong>{row.name}</strong><small>{row.id ? row.email : "No longer a user"}</small></div>
          </div></td>
          <td className="settings-table-meta"><span>{tokens.format(row.tokens)}</span><small>{usage.total ? Math.round(row.tokens / usage.total * 100) : 0}% · {row.calls} {row.calls === 1 ? "call" : "calls"}</small></td>
          <td className="settings-table-meta"><span title={row.last_used ? new Date(row.last_used).toLocaleString() : undefined}>{row.last_used ? time(row.last_used) : "—"}</span></td>
        </tr>)}</tbody>
      </table>}
      {!used.length && <p className="settings-empty" role="status">No token usage in this period.</p>}
      {idle > 0 && !!used.length && <p className="settings-hint">{idle} {idle === 1 ? "user has" : "users have"} no usage in this period.</p>}
      {relay && <p className="settings-hint settings-status" data-status={!relay.enabled ? "idle" : relay.failed ? "error" : "ok"}>
        {!relay.enabled ? <CircleMinus size={14} aria-hidden="true" /> : relay.failed ? <CircleAlert size={14} aria-hidden="true" /> : <CircleCheck size={14} aria-hidden="true" />}
        {!relay.enabled ? "Console relay is off. Usage is kept here only."
          : relay.failed ? `${relay.failed} ${relay.failed === 1 ? "report" : "reports"} could not be sent to Console${relay.pending ? `; ${relay.pending} waiting` : ""}.`
          : relay.pending ? `Sending ${relay.pending} ${relay.pending === 1 ? "report" : "reports"} to Console.` : "All usage is sent to Console."}
      </p>}
    </>}
  </section>;
}
