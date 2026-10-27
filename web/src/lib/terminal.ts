import type { TerminalSession } from "../types";

// The WebSocket boundary accepts at most 16 KiB of UTF-8 per input message.
export function terminalInputChunks(input: string) {
  const chunks: string[] = [];
  let chunk = "", bytes = 0;
  for (const character of input) {
    const point = character.codePointAt(0)!;
    const size = point <= 0x7f ? 1 : point <= 0x7ff ? 2 : point <= 0xffff ? 3 : 4;
    if (bytes + size > 16384) { chunks.push(chunk); chunk = ""; bytes = 0; }
    chunk += character;
    bytes += size;
  }
  if (chunk) chunks.push(chunk);
  return chunks;
}

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
