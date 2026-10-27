import { useEffect, useRef, useState } from "react";
import type { WTerm } from "@wterm/dom";
import type { Request, TerminalSession } from "../types";
import { terminalInputChunks, terminalOutputUpdate } from "../lib/terminal";
import { Button, ErrorNotice } from "./ui";

export function TerminalPanel({ terminalId, sessions, request, theme, open }: {
  terminalId: string;
  sessions: TerminalSession[];
  request: Request;
  theme: string;
  open: boolean;
}) {
  const host = useRef<HTMLDivElement>(null);
  const terminalRef = useRef<WTerm | null>(null);
  const renderedPosition = useRef(0);
  const current = sessions.find((session) => session.id === terminalId);
  const latest = useRef({ current, request, open });
  latest.current = { current, request, open };
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    if (!current || !host.current) return;
    let disposed = false;
    let terminal: WTerm | undefined;
    let inputQueue = Promise.resolve();
    setLoading(true);
    setError("");
    void import("../lib/wterm").then(async ({ createTerminal, applyTerminalTheme }) => {
      if (disposed || !host.current) return;
      // Keep the renderer's DOM separate from React's loading and error notices.
      const element = document.createElement("div");
      element.className = "terminal-emulator";
      element.setAttribute("aria-label", current.title);
      host.current.replaceChildren(element);
      terminal = await createTerminal(element, {
        renderingPaused: !latest.current.open,
        onData: (data) => {
          inputQueue = inputQueue.then(async () => {
            for (const input of terminalInputChunks(data)) {
              if (disposed) return;
              await latest.current.request("terminalInput", { terminal_id: current.id, input });
            }
          }).catch((failure: Error) => { if (!disposed) setError(failure.message); });
        },
        onResize: (cols, rows) => {
          if (disposed) return;
          const size = { cols: Math.max(20, Math.min(500, cols)), rows: Math.max(2, Math.min(200, rows)) };
          if (terminal && (cols !== size.cols || rows !== size.rows)) { terminal.resize(size.cols, size.rows); return; }
          void latest.current.request("terminalResize", { terminal_id: current.id, ...size })
            .catch((failure: Error) => { if (!disposed) setError(failure.message); });
        },
      });
      if (disposed) { terminal.destroy(); return; }
      terminalRef.current = terminal;
      applyTerminalTheme(terminal);
      const session = latest.current.current || current;
      terminal.write(session.output);
      renderedPosition.current = (session.outputOffset ?? 0) + session.output.length;
      terminal.setRenderingPaused(!latest.current.open);
      if (latest.current.open) { terminal.fit(); terminal.focus(); }
      setLoading(false);
    }).catch((failure: Error) => { if (!disposed) { setLoading(false); setError(failure.message); } });
    return () => {
      disposed = true;
      terminal?.destroy();
      if (terminalRef.current === terminal) terminalRef.current = null;
    };
  }, [current?.id, retry]);

  useEffect(() => {
    const terminal = terminalRef.current;
    if (!terminal) return;
    terminal.setRenderingPaused(!open);
    if (open) { terminal.fit(); terminal.focus(); }
  }, [open]);

  useEffect(() => {
    const terminal = terminalRef.current;
    if (terminal) void import("../lib/wterm").then(({ applyTerminalTheme }) => {
      if (terminalRef.current === terminal) applyTerminalTheme(terminal);
    });
  }, [theme]);

  useEffect(() => {
    const terminal = terminalRef.current;
    if (!current || !terminal) return;
    const update = terminalOutputUpdate(current, renderedPosition.current);
    if (update.reset) terminal.write("\x1bc");
    if (update.data) terminal.write(update.data);
    renderedPosition.current = update.position;
  }, [current?.output, current?.outputOffset]);

  return <section className={`terminal-panel ${open ? "open" : ""} terminal-embedded`}
    aria-label="Terminal" aria-hidden={!open} inert={!open}>
    {error && <div className="terminal-notice"><ErrorNotice message={error} dismiss={() => setError("")} />
      {!terminalRef.current && <Button onClick={() => setRetry(value => value + 1)}>Retry terminal</Button>}</div>}
    {loading && !error && <p className="terminal-empty" role="status">Opening terminal…</p>}
    <div className="terminal-screen" ref={host} />
  </section>;
}
