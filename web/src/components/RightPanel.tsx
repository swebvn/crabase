import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { Maximize2, Minimize2, X } from "lucide-react";
import { IconButton } from "./ui";
import { clampPanelWidth } from "../lib/layout";

export function RightPanel({
  children,
  className = "",
  close,
  defaultWidth,
  focusable = false,
  label,
  maxWidth,
  minWidth,
  open,
  storageKey,
  title,
  embedded = false,
}: {
  children: ReactNode | ((actions: ReactNode) => ReactNode);
  className?: string;
  close: () => void;
  defaultWidth: number;
  focusable?: boolean;
  label: string;
  maxWidth: number;
  minWidth: number;
  open: boolean;
  storageKey: string;
  title?: string;
  embedded?: boolean;
}) {
  const clamp = (value: number) => clampPanelWidth(value, minWidth, maxWidth);
  const [width, setWidth] = useState(() => clamp(Number(localStorage.getItem(storageKey)) || defaultWidth));
  const [focused, setFocused] = useState(false);
  const dragOffset = useRef(0);

  useEffect(() => localStorage.setItem(storageKey, String(width)), [storageKey, width]);
  useEffect(() => { if (!open) setFocused(false); }, [open]);

  const actions = <div className="right-panel-actions">
    {focusable && <IconButton label={focused ? "Exit code focus mode" : "Focus code workspace"}
      aria-pressed={focused} onClick={() => setFocused((value) => !value)}>
      {focused ? <Minimize2 size={16} /> : <Maximize2 size={16} />}
    </IconButton>}
    <IconButton label={`Close ${label.toLowerCase()}`} onClick={close}><X size={16} /></IconButton>
  </div>;

  const content = typeof children === "function" ? children(embedded ? null : actions) : <>
    <div className="right-panel-heading">
      {title && <h2 className="truncate">{title}</h2>}
      {actions}
    </div>
    {children}
  </>;
  if (embedded) return <div className={`right-panel-embedded ${className}`}>{content}</div>;
  return <aside
    className={`right-panel ${className} ${open ? "open" : ""} ${focused ? "focus" : ""}`}
    aria-label={label}
    aria-hidden={!open}
    inert={!open}
    style={{ "--right-panel-width": `${width}px` } as CSSProperties}
  >
    {!focused && <div
      className="right-panel-resize"
      role="separator"
      aria-label={`Resize ${label.toLowerCase()}`}
      aria-orientation="vertical"
      aria-valuemin={minWidth}
      aria-valuemax={Math.max(minWidth, Math.min(maxWidth, window.innerWidth - 200))}
      aria-valuenow={width}
      tabIndex={0}
      onPointerDown={(event) => {
        if (event.button !== 0) return;
        event.preventDefault();
        dragOffset.current = width - (window.innerWidth - event.clientX);
        event.currentTarget.setPointerCapture(event.pointerId);
      }}
      onPointerMove={(event) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId))
          setWidth(clamp(window.innerWidth - event.clientX + dragOffset.current));
      }}
      onPointerUp={(event) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId))
          event.currentTarget.releasePointerCapture(event.pointerId);
      }}
      onKeyDown={(event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        setWidth((value) => clamp(event.key === "Home" ? minWidth : event.key === "End"
          ? maxWidth : value + (event.key === "ArrowLeft" ? 16 : -16)));
      }}
    />}
    {content}
  </aside>;
}
