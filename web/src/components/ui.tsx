import { createPortal } from "react-dom";
import { menuPosition } from "../lib/layout";
import {
  createContext,
  useEffect,
  useLayoutEffect,
  useId,
  useRef,
  useState,
  useContext,
  type ComponentPropsWithRef,
  type ReactNode,
  type RefObject,
} from "react";
import { Check, X } from "lucide-react";
const MenuClose = createContext(() => {});

type ButtonVariant = "primary" | "secondary" | "text";
type ButtonControlProps = ComponentPropsWithRef<"button"> & { loading?: boolean };
type ButtonStyleProps = { variant?: ButtonVariant; className?: string };

function buttonClassName(variant: ButtonVariant, className = "") {
  return `${variant === "text" ? "text-button" : `button button-${variant}`} ${className}`.trim();
}

function ButtonControl({ type = "button", loading = false, disabled, ...props }: ButtonControlProps) {
  return <button {...props} type={type} disabled={disabled || loading}
    aria-busy={loading || props["aria-busy"]} />;
}

export function Button({ variant = "secondary", className, ...props }: ButtonControlProps & ButtonStyleProps) {
  return <ButtonControl {...props} className={buttonClassName(variant, className)} />;
}

export function ButtonLink({ variant = "secondary", className, ...props }: ComponentPropsWithRef<"a"> & ButtonStyleProps) {
  return <a {...props} className={buttonClassName(variant, className)} />;
}

export function IconButton({
  label,
  className = "",
  children,
  ...props
}: ButtonControlProps & { label: string }) {
  return (
    <ButtonControl
      className={`icon-button ${className}`}
      aria-label={label}
      title={label}
      {...props}
    >
      {children}
    </ButtonControl>
  );
}

export function IconLink({ label, className = "", ...props }: ComponentPropsWithRef<"a"> & { label: string }) {
  return <a aria-label={label} title={label} {...props} className={`icon-button ${className}`.trim()} />;
}

export function Input({ className = "", ...props }: ComponentPropsWithRef<"input">) {
  return <input {...props} className={`input ${className}`.trim()} />;
}
export function Menu({
  label,
  icon,
  children,
  className = '',
  disabled = false,
  contentRole = 'menu',
  viewport = false,
}: {
  label: string;
  icon: ReactNode;
  children: ReactNode;
  className?: string;
  disabled?: boolean;
  contentRole?: 'menu' | 'dialog';
  viewport?: boolean;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const focusLast = useRef(false);
  const [open, setOpen] = useState(false);
  const contentRef = useRef<HTMLDivElement>(null);
  const [position, setPosition] = useState({ left: 0, top: 0 });
  useLayoutEffect(() => {
    if (!open) return;
    const items = contentRef.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)');
    const selected = contentRef.current?.querySelector<HTMLButtonElement>('[aria-checked="true"], [aria-pressed="true"]');
    (focusLast.current ? items?.[items.length - 1] : selected || items?.[0])?.focus();
    focusLast.current = false;
    if (!viewport) return;
    const place = () => {
      if (!ref.current || !contentRef.current) return;
      setPosition(menuPosition(ref.current.getBoundingClientRect(), contentRef.current.getBoundingClientRect(), { width: window.innerWidth, height: window.innerHeight }));
    };
    place();
    window.addEventListener("resize", place);
    window.addEventListener("scroll", place, true);
    return () => {
      window.removeEventListener("resize", place);
      window.removeEventListener("scroll", place, true);
    };
  }, [open, viewport]);
  useEffect(() => {
    const close = (event: PointerEvent) => {
      if (!ref.current?.contains(event.target as Node) && !contentRef.current?.contains(event.target as Node))
        setOpen(false);
    };
    document.addEventListener("pointerdown", close);
    return () => document.removeEventListener("pointerdown", close);
  }, []);
  const content = (
    <MenuClose.Provider value={() => { setOpen(false); trigger.current?.focus(); }}>
      <div ref={contentRef} className={`menu-content ${viewport ? "viewport-menu" : ""}`} role={contentRole} aria-label={label}
        style={viewport ? position : undefined}>
        {children}
      </div>
    </MenuClose.Provider>
  );
  return (
    <div
      ref={ref}
      className={`menu ${className}`}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget) && !contentRef.current?.contains(event.relatedTarget)) setOpen(false); }}
      onKeyDown={(event) => {
        if (event.key === "Escape" && open) {
          event.preventDefault(); event.stopPropagation(); setOpen(false); trigger.current?.focus();
        }
        if (contentRole !== "menu") return;
        if (event.key === "Tab" && open) { setOpen(false); trigger.current?.focus(); return; }
        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        if (!open) { focusLast.current = event.key === "ArrowUp" || event.key === "End"; setOpen(true); return; }
        const items = Array.from(contentRef.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') || []);
        const index = items.indexOf(document.activeElement as HTMLButtonElement);
        const next = event.key === "Home" ? 0 : event.key === "End" ? items.length - 1
          : (index + (event.key === "ArrowDown" ? 1 : -1) + items.length) % items.length;
        items[next]?.focus();
      }}
    >
      <IconButton
        ref={trigger}
        label={label}
        disabled={disabled}
        aria-haspopup={contentRole}
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        {icon}
      </IconButton>
      {open && (viewport ? createPortal(content, document.body) : content)}
    </div>
  );
}
export function MenuLabel({ children }: { children: ReactNode }) {
  return <span className="menu-label">{children}</span>;
}
export function MenuItem({
  selected,
  children,
  onClick,
}: {
  selected?: boolean;
  children: ReactNode;
  onClick: () => void;
}) {
  const close = useContext(MenuClose);
  return (
    <ButtonControl
      role={selected === undefined ? "menuitem" : "menuitemradio"}
      aria-checked={selected}
      tabIndex={-1}
      onClick={() => {
        close();
        onClick();
      }}
    >
      <span>{children}</span>
      {selected && <Check size={15} />}
    </ButtonControl>
  );
}
export function Dialog({
  title,
  close,
  children,
  className = "",
  initialFocus,
  minimal = false,
  dismissible = true,
}: {
  title: string;
  close: () => void;
  children: ReactNode;
  className?: string;
  initialFocus?: RefObject<HTMLElement | null>;
  minimal?: boolean;
  dismissible?: boolean;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  const id = useId();
  useEffect(() => {
    const opener = document.activeElement;
    const dialog = ref.current;
    dialog?.showModal();
    initialFocus?.current?.focus();
    return () => {
      dialog?.close();
      if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
    };
  }, []);
  return (
    <dialog
      ref={ref}
      className={className}
      aria-labelledby={id}
      onCancel={(event) => { event.preventDefault(); if (dismissible) close(); }}
      onClick={(event) => {
        if (dismissible && event.target === event.currentTarget) close();
      }}
    >
      <div className="dialog-inner">
        {minimal ? <h2 id={id} className="sr-only">{title}</h2> : <header className="dialog-heading">
          <h2 id={id}>{title}</h2>
          <IconButton label="Close dialog" onClick={close} disabled={!dismissible}>
            <X size={18} />
          </IconButton>
        </header>}
        {children}
      </div>
    </dialog>
  );
}
export function ErrorNotice({
  message,
  dismiss,
}: {
  message: string;
  dismiss?: () => void;
}) {
  if (!message) return null;
  return (
    <div className="error-notice" role="alert">
      <span>{message}</span>
      {dismiss && (
        <IconButton label="Dismiss error" onClick={dismiss}>
          <X size={16} />
        </IconButton>
      )}
    </div>
  );
}
