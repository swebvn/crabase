import { useEffect, useState } from "react";
import { ArrowLeft, ChevronRight, Folder } from "lucide-react";
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
  const [listing, setListing] = useState<FolderListing>();
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState("");
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let stale = false;
    setLoading(true);
    setListing(undefined);
    setSelected("");
    setQuery("");
    setError("");
    request<FolderListing>("projectFolders", path ? { path } : {})
      .then((result) => {
        if (!stale) setListing(result);
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
    <Dialog title="Open folder" close={close} dismissible={!busy}>
      <div className="folder-location">
        <IconButton
          label="Parent folder"
          disabled={busy || loading || !listing?.parent}
          onClick={() => setPath(listing?.parent || undefined)}
        >
          <ArrowLeft size={16} />
        </IconButton>
        <span className="truncate" title={listing?.path}>
          {listing
            ? listing.path === listing.root
              ? listing.root
              : listing.path.slice(listing.root.length + 1)
            : "Workspace"}
        </span>
      </div>
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
