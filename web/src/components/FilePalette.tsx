import { useEffect, useId, useMemo, useRef, useState } from "react";
import { File } from "lucide-react";
import { Dialog, Input } from "./ui";
import { searchFiles } from "../lib/file-search";
import { fileSearchDirection } from "../lib/shortcuts";

export function FilePalette({ paths, loading, error, open, close }: {
  paths: readonly string[];
  loading: boolean;
  error: string;
  open: (path: string) => void;
  close: () => void;
}) {
  const [query, setQuery] = useState("");
  const [selection, setSelection] = useState(0);
  const results = useMemo(() => searchFiles(paths, query), [paths, query]);
  const active = Math.min(selection, Math.max(0, results.length - 1));
  const list = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const id = useId();
  useEffect(() => {
    list.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: "nearest" });
  }, [active, results]);
  function choose(path: string) {
    open(path);
    close();
  }
  return <Dialog title="Open file" className="file-palette" close={close} initialFocus={input} minimal>
    <div className="file-palette-search">
      <Input ref={input} role="combobox" aria-label="Search project files" aria-autocomplete="list"
        aria-expanded="true" aria-controls={id} aria-activedescendant={results.length ? `${id}-${active}` : undefined}
        placeholder="Search files by name or path…" value={query}
        onChange={event => { setQuery(event.target.value); setSelection(0); }}
        onKeyDown={event => {
          if (event.nativeEvent.isComposing) return;
          const direction = fileSearchDirection(event.nativeEvent);
          if (direction) {
            event.preventDefault();
            if (results.length) setSelection((active + direction + results.length) % results.length);
          }
          if (event.key === "Enter") {
            event.preventDefault();
            if (results[active]) choose(results[active]);
          }
        }} />
    </div>
    <div className="file-palette-results" id={id} role="listbox" aria-label="Files" ref={list}>
      {results.map((path, index) => <button key={path} id={`${id}-${index}`} role="option"
        aria-selected={index === active} tabIndex={-1} title={path} onClick={() => choose(path)}>
        <File size={16} aria-hidden="true" />
        <span className="truncate">{path.slice(path.lastIndexOf("/") + 1)}<small className="truncate">{path}</small></span>
      </button>)}
    </div>
    {!results.length && <p className="workspace-message muted" role="status">
      {loading ? "Loading files…" : error || "No matching files."}
    </p>}
  </Dialog>;
}
