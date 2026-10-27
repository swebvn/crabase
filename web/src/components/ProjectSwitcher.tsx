import { useEffect, useState, type Dispatch, type SetStateAction } from "react";
import { Check, ChevronsUpDown, Folder, Loader2, MessageSquare, MoreHorizontal, Plus, Search } from "lucide-react";
import type { Chat, Project } from "../types";
import { sortProjects, type ProjectSort } from "../lib/projects";
import { Button, Dialog, Input, Menu, MenuItem, MenuLabel } from "./ui";

export function ProjectSwitcher({ projects, chats, pins, current, loaded, admin, select, add, sort, setSort, showArchived, setShowArchived }: {
  projects: Project[]; chats: Chat[]; pins: string[]; current?: Project; loaded: boolean; admin: boolean;
  select: (id: string) => void; add: () => void;
  sort: ProjectSort; setSort: (sort: ProjectSort) => void;
  showArchived: boolean; setShowArchived: Dispatch<SetStateAction<boolean>>;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  useEffect(() => {
    const toggle = () => { setShowArchived(value => !value); setQuery(""); setOpen(true); };
    window.addEventListener("crabase:toggle-archived", toggle);
    return () => window.removeEventListener("crabase:toggle-archived", toggle);
  }, []);
  const visible = sortProjects(projects, chats, sort).filter(project => !project.parent_id
    && (showArchived || !project.archived || project.id === current?.id)
    && `${project.name} ${project.path}`.toLocaleLowerCase().includes(query.trim().toLocaleLowerCase()));
  const choose = (id: string) => { setOpen(false); select(id); };
  return <>
    <Button variant="text" className="project-switcher" aria-label={`Switch project: ${current?.name || "Standalone chats"}`} aria-haspopup="dialog" aria-expanded={open}
      onClick={() => { setQuery(""); setOpen(true); }}>
      {current ? <Folder size={18} aria-hidden="true" /> : <MessageSquare size={18} aria-hidden="true" />}
      <span className="truncate" title={current?.name || "Standalone chats"}>{current?.name || "Standalone"}</span>
      <ChevronsUpDown size={14} aria-hidden="true" />
    </Button>
    {open && <Dialog title="Switch project" close={() => setOpen(false)} className="project-switcher-dialog">
      <div className="project-switcher-search">
        <Search size={16} aria-hidden="true" />
        <Input type="search" autoFocus aria-label="Find a project" placeholder="Find a project…" value={query} onChange={event => setQuery(event.target.value)}
          onKeyDown={event => { if (event.key === "Escape" && !event.nativeEvent.isComposing) { event.preventDefault(); setOpen(false); } }} />
      </div>
      <Button variant="text" className="project-switcher-option" aria-pressed={!current} onClick={() => choose("")}>
        <MessageSquare size={18} aria-hidden="true" /><span>Standalone chats</span>{!current && <Check size={16} aria-hidden="true" />}
      </Button>
      <div className="project-switcher-toolbar">
        <label className="check-label"><Input type="checkbox" checked={showArchived} onChange={event => setShowArchived(event.target.checked)} />Show archived</label>
        <Menu label="Sort projects" icon={<MoreHorizontal size={16} />}>
          <MenuLabel>Sort by</MenuLabel>
          {([["updated", "Latest update"], ["name", "Name"], ["created", "Default (created)"]] as const).map(([value, label]) =>
            <MenuItem key={value} selected={sort === value} onClick={() => setSort(value)}>{label}</MenuItem>)}
        </Menu>
      </div>
      <div className="project-switcher-list" aria-label="Projects" aria-busy={!loaded}>
        {!loaded && !projects.length ? <p className="muted" role="status">Loading projects…</p> : <>
          {[{ label: "Pinned", items: visible.filter(project => pins.includes(project.id)) }, { label: "Projects", items: visible.filter(project => !pins.includes(project.id)) }].map(group => !!group.items.length &&
            <section key={group.label} aria-label={group.label}>
              <h3>{group.label}</h3>
              {group.items.map(project => {
                const busy = chats.some(chat => chat.status !== "idle" && (chat.project_id === project.id || projects.some(child => child.id === chat.project_id && child.parent_id === project.id)));
                return <Button variant="text" className="project-switcher-option" key={project.id} aria-pressed={current?.id === project.id} onClick={() => choose(project.id)}>
                  <Folder size={18} aria-hidden="true" />
                  <span className="project-switcher-copy"><strong>{project.name}{!!project.archived && " · Archived"}</strong><small className="truncate" title={project.path}>{project.path}</small></span>
                  {busy && <Loader2 size={14} className="spin" aria-label="Agent working" />}
                  {current?.id === project.id && <Check size={16} aria-hidden="true" />}
                </Button>;
              })}
            </section>)}
          {!visible.length && <p className="muted" role="status">{query.trim() ? "No matching projects." : "No projects yet."}</p>}
        </>}
      </div>
      {admin && <div className="project-switcher-footer"><Button onClick={() => { setOpen(false); add(); }}><Plus size={16} aria-hidden="true" />Add project</Button></div>}
    </Dialog>}
  </>;
}
