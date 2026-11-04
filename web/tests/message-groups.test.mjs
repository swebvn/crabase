import assert from "node:assert/strict";
import { test } from "node:test";
import { groupConversationMessages } from "../src/lib/messages.ts";

test("conversation timestamps belong after the full assistant/tool group", () => {
  const messages = ["user", "assistant", "tool", "agent_activity", "tool", "assistant", "note", "tool", "user", "guide"]
    .map((role, id) => ({ id, role, created_at: String(id) }));
  const groups = groupConversationMessages(messages);
  assert.deepEqual(groups.map(group => group.map(message => message.id)), [[0], [1, 2, 4, 5], [6], [7], [8], [9]]);
  assert.equal(groups[1].at(-1).created_at, "5");
  assert.deepEqual(groupConversationMessages([]), []);
});

test("finished turns summarize their tool work in one line and keep the final reply visible", async () => {
  const { summarizeTurn } = await import("../src/lib/messages.ts");
  const msg = (id, role, author, body) => ({ id, role, author, body, created_at: String(id) });
  const turn = [
    msg(1, "assistant", "Crab", "Checking the script."),
    msg(2, "tool", "Terminal", "sed -n 1,20p app.py\nok"),
    msg(3, "tool", "Terminal", "python3 -m py_compile app.py"),
    msg(4, "tool", "File changes", "app.py\nlib/util.py"),
    msg(5, "tool", "File changes", "app.py"),
    msg(6, "assistant", "Crab", "Fixed the refresh job."),
  ];
  const summary = summarizeTurn(turn);
  assert.equal(summary.label, "Ran 2 commands, edited 2 files");
  assert.equal(summary.final.id, 6);
  assert.deepEqual(summary.work.map((m) => m.id), [1, 2, 3, 4, 5]);
  assert.equal(summarizeTurn([msg(1, "assistant", "Crab", "Just text")]), null);
  assert.equal(summarizeTurn([msg(1, "tool", "Terminal", "ls")]).label, "Ran 1 command");
  assert.equal(summarizeTurn([msg(1, "tool", "Terminal", "ls")]).final, null);
  assert.equal(summarizeTurn([msg(1, "tool", "Web search", "q"), msg(2, "assistant", "Crab", "   ")]).label, "Used 1 tool");
});
