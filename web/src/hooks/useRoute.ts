import { useCallback, useEffect, useState } from "react";
import { parseRoute } from "../lib/routes";
export function useRoute() {
  const [route, setRoute] = useState(() => parseRoute(location.pathname));
  useEffect(() => {
    const update = () => setRoute(parseRoute(location.pathname));
    window.addEventListener("popstate", update);
    return () => window.removeEventListener("popstate", update);
  }, []);
  const navigate = useCallback((path: string, replace = false) => {
    if (location.pathname !== path) {
      if (replace) history.replaceState(null, "", path);
      else history.pushState(null, "", path);
    }
    setRoute(parseRoute(path));
  }, []);
  return { route, navigate };
}
