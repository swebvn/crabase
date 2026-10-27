import { useRef, useState } from 'react';
import type { Project, Request } from '../types';
import { Dialog, ErrorNotice, Input, Button } from './ui';

export function WorktreeDialog({ project, request, added, close }: {
  project: Project; request: Request; added: (id: string) => void; close: () => void;
}) {
  const [branch, setBranch] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const input = useRef<HTMLInputElement>(null);
  return <Dialog title="Create worktree" close={close} dismissible={!busy} initialFocus={input}>
    <form className="account-form" onSubmit={async event => {
      event.preventDefault();
      if (busy) return;
      setBusy(true); setError('');
      try { const result = await request<{ id: string }>('worktreeCreate', { project_id: project.id, branch }); added(result.id); }
      catch (error) { setError((error as Error).message); }
      finally { setBusy(false); }
    }}>
      <p className="muted">Create a new branch from {project.name}’s current commit. Uncommitted files, .env and dependencies aren’t copied unless tracked by Git.</p>
      <label>New branch<Input ref={input} required maxLength={160} placeholder="feature/login" value={branch} disabled={busy} onChange={event => setBranch(event.target.value)} /></label>
      <ErrorNotice message={error} />
      <div className="dialog-actions">
        <Button type="button" variant="secondary" disabled={busy} onClick={close}>Cancel</Button>
        <Button type="submit" variant="primary" loading={busy} disabled={!branch.trim()}>{busy ? 'Creating…' : 'Create worktree'}</Button>
      </div>
    </form>
  </Dialog>;
}
