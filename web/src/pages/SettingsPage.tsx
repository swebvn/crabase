import { useEffect, useId, useRef, useState } from "react";
import { Activity, ArrowLeft, Check, CircleCheck, CircleMinus, Folder, LockKeyhole, Moon, Palette, Plus, Search, Sun, TriangleAlert, UserRound, Users } from "lucide-react";
import type { Account, Project, Request } from "../types";
import { ErrorNotice, Button, IconButton, Input } from "../components/ui";
import { Avatar } from "../components/Avatar";
import { useAuth } from "../components/AuthGate";
import { useAttachments } from "../hooks/useAttachments";
import { AttachmentList } from "../components/AttachmentList";
import { HealthPage } from "./HealthPage";
import { ProjectSharingDialog } from "../components/ProjectSharingDialog";

export function SettingsPage({ theme, setTheme, request, projects, loaded, back }: {
  theme: string; setTheme: (theme: string) => void; request: Request; projects: Project[]; loaded: boolean; back: () => void;
}) {
  const { user, refresh, logout } = useAuth();
  const [page, setPage] = useState("profile");
  const [error, setError] = useState("");
  const [signingOut, setSigningOut] = useState(false);
  const currentPage = user.avatar_required ? "profile" : page;
  return <div className="settings-page">
    <header className="settings-header">
      {!user.avatar_required && <IconButton label="Back to workspace" onClick={back}><ArrowLeft size={18} /></IconButton>}
      <h1>Settings</h1>
      <Button variant="text" loading={signingOut} onClick={async () => {
        setSigningOut(true); setError("");
        try { await logout(); }
        catch (error) { setError((error as Error).message); }
        finally { setSigningOut(false); }
      }}>{signingOut ? "Signing out…" : "Sign out"}</Button>
    </header>
    <ErrorNotice message={error} dismiss={() => setError("")} />
    <div className="settings-layout">
      <nav className="settings-nav" aria-label="Settings">
        <Button variant="text" aria-current={currentPage === "profile" ? "page" : undefined} onClick={() => setPage("profile")}><UserRound size={16} aria-hidden="true" />Profile</Button>
        <Button variant="text" disabled={user.avatar_required} aria-current={currentPage === "appearance" ? "page" : undefined} onClick={() => setPage("appearance")}><Palette size={16} aria-hidden="true" />Appearance</Button>
        {!!user.admin && !user.avatar_required && <div className="settings-admin-nav" role="group" aria-label="Administration">
          <span className="settings-group">Admin</span>
          {[{ id: "users", label: "Users", Icon: Users }, { id: "projects", label: "Projects", Icon: Folder }, { id: "health", label: "Health", Icon: Activity }].map(({ id, label, Icon }) =>
            <Button key={id} variant="text" aria-current={currentPage === id ? "page" : undefined} onClick={() => setPage(id)}><Icon size={16} aria-hidden="true" />{label}</Button>)}
        </div>}
      </nav>
      <div className="settings-content">
        {currentPage === "profile" && <section className="settings-section" aria-labelledby="settings-profile-title">
          <header className="settings-section-header"><h2 id="settings-profile-title">Profile</h2></header>
          <AvatarUpload request={request} />
          <AccountForm account={user} save={async data => { await request("profileSave", data); await refresh(); }} />
        </section>}
        {currentPage === "appearance" && <section className="settings-section" aria-labelledby="settings-appearance-title">
          <header className="settings-section-header"><div><h2 id="settings-appearance-title">Appearance</h2><p>Choose a theme for this device.</p></div></header>
          <div className="theme-options" role="group" aria-label="Color theme">
            {["light", "dark"].map(value => <Button key={value} aria-pressed={theme === value} onClick={() => setTheme(value)}>
              {value === "light" ? <Sun size={18} aria-hidden="true" /> : <Moon size={18} aria-hidden="true" />}<span>{value === "light" ? "Light" : "Dark"}</span>
              <Check size={16} aria-hidden="true" className={theme === value ? "" : "theme-check-hidden"} />
            </Button>)}
          </div>
        </section>}
        {currentPage === "users" && !!user.admin && <UserManagement request={request} />}
        {currentPage === "projects" && !!user.admin && <ProjectSettings projects={projects} loaded={loaded} request={request} />}
        {currentPage === "health" && !!user.admin && <HealthPage request={request} />}
      </div>
    </div>
  </div>;
}

function AvatarUpload({ request }: { request: Request }) {
  const { user, refresh } = useAuth();
  const upload = useAttachments(request, 0, user.id);
  const picker = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);
  return <section className="profile-avatar-upload" aria-label="Profile photo">
    {user.avatar_required && <div className="profile-avatar-warning" role="alert">
      <TriangleAlert size={18} aria-hidden="true" />
      <div><strong>Profile photo required</strong><p>Upload and save a photo to enter the workspace.</p></div>
    </div>}
    <div className="profile-photo-row">
      <Avatar user={user.name} avatars={{ [user.name]: user.avatar_url || user.avatar_fallback || "" }} />
      <div className="profile-photo-controls">
        <h3>Profile photo</h3>
        <p className="settings-hint">PNG, JPG, GIF or WebP. Up to 5 MB.</p>
        <div className="settings-inline-actions">
          <Input ref={picker} type="file" hidden accept="image/png,image/jpeg,image/gif,image/webp" disabled={busy} onChange={event => {
            const file = event.target.files?.[0];
            if (file) { upload.clear(); upload.add([file]); setError(""); setSaved(false); }
            event.target.value = "";
          }} />
          <Button disabled={busy} onClick={() => picker.current?.click()}>Choose photo</Button>
          {!!upload.files.length && <Button loading={busy} disabled={!upload.ready} onClick={async () => {
            setBusy(true); setError(""); setSaved(false);
            try { await request("avatarSave", { id: upload.ids()[0] }); upload.clear(); await refresh(); setSaved(true); }
            catch (error) { setError((error as Error).message); }
            finally { setBusy(false); }
          }}>{busy ? "Saving…" : !upload.ready ? "Uploading…" : "Save photo"}</Button>}
          {saved && <span className="settings-hint" role="status">Photo saved.</span>}
        </div>
      </div>
    </div>
    <AttachmentList files={upload.files} remove={upload.remove} retry={upload.retry} disabled={busy} />
    <ErrorNotice message={error || upload.error} />
  </section>;
}

function AccountForm({ account, admin = false, save, cancel }: {
  account?: Account; admin?: boolean; save: (data: Record<string, unknown>) => Promise<void>; cancel?: () => void;
}) {
  const [error, setError] = useState("");
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const id = useId();
  return <form className="account-form settings-account-form" onChange={() => setSaved(false)} onSubmit={async event => {
    event.preventDefault();
    if (busy) return;
    const form = event.currentTarget;
    const fields = new FormData(form);
    const data: Record<string, unknown> = Object.fromEntries(fields);
    if (admin) { data.admin = fields.has("admin"); data.enabled = fields.has("enabled"); }
    if (account) data.id = account.id;
    setBusy(true); setError(""); setSaved(false);
    try {
      await save(data); setSaved(true);
      form.querySelectorAll<HTMLInputElement>('input[type="password"]').forEach(input => { input.value = ""; });
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }}>
    {admin && <header className="settings-section-header"><div><h2>{account ? "Edit user" : "New user"}</h2><p>{account ? `Manage access for ${account.name}.` : "Create an account for your workspace."}</p></div></header>}
    <fieldset className="settings-fieldset" disabled={busy}>
      <legend>Account details</legend>
      <div className="settings-form-grid">
        <label>Display name<Input name="name" defaultValue={account?.name} required maxLength={100} autoComplete="name" /></label>
        <label>Login email<Input name="email" type="email" defaultValue={account?.email} readOnly={!!account} required maxLength={254} autoComplete="username" aria-describedby={account ? `${id}-email` : undefined} /></label>
      </div>
      {account && <p className="settings-hint" id={`${id}-email`}>Login email cannot be changed.</p>}
    </fieldset>
    {!admin && <fieldset className="settings-fieldset" disabled={busy}>
      <legend>Git identity <small>Optional</small></legend>
      <div className="settings-form-grid">
        <label>Git author name<Input name="git_name" defaultValue={account?.git_name} maxLength={100} aria-describedby={`${id}-git`} /></label>
        <label>Git author email<Input name="git_email" type="email" defaultValue={account?.git_email} maxLength={254} aria-describedby={`${id}-git`} /></label>
      </div>
      <p className="settings-hint" id={`${id}-git`}>Use your GitHub-verified or noreply email for commit credit. Leave empty to omit co-author credit.</p>
    </fieldset>}
    <fieldset className="settings-fieldset" disabled={busy}>
      <legend>Password</legend>
      <div className="settings-form-grid">
        {!admin && <label>Current password<Input name="current_password" type="password" autoComplete="current-password" aria-describedby={`${id}-password`} /></label>}
        <label>{account ? "New password" : "Password"}<Input name="password" type="password" autoComplete="new-password" minLength={6} maxLength={72} required={!account} aria-describedby={`${id}-password`} /></label>
      </div>
      <p className="settings-hint" id={`${id}-password`}>{account ? `Leave blank to keep the current password.${admin ? "" : " Enter your current password only when changing it."}` : "Use at least 6 characters."}</p>
    </fieldset>
    {admin && <fieldset className="settings-fieldset" disabled={busy}>
      <legend>Access</legend>
      <label className="check-label"><Input name="admin" type="checkbox" defaultChecked={!!account?.admin} /><span>Administrator<small>Manage users, projects and workspace health.</small></span></label>
      {account && <label className="check-label"><Input name="enabled" type="checkbox" defaultChecked={!!account.enabled} /><span>Account enabled<small>Allow this user to sign in.</small></span></label>}
    </fieldset>}
    <ErrorNotice message={error} />
    <div className="settings-form-actions">
      <span className="settings-hint" role="status">{saved ? "Changes saved." : ""}</span>
      {cancel && <Button disabled={busy} onClick={cancel}>Back to users</Button>}
      <Button type="submit" variant="primary" loading={busy}>{busy ? "Saving…" : admin && !account ? "Create user" : "Save changes"}</Button>
    </div>
  </form>;
}

function UserManagement({ request }: { request: Request }) {
  const [users, setUsers] = useState<Account[]>([]);
  const [editing, setEditing] = useState<Account | "new" | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [query, setQuery] = useState("");
  const [version, setVersion] = useState(0);
  useEffect(() => {
    let stale = false;
    setLoading(true); setError("");
    request<{ users: Account[] }>("usersList")
      .then(result => { if (!stale) setUsers(result.users); })
      .catch(error => { if (!stale) setError(error.message); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [request, version]);
  if (editing) return <AccountForm key={editing === "new" ? "new" : editing.id} admin
    account={editing === "new" ? undefined : editing} cancel={() => setEditing(null)} save={async data => {
      await request(editing === "new" ? "userCreate" : "userUpdate", data);
      setEditing(null); setVersion(value => value + 1);
    }} />;
  const visible = users.filter(user => `${user.name} ${user.email}`.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()));
  return <section className="settings-section" aria-labelledby="settings-users-title">
    <header className="settings-section-header"><div><h2 id="settings-users-title">Users</h2><p>Manage accounts and workspace access.</p></div><Button variant="primary" onClick={() => setEditing("new")}><Plus size={16} aria-hidden="true" />Add user</Button></header>
    <div className="settings-toolbar">
      <div className="settings-search"><Search size={16} aria-hidden="true" /><Input type="search" aria-label="Search users" placeholder="Search by name or email…" value={query} onChange={event => setQuery(event.target.value)} /></div>
      {!loading && !error && <span className="settings-hint" role="status">{query.trim() ? `${visible.length} of ${users.length}` : users.length} {users.length === 1 ? "user" : "users"}</span>}
    </div>
    <ErrorNotice message={error} />
    {error && <Button className="settings-retry" onClick={() => setVersion(value => value + 1)}>Retry</Button>}
    {loading ? <p className="settings-empty" role="status">Loading users…</p> : <>
      {!!visible.length && <table className="settings-table settings-directory" aria-label="Users">
        <thead><tr><th scope="col">User</th><th scope="col">Role & access</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
        <tbody>{visible.map(user => <tr key={user.id}>
          <td className="settings-table-identity"><div className="settings-person">
            <Avatar user={user.name} avatars={{ [user.name]: user.avatar_url || user.avatar_fallback || "" }} />
            <div className="settings-row-copy"><strong>{user.name}</strong><small>{user.email}</small></div>
          </div></td>
          <td className="settings-table-meta"><span>{user.admin ? "Admin" : "Member"}</span><small className="settings-status" data-status={user.enabled ? "ok" : "idle"}>
            {user.enabled ? <CircleCheck size={14} aria-hidden="true" /> : <CircleMinus size={14} aria-hidden="true" />}{user.enabled ? "Active" : "Disabled"}
          </small></td>
          <td className="settings-table-action"><Button aria-label={`Edit ${user.name}`} onClick={() => setEditing(user)}>Edit</Button></td>
        </tr>)}</tbody>
      </table>}
      {!visible.length && !error && <p className="settings-empty" role="status">{query.trim() ? "No users match your search." : "No users yet."}</p>}
    </>}
  </section>;
}

function ProjectSettings({ projects, loaded, request }: { projects: Project[]; loaded: boolean; request: Request }) {
  const [query, setQuery] = useState("");
  const [sharing, setSharing] = useState<Project | null>(null);
  const roots = projects.filter(project => !project.parent_id);
  const visible = roots.filter(project => project.name.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()));
  return <section className="settings-section" aria-labelledby="settings-projects-title">
    <header className="settings-section-header"><div><h2 id="settings-projects-title">Projects</h2><p>Control who can access each project. Worktrees inherit access.</p></div></header>
    <div className="settings-toolbar">
      <div className="settings-search"><Search size={16} aria-hidden="true" /><Input type="search" aria-label="Search projects" placeholder="Search projects…" value={query} onChange={event => setQuery(event.target.value)} /></div>
      {loaded && <span className="settings-hint" role="status">{query.trim() ? `${visible.length} of ${roots.length}` : roots.length} {roots.length === 1 ? "project" : "projects"}</span>}
    </div>
    {!loaded ? <p className="settings-empty" role="status">Loading projects…</p> : <>
      {!!visible.length && <table className="settings-table settings-directory settings-projects" aria-label="Projects">
        <thead><tr><th scope="col">Project</th><th scope="col">Visibility</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
        <tbody>{visible.map(project => <tr key={project.id}>
          <td className="settings-table-identity"><div className="settings-row-copy"><strong>{project.name}</strong><small className="settings-project-path" title={project.path}>{project.path}</small>{!!project.archived && <small>Archived</small>}</div></td>
          <td className="settings-table-meta"><span className="settings-status">{project.visibility === "public" ? <Users size={14} aria-hidden="true" /> : <LockKeyhole size={14} aria-hidden="true" />}{project.visibility === "public" ? "Public" : "Private"}</span><small>{project.visibility === "public" ? "All signed-in users" : "Invited members"}</small></td>
          <td className="settings-table-action"><Button aria-label={`Sharing for ${project.name}`} onClick={() => setSharing(project)}>Sharing</Button></td>
        </tr>)}</tbody>
      </table>}
      {!visible.length && <p className="settings-empty" role="status">{query.trim() ? "No projects match your search." : "No projects yet. Add a project from the workspace."}</p>}
    </>}
    {sharing && <ProjectSharingDialog project={sharing} request={request} close={() => setSharing(null)} />}
  </section>;
}
