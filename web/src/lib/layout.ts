export function clampSidebarWidth(width: number) {
  return Math.max(200, Math.min(500, width));
}

export function clampPanelWidth(width: number, min: number, max: number, viewport = window.innerWidth) {
  return Math.max(min, Math.min(max, viewport - 200, width));
}

export function updateRecord<T>(records: Record<string, T>, key: string, initial: () => T, update: (current: T) => T) {
  const current = records[key] ?? initial();
  const next = update(current);
  return next === current ? records : { ...records, [key]: next };
}

export function menuPosition(trigger: { left: number; right: number; top: number; bottom: number }, menu: { width: number; height: number }, viewport: { width: number; height: number }) {
  const left = Math.max(8, Math.min(trigger.right - menu.width, viewport.width - menu.width - 8));
  const below = trigger.bottom + 4;
  const top = below + menu.height <= viewport.height - 8 ? below : Math.max(8, trigger.top - menu.height - 4);
  return { left, top: Math.max(8, Math.min(top, viewport.height - menu.height - 8)) };
}
