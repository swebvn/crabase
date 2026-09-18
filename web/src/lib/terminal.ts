import type { TerminalSession } from "../types";

export function appendTerminalOutput(session: TerminalSession, data: string): TerminalSession {
  const output = session.output + data;
  const dropped = Math.max(0, output.length - 1000000);
  return { ...session, output: output.slice(dropped), outputOffset: (session.outputOffset ?? 0) + dropped };
}

export function terminalOutputUpdate(session: TerminalSession, position: number) {
  const offset = session.outputOffset ?? 0;
  const end = offset + session.output.length;
  const reset = position < offset || position > end;
  return { reset, data: session.output.slice(reset ? 0 : position - offset), position: end };
}
