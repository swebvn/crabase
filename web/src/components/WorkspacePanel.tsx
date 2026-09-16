import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { FileCode2, Info, Maximize2, Minimize2, PanelBottom, PanelRight, Plus, SquareTerminal, X } from "lucide-react";
import { IconButton, Menu, MenuItem } from "./ui";
import { clampPanelWidth } from "../lib/layout";

export type WorkspaceTab = "code" | "artifacts";
export type WorkspaceTabId = WorkspaceTab | `terminal:${string}`;

export function WorkspacePanel({ active, codeAvailable, dock, open, seenTabs, terminalTabs, onTab, onSelectTab, onAddTerminal, onRemoveTab, onClose, onDock, children }: {
  active: WorkspaceTabId | "start";
  codeAvailable: boolean;
  dock: "bottom" | "right";
  open: boolean;
  seenTabs: WorkspaceTabId[];
  terminalTabs: { id: string; title: string; running: boolean }[];
  onTab: (tab: WorkspaceTabId) => void;
  onSelectTab: (tab: WorkspaceTab) => void;
  onAddTerminal: () => void;
  onRemoveTab: (tab: WorkspaceTabId) => void;
  onClose: () => void;
  onDock: () => void;
  children: ReactNode;
}) {
  const minWidth = active === "code" ? 520 : 240;
  const maxWidth = Number.MAX_SAFE_INTEGER;
  const [width, setWidth] = useState(() => clampPanelWidth(Number(localStorage.getItem("crabase-workspace-width")) || (active === "code" ? 760 : 320), minWidth, maxWidth));
  const [height, setHeight] = useState(() => Number(localStorage.getItem("crabase-terminal-height")) || 280);
  const [focused, setFocused] = useState(false);
  const dragOffset = useRef(0);
  const resizeStart = useRef({ value: 0, pointer: 0 });
  useEffect(() => setWidth((value) => clampPanelWidth(value, minWidth, maxWidth)), [active, minWidth, maxWidth]);
  useEffect(() => { if (!open || dock !== "right") setFocused(false); }, [open, dock]);
  const setPanelWidth = (value: number) => {
    const next = clampPanelWidth(value, minWidth, maxWidth);
    setWidth(next);
    localStorage.setItem("crabase-workspace-width", String(next));
  };
  const staticTabs = [
    ["code", "Code", FileCode2],
    ["artifacts", "Artifacts", Info],
  ] as const;
  const terminalById = new Map(terminalTabs.map((tab) => [tab.id, tab]));
  const tabIds = [...seenTabs, ...terminalTabs.map((tab) => `terminal:${tab.id}` as const).filter((id) => !seenTabs.includes(id))];
  const visibleTabs = tabIds.flatMap((id) => {
    if (id.startsWith("terminal:")) {
      const terminal = terminalById.get(id.slice("terminal:".length));
      return terminal ? [{ id, label: `${terminal.title}${terminal.running ? "" : " (exited)"}`, Icon: SquareTerminal }] : [];
    }
    const tab = staticTabs.find((item) => item[0] === id);
    return tab && (tab[0] !== "code" || codeAvailable) ? [{ id, label: tab[1], Icon: tab[2] }] : [];
  });
  const availableTabs = staticTabs.filter(([id]) => !seenTabs.includes(id) && (id !== "code" || codeAvailable));
  return <aside className={`workspace-panel workspace-${dock} ${open ? "open" : ""} ${focused ? "focus" : ""}`} aria-hidden={!open} inert={!open} style={{ "--workspace-width": `${width}px`, "--workspace-height": `${height}px` } as CSSProperties}>
    <div className="workspace-resize" role="separator" aria-label="Resize workspace" tabIndex={0}
      onPointerDown={(event) => { if (event.button !== 0) return; event.currentTarget.setPointerCapture(event.pointerId); dragOffset.current = width - (window.innerWidth - event.clientX); }}
      onPointerMove={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) setPanelWidth(window.innerWidth - event.clientX + dragOffset.current); }}
      onPointerUp={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId); }}
      onKeyDown={(event) => { if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return; event.preventDefault(); setPanelWidth(event.key === "Home" ? minWidth : event.key === "End" ? maxWidth : width + (event.key === "ArrowLeft" ? 16 : -16)); }} />
    {dock === "bottom" && <div className="workspace-resize workspace-resize-bottom" role="separator" aria-label="Resize workspace" tabIndex={0}
      onPointerDown={(event) => { if (event.button !== 0) return; event.currentTarget.setPointerCapture(event.pointerId); resizeStart.current = { value: height, pointer: event.clientY }; }}
      onPointerMove={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) { const next = Math.min(Math.max(140, resizeStart.current.value + resizeStart.current.pointer - event.clientY), window.innerHeight - 180); setHeight(next); localStorage.setItem("crabase-terminal-height", String(next)); } }}
      onPointerUp={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId); }}
      onKeyDown={(event) => { if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) return; event.preventDefault(); const next = event.key === "Home" ? 140 : event.key === "End" ? window.innerHeight - 180 : Math.min(Math.max(140, height + (event.key === "ArrowUp" ? 20 : -20)), window.innerHeight - 180); setHeight(next); localStorage.setItem("crabase-terminal-height", String(next)); }} />}
    <header className="workspace-heading" role="tablist" aria-label="Workspace">
      <div className="workspace-tabs">
        {visibleTabs.map(({ id, label, Icon }) => <span className={`workspace-tab-item ${active === id ? "active" : ""}`} key={id}>
          <button className="workspace-tab" role="tab" aria-selected={active === id} onClick={() => onTab(id)}><Icon size={15} />{label}</button>
          <IconButton className="workspace-tab-close" label={`Remove ${label} tab`} onClick={() => onRemoveTab(id)}><X size={12} /></IconButton>
        </span>)}
      </div>
      <Menu label="Add workspace tab" icon={<Plus size={15} />} viewport>
        {availableTabs.map(([id, label]) => <MenuItem key={id} onClick={() => onSelectTab(id)}>{label}</MenuItem>)}
        <MenuItem onClick={onAddTerminal}>Terminal</MenuItem>
      </Menu>
      {dock === "right" && <IconButton label={focused ? "Exit workspace focus mode" : "Focus workspace"} aria-pressed={focused} onClick={() => setFocused((value) => !value)}>
        {focused ? <Minimize2 size={15} /> : <Maximize2 size={15} />}
      </IconButton>}
      <IconButton label={`Dock workspace ${dock === "bottom" ? "right" : "bottom"}`} onClick={onDock}>
        {dock === "bottom" ? <PanelRight size={15} /> : <PanelBottom size={15} />}
      </IconButton>
      <IconButton label="Close workspace" onClick={onClose}><X size={17} /></IconButton>
    </header>
    <div className="workspace-body">
      {active === "start" && <div className="workspace-start">
        <p>Choose a workspace tab</p>
        {codeAvailable && <button className="button secondary" onClick={() => onSelectTab("code")}>Open code</button>}
        <button className="button secondary" onClick={onAddTerminal}>Open terminal</button>
        <button className="button secondary" onClick={() => onSelectTab("artifacts")}>Open artifacts</button>
      </div>}
      {children}
    </div>
  </aside>;
}
