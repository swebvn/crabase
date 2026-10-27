import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { FileCode2, Info, Maximize2, Minimize2, PanelBottom, PanelRight, Plus, SquareTerminal, X } from "lucide-react";
import { IconButton, Menu, MenuItem, Button } from "./ui";
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
  const [viewport, setViewport] = useState(() => ({ width: window.innerWidth, height: window.innerHeight }));
  const maxWidth = Math.max(minWidth, viewport.width - 200);
  // Leave room for the header and composer when the workspace is docked below chat.
  const maxHeight = Math.max(140, viewport.height - 320);
  const [width, setWidth] = useState(() => clampPanelWidth(Number(localStorage.getItem("crabase-workspace-width")) || (active === "code" ? 760 : 320), minWidth, maxWidth));
  const [height, setHeight] = useState(() => Math.max(140, Math.min(maxHeight, Number(localStorage.getItem("crabase-terminal-height")) || 280)));
  const [focused, setFocused] = useState(false);
  const dragOffset = useRef(0);
  const resizeStart = useRef({ value: 0, pointer: 0 });
  useEffect(() => {
    const resize = () => setViewport({ width: window.innerWidth, height: window.innerHeight });
    window.addEventListener("resize", resize);
    return () => window.removeEventListener("resize", resize);
  }, []);
  useEffect(() => setWidth((value) => clampPanelWidth(value, minWidth, maxWidth)), [active, minWidth, maxWidth]);
  useEffect(() => setHeight((value) => Math.max(140, Math.min(maxHeight, value))), [maxHeight]);
  useEffect(() => { if (!open || dock !== "right") setFocused(false); }, [open, dock]);
  const setPanelWidth = (value: number) => {
    const next = clampPanelWidth(value, minWidth, maxWidth);
    setWidth(next);
    localStorage.setItem("crabase-workspace-width", String(next));
  };
  const setPanelHeight = (value: number) => {
    const next = Math.max(140, Math.min(maxHeight, value));
    setHeight(next);
    localStorage.setItem("crabase-terminal-height", String(next));
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
    <div className="workspace-resize" role="separator" aria-label="Resize workspace width" tabIndex={0}
      aria-orientation="vertical" aria-valuemin={minWidth} aria-valuemax={maxWidth} aria-valuenow={width}
      onPointerDown={(event) => { if (event.button !== 0) return; event.currentTarget.setPointerCapture(event.pointerId); dragOffset.current = width - (window.innerWidth - event.clientX); }}
      onPointerMove={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) setPanelWidth(window.innerWidth - event.clientX + dragOffset.current); }}
      onPointerUp={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId); }}
      onKeyDown={(event) => { if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return; event.preventDefault(); setPanelWidth(event.key === "Home" ? minWidth : event.key === "End" ? maxWidth : width + (event.key === "ArrowLeft" ? 16 : -16)); }} />
    {dock === "bottom" && <div className="workspace-resize workspace-resize-bottom" role="separator" aria-label="Resize workspace height" tabIndex={0}
      aria-orientation="horizontal" aria-valuemin={140} aria-valuemax={maxHeight} aria-valuenow={height}
      onPointerDown={(event) => { if (event.button !== 0) return; event.currentTarget.setPointerCapture(event.pointerId); resizeStart.current = { value: height, pointer: event.clientY }; }}
      onPointerMove={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) setPanelHeight(resizeStart.current.value + resizeStart.current.pointer - event.clientY); }}
      onPointerUp={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId); }}
      onKeyDown={(event) => { if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) return; event.preventDefault(); setPanelHeight(event.key === "Home" ? 140 : event.key === "End" ? maxHeight : height + (event.key === "ArrowUp" ? 20 : -20)); }} />}
    <header className="workspace-heading">
      <div className="workspace-tabs" role="tablist" aria-label="Workspace" onKeyDown={(event) => {
        if (!(event.target instanceof HTMLButtonElement) || event.target.getAttribute("role") !== "tab" ||
          !["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const tabs = Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]'));
        const index = tabs.indexOf(event.target);
        const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1
          : (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length;
        tabs[next]?.focus();
        tabs[next]?.click();
      }}>
        {visibleTabs.map(({ id, label, Icon }) => <span className={`workspace-tab-item ${active === id ? "active" : ""}`} key={id}>
          <button type="button" id={`workspace-tab-${id}`} className="workspace-tab" role="tab" aria-controls="workspace-content"
            tabIndex={active === id || (active === "start" && visibleTabs[0]?.id === id) ? 0 : -1}
            aria-selected={active === id} onClick={() => onTab(id)}><Icon size={15} />{label}</button>
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
    <div id="workspace-content" className="workspace-body" role={active === "start" ? undefined : "tabpanel"}
      aria-labelledby={active === "start" ? undefined : `workspace-tab-${active}`}>
      {active === "start" && <div className="workspace-start">
        <p>Choose a workspace tab</p>
        {codeAvailable && <Button variant="secondary" onClick={() => onSelectTab("code")}>Open code</Button>}
        <Button variant="secondary" onClick={onAddTerminal}>Open terminal</Button>
        <Button variant="secondary" onClick={() => onSelectTab("artifacts")}>Open artifacts</Button>
      </div>}
      {children}
    </div>
  </aside>;
}
