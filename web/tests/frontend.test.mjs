import test from "node:test";
import assert from "node:assert/strict";
import { parseRoute, chatPath } from "../src/lib/routes.ts";
import { applyMessagePatch, nextStreamText } from "../src/lib/messages.ts";
import { avatarUrl, validAvatarUrl } from "../src/lib/identity.ts";

test("chat routes support direct loads without interpreting arbitrary paths", () => {
  assert.deepEqual(parseRoute("/"), { page: "new" });
  assert.deepEqual(parseRoute("/settings"), { page: "settings" });
  const id = "fb6904dc4c961211";
  assert.deepEqual(parseRoute(chatPath(id)), { page: "chat", id });
  assert.deepEqual(parseRoute(`/chat/${id}/`), { page: "chat", id });
  for (const path of [
    "/unknown",
    "/chat/",
    "/chat/../../etc",
    `/chat/${id}/extra`,
  ])
    assert.equal(parseRoute(path).page, "missing");
});

test("stream patches upsert, append and order messages without mutating previous state", () => {
  const first = { id: 1, body: "Hello" };
  assert.deepEqual(
    applyMessagePatch(
      [first],
      [{ id: 2, body: "Next" }],
      [
        { id: 1, delta: " world" },
        { id: 99, delta: "ignored" },
      ],
    ),
    [
      { id: 1, body: "Hello world" },
      { id: 2, body: "Next" },
    ],
  );
  assert.equal(first.body, "Hello");
  assert.deepEqual(
    applyMessagePatch(
      [{ id: 2, body: "old" }],
      [{ id: 2, body: "complete" }, first],
    ),
    [first, { id: 2, body: "complete" }],
  );
});

test("stream reveal advances safely without splitting code points", () => {
  assert.equal(nextStreamText("Hello world", "Hello", 24), "Hello wor");
  assert.equal(nextStreamText("Hello world", "Hello wor", 24), "Hello world");
  assert.equal(nextStreamText("Hello 🦀!", "Hello ", 24), "Hello 🦀!");
  assert.equal(nextStreamText("Rewritten", "Old", 24), "Rewritten");
});

test("avatar preferences resolve by author and reject executable URLs", () => {
  assert.notEqual(avatarUrl("user1", {}), avatarUrl("user2", {}));
  assert.equal(
    avatarUrl("user2", { user1: "https://example.com/photo.jpg" }),
    avatarUrl("user2", {}),
  );
  assert.equal(avatarUrl("unknown", {}), "");
  assert.equal(validAvatarUrl("javascript:alert(1)"), false);
  assert.equal(validAvatarUrl("https://example.com/photo.jpg"), true);
});

test("sidebar resizing stays within its 200–500px bounds", async () => {
  const { clampPanelWidth, clampSidebarWidth } = await import("../src/lib/layout.ts");
  assert.equal(clampSidebarWidth(150), 200);
  assert.equal(clampSidebarWidth(450), 450);
  assert.equal(clampSidebarWidth(900), 500);
  assert.equal(clampPanelWidth(200, 240, 500, 1200), 240);
  assert.equal(clampPanelWidth(420, 240, 500, 1200), 420);
  assert.equal(clampPanelWidth(900, 240, 500, 1200), 500);
});

test("sidebar shortcut accepts Command/Ctrl+B without repeats or conflicting modifiers", async () => {
  const { isSidebarShortcut } = await import("../src/lib/shortcuts.ts");
  const event = {
    key: "b",
    metaKey: true,
    ctrlKey: false,
    altKey: false,
    shiftKey: false,
    repeat: false,
    isComposing: false,
  };
  assert.equal(isSidebarShortcut(event), true);
  assert.equal(
    isSidebarShortcut({ ...event, metaKey: false, ctrlKey: true }),
    true,
  );
  for (const change of [
    { metaKey: false },
    { key: "n" },
    { altKey: true },
    { shiftKey: true },
    { repeat: true },
    { isComposing: true },
  ]) {
    assert.equal(isSidebarShortcut({ ...event, ...change }), false);
  }
});
