import test from "node:test";
import assert from "node:assert/strict";
import { searchChats } from "../src/lib/commands.ts";

const chats = [
  { id: "standalone", title: "Release checklist", project_id: null, archived: 0 },
  { id: "project", title: "Review buttons", project_id: "web", archived: 0 },
  { id: "archived", title: "Old release notes", project_id: null, archived: 1 },
];
const projects = [{ id: "web", name: "Website" }];

test("chat search finds standalone titles, project names, and archived chats", () => {
  const ids = query => searchChats(chats, projects, query).map(chat => chat.id);
  assert.deepEqual(ids(""), ["standalone", "project"]);
  assert.deepEqual(ids("  RELEASE  "), ["standalone", "archived"]);
  assert.deepEqual(ids("Website"), ["project"]);
  assert.deepEqual(ids("buttons"), ["project"]);
  assert.deepEqual(ids("xyzzy"), []);
});
