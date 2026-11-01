import { useEffect, useState } from "react";
import { ArrowLeft, ChevronRight, Folder, Plus } from "lucide-react";
import type { Request } from "../types";
import { Dialog, ErrorNotice, IconButton, Input, Button } from "./ui";

type FolderListing = {
  root: string;
  path: string;
  parent: string | null;
  folders: { name: string; path: string }[];
};

export function ProjectDialog({
  request,
  added,
  close,
}: {
  request: Request;
  added: (id: string) => void;
  close: () => void;
}) {
  const [path, setPath] = useState<string>();
  const [location, setLocation] = useState("");
  const [listing, setListing] = useState<FolderListing>();
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState("");
  const [creating, setCreating] = useState(false);
  const [folderName, setFolderName] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let stale = false;
    setLoading(true);
    setListing(undefined);
    setSelected("");
    setCreating(false);
    setFolderName("");
    setQuery("");
    setError("");
    request<FolderListing>("projectFolders", path ? { path } : {})
      .then((result) => {
        if (!stale) {
          setListing(result);
          setLocation(result.path);
        }
      })
      .catch((error) => {
        if (!stale) setError(error.message);
      })
      .finally(() => {
        if (!stale) setLoading(false);
      });
    return () => {
      stale = true;
    };
  }, [path, request, retry]);
  const folders =
    listing?.folders.filter((folder) =>
      folder.name.toLowerCase().includes(query.trim().toLowerCase()),
    ) || [];
  function browse() {
    const next = location.trim();
    if (!next) {
      setError("Enter a folder path.");
      return;
    }
    setPath(next);
  }
  async function createFolder() {
    if (busy || !listing || !folderName.trim()) return;
    setBusy(true);
    setError("");
    try {
      const result = await request<{ name: string; path: string }>("projectFolderCreate", {
        path: listing.path,
        name: folderName,
      });
      setListing((current) => current && {
        ...current,
        folders: [...current.folders, result].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: "base" })),
      });
      setSelected(result.path);
      setFolderName("");
      setCreating(false);
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function openFolder() {
    if (busy || !selected) return;
    setBusy(true);
    setError("");
    try {
      const result = await request<{ id: string }>("project", {
        path: selected,
      });
      added(result.id);
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog title="Add project" close={close} dismissible={!busy}>
      <div className="folder-location">
        <IconButton
          label="Parent folder"
          disabled={busy || loading || !listing?.parent}
          onClick={() => setPath(listing?.parent || undefined)}
        >
          <ArrowLeft size={16} />
        </IconButton>
        <Input
          className="folder-path"
          aria-label="Folder path"
          value={location}
          disabled={busy || loading}
          onChange={(event) => {
            setLocation(event.target.value);
            setSelected("");
            setError("");
          }}
          onKeyDown={(event) => {
            if (event.key === "Enter" && !event.nativeEvent.isComposing) {
              event.preventDefault();
              browse();
            }
          }}
        />
        <Button disabled={busy || loading || !location.trim()} onClick={browse}>Go</Button>
        <Button disabled={busy || loading || !listing} onClick={() => { setCreating((value) => !value); setError(""); }}>
          <Plus size={16} aria-hidden="true" />New folder
        </Button>
      </div>
      {creating && <form className="folder-create" onSubmit={(event) => { event.preventDefault(); void createFolder(); }}>
        <label>
          <span>New folder name</span>
          <Input autoFocus value={folderName} maxLength={255} disabled={busy} onChange={(event) => setFolderName(event.target.value)} />
        </label>
        <div className="dialog-actions">
          <Button disabled={busy} onClick={() => { setCreating(false); setFolderName(""); }}>Cancel</Button>
          <Button type="submit" variant="primary" loading={busy} disabled={!folderName.trim()}>{busy ? "Creating…" : "Create folder"}</Button>
        </div>
      </form>}
      <label>
        <span className="sr-only">Search folders here</span>
        <Input
          autoFocus
          type="search"
          placeholder="Search folders here…"
          value={query}
          disabled={busy || loading}
          onChange={(event) => {
            setQuery(event.target.value);
            setSelected("");
          }}
        />
      </label>
      <div
        className="folder-list"
        aria-label="Workspace folders"
        aria-busy={loading}
      >
        {loading ? (
          <p className="muted" role="status">
            Loading folders…
          </p>
        ) : (
          folders.map((folder) => (
            <div className="folder-row" key={folder.path + folder.name}>
              <button
                type="button"
                className="folder-select"
                aria-pressed={selected === folder.path}
                disabled={busy}
                onClick={() => setSelected(folder.path)}
              >
                <Folder size={18} />
                <span className="truncate" title={folder.name}>
                  {folder.name}
                </span>
              </button>
              <IconButton
                label={`Browse ${folder.name}`}
                disabled={busy}
                onClick={() => setPath(folder.path)}
              >
                <ChevronRight size={16} />
              </IconButton>
            </div>
          ))
        )}
        {!loading && !folders.length && !error && (
          <p className="muted">
            {query ? "No matching folders." : "No folders here."}
          </p>
        )}
      </div>
      <ErrorNotice message={error} />
      {error && (
        <Button
          variant="secondary"
          disabled={busy}
          onClick={() => setRetry(retry + 1)}
        >
          Reload folders
        </Button>
      )}
      <div className="dialog-actions">
        <Button variant="secondary" disabled={busy} onClick={close}>
          Cancel
        </Button>
        <Button
          variant="primary"
          loading={busy}
          disabled={!selected || loading}
          onClick={() => void openFolder()}
        >
          {busy ? "Opening…" : "Open folder"}
        </Button>
      </div>
    </Dialog>
  );
}
