import { useEffect, useRef, useState } from "react";
import { nextStreamText } from "../lib/messages";

const FRAME_INTERVAL = 24;

export function useSmoothText(target: string, streaming: boolean) {
  const [visible, setVisible] = useState(target);
  const visibleRef = useRef(target);
  const targetRef = useRef(target);
  const frameRef = useRef<number | null>(null);
  const lastFrameRef = useRef(0);

  useEffect(() => {
    targetRef.current = target;
    const reducedMotion =
      typeof window !== "undefined" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!streaming || reducedMotion || typeof requestAnimationFrame === "undefined") {
      if (frameRef.current !== null) cancelAnimationFrame(frameRef.current);
      frameRef.current = null;
      visibleRef.current = target;
      setVisible((current) => (current === target ? current : target));
      return;
    }
    if (!target.startsWith(visibleRef.current)) {
      if (frameRef.current !== null) cancelAnimationFrame(frameRef.current);
      frameRef.current = null;
      visibleRef.current = target;
      setVisible(target);
      return;
    }
    if (frameRef.current !== null) return;

    const step = (now: number) => {
      frameRef.current = null;
      const currentTarget = targetRef.current;
      const currentVisible = visibleRef.current;
      if (!currentTarget.startsWith(currentVisible)) {
        visibleRef.current = currentTarget;
        setVisible(currentTarget);
        return;
      }
      if (now - lastFrameRef.current < FRAME_INTERVAL) {
        frameRef.current = requestAnimationFrame(step);
        return;
      }
      const next = nextStreamText(
        currentTarget,
        currentVisible,
        now - lastFrameRef.current,
      );
      lastFrameRef.current = now;
      if (next !== currentVisible) {
        visibleRef.current = next;
        setVisible(next);
      }
      if (next.length < currentTarget.length)
        frameRef.current = requestAnimationFrame(step);
    };

    lastFrameRef.current = performance.now();
    frameRef.current = requestAnimationFrame(step);
  }, [streaming, target]);

  useEffect(
    () => () => {
      if (frameRef.current !== null) cancelAnimationFrame(frameRef.current);
    },
    [],
  );

  return visible;
}
