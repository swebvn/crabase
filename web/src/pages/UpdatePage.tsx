import { useEffect, useState } from "react";
import { Check, ChevronRight, Download, RefreshCw, TriangleAlert } from "lucide-react";
import type { Request } from "../types";
import { ErrorNotice, Button } from "../components/ui";

type Release = { tag: string; name: string; url: string; body: string; published_at: string };
type UpdateStatus = { state: "idle" | "queued" | "running" | "succeeded" | "failed"; tag?: string; message?: string; updated_at?: string; backup?: string; error?: string };
type UpdateInfo = { current: { tag: string | null; commit: string }; latest: Release; available: boolean; can_update: boolean; reason: string | null; status: UpdateStatus };

export function UpdatePage({ request }: { request: Request }) {
  const [info, setInfo] = useState<UpdateInfo>();
  const [status, setStatus] = useState<UpdateStatus>({ state: "idle" });
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [version, setVersion] = useState(0);
  const active = status.state === "queued" || status.state === "running";

  useEffect(() => {
    let stale = false;
    setLoading(true); setError("");
    request<UpdateInfo>("updateCheck")
      .then(next => { if (!stale) { setInfo(next); setStatus(next.status); } })
      .catch(error => { if (!stale) setError((error as Error).message); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [request, version]);

  useEffect(() => {
    const connected = () => setVersion(value => value + 1);
    window.addEventListener("crabase:connected", connected);
    return () => window.removeEventListener("crabase:connected", connected);
  }, []);

  useEffect(() => {
    const updated = (event: Event) => setStatus((event as CustomEvent<UpdateStatus>).detail);
    window.addEventListener("crabase:update", updated);
    return () => window.removeEventListener("crabase:update", updated);
  }, []);

  async function start() {
    if (!info?.can_update || busy) return;
    if (!window.confirm(`Update Crabase to ${info.latest.tag}? The service will restart and briefly disconnect everyone.`)) return;
    setBusy(true); setError("");
    try { setStatus(await request<UpdateStatus>("updateStart")); }
    catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }

  return <section className="settings-section" aria-labelledby="settings-updates-title">
    <header className="settings-section-header"><div><h2 id="settings-updates-title">Updates</h2><p>Install the latest stable Crabase release from GitHub.</p></div><Button loading={loading} onClick={() => setVersion(value => value + 1)}><RefreshCw size={16} className={loading ? "spin" : ""} aria-hidden="true" />Refresh</Button></header>
    <ErrorNotice message={error} />
    {loading && !info && <p className="settings-empty" role="status">Checking for releases…</p>}
    {info && <>
      <div className="settings-update-card"><div><strong>Current release</strong><span>{info.current.tag || "Development checkout"}</span></div><div><strong>Latest stable</strong><span>{info.latest.tag}</span></div><div className="settings-update-action">{info.can_update ? <Button variant="primary" loading={busy || active} onClick={() => void start()}><Download size={16} aria-hidden="true" />{active ? "Updating…" : `Update to ${info.latest.tag}`}</Button> : info.available || !info.current.tag ? <span className="settings-status" data-status="warning"><TriangleAlert size={14} aria-hidden="true" />{info.reason}</span> : <span className="settings-status" data-status="ok"><Check size={14} aria-hidden="true" />Up to date</span>}</div></div>
      {status.state !== "idle" && <div className={`settings-update-status settings-status-${status.state}`} role="status"><strong>{status.state === "succeeded" ? "Update complete" : status.state === "failed" ? "Update failed" : "Update in progress"}</strong><span>{status.message}</span>{status.backup && <small>Backup: {status.backup}</small>}</div>}
      <div className="settings-update-release"><div><strong>{info.latest.name}</strong>{info.latest.published_at && <small>Published {new Date(info.latest.published_at).toLocaleString()}</small>}</div><a href={info.latest.url} target="_blank" rel="noreferrer">View release notes</a></div>
      {info.latest.body && <details className="settings-release-toggle"><summary><ChevronRight size={12} aria-hidden="true" />Release notes</summary><pre className="settings-release-notes">{info.latest.body}</pre></details>}
    </>}
  </section>;
}
