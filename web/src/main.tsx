import { createRoot } from "react-dom/client";
import "@fontsource-variable/inter";
import "@fontsource-variable/inter/wght-italic.css";
import "@fontsource/dm-mono/latin-400.css";
import "@fontsource/dm-mono/latin-500.css";
import "@fontsource/jetbrains-mono/latin-400.css";
import "@fontsource/jetbrains-mono/latin-500.css";
import "./styles/tokens.css";
import "./style.css";
import { App } from "./App";
import { AuthGate } from "./components/AuthGate";

createRoot(document.getElementById("root")!).render(
  <AuthGate><App /></AuthGate>,
);
