import { WTerm, type WTermOptions } from "@wterm/dom";
import { GhosttyCore } from "@wterm/ghostty";
import wasmPath from "@wterm/ghostty/ghostty-vt.wasm?url";
import "@wterm/dom/css";

export async function createTerminal(element: HTMLElement, options: WTermOptions) {
  const core = await GhosttyCore.load({ wasmPath, scrollbackLimit: 5000, imageStorageLimit: 0 });
  const terminal = new WTerm(element, { ...options, core });
  try {
    await terminal.init();
    return terminal;
  } catch (error) {
    terminal.destroy();
    throw error;
  }
}

export function applyTerminalTheme(terminal: WTerm) {
  const styles = getComputedStyle(document.documentElement);
  const color = (name: string) => Number.parseInt(styles.getPropertyValue(name).trim().slice(1), 16);
  terminal.setThemeColors({
    foreground: color("--text"), background: color("--terminal-background"), cursor: color("--text"),
    palette: ["--terminal-black", "--danger", "--focus", "--terminal-yellow", "--terminal-blue", "--terminal-magenta", "--terminal-cyan", "--subtle",
      "--muted", "--danger", "--focus", "--terminal-yellow", "--terminal-blue", "--terminal-magenta", "--terminal-cyan", "--text"].map(color),
  });
}
