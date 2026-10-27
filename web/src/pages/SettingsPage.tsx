import { useEffect, useState } from "react";
import { Check, Moon, Sun, TriangleAlert } from "lucide-react";
import type { Account, Project, Request } from "../types";
import { ErrorNotice, Button, Input } from "../components/ui";
import { useAuth } from "../components/AuthGate";
import { useAttachments } from '../hooks/useAttachments';
import { AttachmentList } from '../components/AttachmentList';
import { HealthPage } from './HealthPage';
import { ProjectSharingDialog } from '../components/ProjectSharingDialog';

export function SettingsPage({ theme, setTheme, request, projects, loaded, back }: {
  theme: string; setTheme: (theme: string) => void; request: Request; projects: Project[]; loaded: boolean; back: () => void;
}) {
  const { user, refresh, logout } = useAuth();
  const [page, setPage] = useState('profile');
  const [error, setError] = useState('');
  const [sharingProject, setSharingProject] = useState<Project | null>(null);
  return <div className="settings-page">{!user.avatar_required && <Button variant="text" onClick={back}>← Back to workspace</Button>}<h1>Settings</h1>
    <div className="settings-layout">
      <nav className="settings-nav" aria-label="Settings">
        <button aria-current={page === 'profile' ? 'page' : undefined} onClick={() => setPage('profile')}>Profile</button>
        <button disabled={user.avatar_required} aria-current={page === 'appearance' ? 'page' : undefined} onClick={() => setPage('appearance')}>Appearance</button>
        {!!user.admin && !user.avatar_required && <><span className="settings-group">Admin</span>
          <button aria-current={page === 'users' ? 'page' : undefined} onClick={() => setPage('users')}>Users</button>
          <button aria-current={page === 'projects' ? 'page' : undefined} onClick={() => setPage('projects')}>Projects</button>
          <button aria-current={page === 'health' ? 'page' : undefined} onClick={() => setPage('health')}>Health</button></>}
        <button className="settings-logout" onClick={() => void logout().catch(error => setError(error.message))}>Sign out</button>
      </nav>
      <section className="settings-content">
        <ErrorNotice message={error} />
        {(page === 'profile' || user.avatar_required) && <><AvatarUpload request={request} /> <AccountForm account={user} save={async data => {
          await request('profileSave', data); await refresh();
        }} /></>}
        {page === 'appearance' && !user.avatar_required && <><h2>Appearance</h2><div className="theme-options">
          {['light','dark'].map(value => <button key={value} aria-pressed={theme === value} onClick={() => setTheme(value)}>
            {value === 'light' ? <Sun size={18} /> : <Moon size={18} />}{value === 'light' ? 'Light' : 'Dark'}
            {theme === value && <Check size={16} />}
          </button>)}
        </div></>}
        {page === 'users' && !!user.admin && !user.avatar_required && <UserManagement request={request} />}
        {page === 'projects' && !!user.admin && !user.avatar_required && <>
          <div className="settings-users-heading"><h2>Projects</h2></div>
          <p className="muted">Select a project to manage visibility and shared users. Worktrees inherit access.</p>
          {!loaded ? <p role="status">Loading projects…</p> : <ul className="settings-users">
            {projects.filter(project => !project.parent_id).map(project => <li key={project.id}>
              <button aria-label={`Sharing for ${project.name}`} onClick={() => setSharingProject(project)}>
                <span><strong>{project.name}</strong>{!!project.archived && <small>Archived</small>}</span>
                <small>{project.visibility === 'public' ? 'Public' : 'Private'}</small>
              </button>
            </li>)}
            {!projects.length && <li className="muted">No projects yet.</li>}
          </ul>}
        </>}
        {page === 'health' && !!user.admin && !user.avatar_required && <HealthPage request={request} />}
      </section>
    </div>
    {sharingProject && !!user.admin && <ProjectSharingDialog project={sharingProject} request={request} close={() => setSharingProject(null)} />}
  </div>;
}

function AvatarUpload({ request }: { request: Request }) {
  const { user, refresh } = useAuth();
  const upload = useAttachments(request, 0, user.id);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  return <section className="profile-avatar-upload" aria-label="Profile photo">
    <h2>Profile photo</h2>
    {user.avatar_required ? <div className="profile-avatar-warning" role="alert">
      <TriangleAlert size={18} aria-hidden="true" />
      <div><strong>Profile photo required</strong><p>Upload and save a photo to enter the workspace. We couldn’t find an avatar or Gravatar for your account.</p></div>
    </div> :
      <img className="profile-avatar-preview" src={user.avatar_url || user.avatar_fallback} alt="Your profile photo" />}
    <label>Choose image<Input type="file" accept="image/png,image/jpeg,image/gif,image/webp" disabled={busy} onChange={event => {
      const file = event.target.files?.[0]; if (file) { upload.clear(); upload.add([file]); setError(''); } event.target.value = '';
    }} /></label>
    <AttachmentList files={upload.files} remove={upload.remove} retry={upload.retry} disabled={busy} />
    <ErrorNotice message={error || upload.error} />
    <Button variant="secondary" loading={busy} disabled={!upload.files.length || !upload.ready} onClick={async () => {
      setBusy(true); setError('');
      try { await request('avatarSave', { id: upload.ids()[0] }); upload.clear(); await refresh(); }
      catch (error) { setError((error as Error).message); }
      finally { setBusy(false); }
    }}>{busy ? 'Saving…' : upload.files.length && !upload.ready ? 'Uploading…' : 'Save photo'}</Button>
  </section>;
}

function AccountForm({ account, admin = false, save, cancel }: {
  account?: Account; admin?: boolean; save: (data: Record<string, unknown>) => Promise<void>; cancel?: () => void;
}) {
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  return <form className="account-form" onChange={() => setSaved(false)} onSubmit={async event => {
    event.preventDefault();
    if (busy) return;
    const form = event.currentTarget;
    const fields = new FormData(form);
    const data: Record<string, unknown> = Object.fromEntries(fields);
    if (admin) { data.admin = fields.has('admin'); data.enabled = fields.has('enabled'); }
    if (account) data.id = account.id;
    setBusy(true); setError(''); setSaved(false);
    try {
      await save(data); setSaved(true);
      form.querySelectorAll<HTMLInputElement>('input[type="password"]').forEach(input => { input.value = ''; });
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  }}>
    <h2>{admin ? account ? 'Edit user' : 'New user' : 'Profile'}</h2>
    <label>Display name<Input name="name" defaultValue={account?.name} required maxLength={100} autoComplete="name" /></label>
    <label>Login email<Input name="email" type="email" defaultValue={account?.email} readOnly={!!account} required maxLength={254} autoComplete="username" /></label>
    {!admin && <>
      <label>Git author name <small>(optional)</small><Input name="git_name" defaultValue={account?.git_name} maxLength={100} /></label>
      <label>Git author email <small>(optional)</small><Input name="git_email" type="email" defaultValue={account?.git_email} maxLength={254} /></label>
      <p className="muted">Use your GitHub-verified or noreply email for commit credit. Leave it empty to omit co-author credit.</p>
      <label>Current password<Input name="current_password" type="password" autoComplete="current-password" /></label>
      <p className="muted">Required only when changing your password. Login email cannot be changed.</p>
    </>}
    <label>{account ? 'New password (leave blank to keep)' : 'Password'}<Input name="password" type="password"
      autoComplete="new-password" minLength={6} maxLength={72} required={!account} /></label>
    {admin && <>
      <label className="check-label"><Input name="admin" type="checkbox" defaultChecked={!!account?.admin} /> Administrator</label>
      {account && <label className="check-label"><Input name="enabled" type="checkbox" defaultChecked={!!account.enabled} /> Account enabled</label>}
    </>}
    <ErrorNotice message={error} />
    {saved && <p role="status">Saved.</p>}
    <div className="dialog-actions">
      {cancel && <Button type="button" variant="secondary" onClick={cancel}>Back</Button>}
      <Button type="submit" variant="primary" loading={busy}>{busy ? 'Saving…' : 'Save'}</Button>
    </div>
  </form>;
}

function UserManagement({ request }: { request: Request }) {
  const [users, setUsers] = useState<Account[]>([]);
  const [editing, setEditing] = useState<Account | 'new' | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  async function load() {
    setLoading(true); setError('');
    try { setUsers((await request<{ users: Account[] }>('usersList')).users); }
    catch (error) { setError((error as Error).message); }
    finally { setLoading(false); }
  }
  useEffect(() => { void load(); }, [request]);
  if (editing) return <AccountForm key={editing === 'new' ? 'new' : editing.id} admin
    account={editing === 'new' ? undefined : editing} cancel={() => setEditing(null)} save={async data => {
      await request(editing === 'new' ? 'userCreate' : 'userUpdate', data);
      setEditing(null); await load();
    }} />;
  return <><div className="settings-users-heading"><h2>Users</h2><Button variant="secondary" onClick={() => setEditing('new')}>Add user</Button></div>
    <ErrorNotice message={error} />
    {error && <Button variant="text" onClick={() => void load()}>Retry</Button>}
    {loading ? <p role="status">Loading users…</p> : <ul className="settings-users">
      {users.map(user => <li key={user.id}><button onClick={() => setEditing(user)}>
        <span><strong>{user.name}</strong><small>{user.email}</small></span>
        <small>{!user.enabled ? 'Disabled' : user.admin ? 'Admin' : 'Member'}</small>
      </button></li>)}
    </ul>}
  </>;
}
