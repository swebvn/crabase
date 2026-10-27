import { useEffect, useState } from 'react';
import type { Account, Project, Request } from '../types';
import { Dialog, ErrorNotice, Input, Button } from './ui';

export function ProjectSharingDialog({ project, request, close }: { project: Project; request: Request; close: () => void }) {
  const [visibility, setVisibility] = useState('private');
  const [members, setMembers] = useState<string[]>([]);
  const [users, setUsers] = useState<Account[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [ready, setReady] = useState(false);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let stale = false;
    setLoading(true); setReady(false); setError('');
    Promise.all([request<{ visibility: string; members: string[] }>('projectSharing', { project_id: project.id }), request<{ users: Account[] }>('usersList')])
      .then(([sharing, accounts]) => {
        if (stale) return;
        setVisibility(sharing.visibility); setMembers(sharing.members.filter(id => accounts.users.some(user => user.id === id && user.enabled)));
        setUsers(accounts.users.filter(user => user.enabled && !user.admin)); setReady(true);
      }).catch(error => { if (!stale) setError(error.message); })
      .finally(() => { if (!stale) setLoading(false); });
    return () => { stale = true; };
  }, [project.id, request, retry]);
  return <Dialog className="project-sharing-dialog" title={`Sharing · ${project.name}`} close={close} dismissible={!busy}>
    <form className="account-form" onSubmit={async event => {
      event.preventDefault(); if (!ready || busy) return;
      setBusy(true); setError('');
      try { await request('projectSharingSave', { project_id: project.id, visibility, members }); close(); }
      catch (error) { setError((error as Error).message); }
      finally { setBusy(false); }
    }}>
      <ErrorNotice message={error} />
      {loading && <p role="status">Loading sharing settings…</p>}
      {!loading && !ready && <Button onClick={() => setRetry(value => value + 1)}>Retry</Button>}
      {ready && <>
        <fieldset className="project-sharing-visibility" disabled={busy}>
          <legend>Visibility</legend>
          <label className="check-label"><Input type="radio" name="visibility" value="private" checked={visibility === 'private'} onChange={() => setVisibility('private')} />Private</label>
          <label className="check-label"><Input type="radio" name="visibility" value="public" checked={visibility === 'public'} onChange={() => setVisibility('public')} />Public · all signed-in users</label>
        </fieldset>
        <p className="muted">Admins always have access. Chats, files and worktrees inherit these settings.</p>
        {visibility === 'private' && <div className="project-sharing-users" role="group" aria-label="Share with users">
          {users.map(user => <label className="check-label" key={user.id}>
            <Input type="checkbox" disabled={busy} checked={members.includes(user.id)} onChange={event => setMembers(current => event.target.checked ? [...current, user.id] : current.filter(id => id !== user.id))} />
            <span>{user.name}<small className="muted">{user.email}</small></span>
          </label>)}
          {!users.length && <p className="muted">No enabled members to share with.</p>}
        </div>}
      </>}
      <div className="dialog-actions"><Button type="button" variant="secondary" disabled={busy} onClick={close}>Cancel</Button>
        <Button type="submit" variant="primary" loading={busy} disabled={!ready}>{busy ? 'Saving…' : 'Save'}</Button></div>
    </form>
  </Dialog>;
}
