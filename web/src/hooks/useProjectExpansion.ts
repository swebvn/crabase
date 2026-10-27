import { useEffect, useState } from "react";

const storageKey = "crabase.project-expansion";

export function readProjectExpansion() {
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || "null");
    return {
      collapsed: Array.isArray(saved?.collapsed)
        ? saved.collapsed.filter((id: unknown): id is string => typeof id === "string")
        : [],
      projectsOpen: saved?.projectsOpen !== false,
    };
  } catch {
    return { collapsed: [] as string[], projectsOpen: true };
  }
}

export function useProjectExpansion() {
  const [state, setState] = useState(readProjectExpansion);
  useEffect(() => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(state));
    } catch {
      // Keep toggles usable when browser storage is unavailable.
    }
  }, [state]);
  return {
    ...state,
    toggleProjects: () => setState((current) => ({
      ...current, projectsOpen: !current.projectsOpen,
    })),
    toggleProject: (id: string) => setState((current) => ({
      ...current,
      collapsed: current.collapsed.includes(id)
        ? current.collapsed.filter((value: string) => value !== id)
        : [...current.collapsed, id],
    })),
  };
}
