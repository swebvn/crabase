import test from "node:test";
import assert from "node:assert/strict";
import { appendTerminalOutput, terminalInputChunks, terminalOutputUpdate } from "../src/lib/terminal.ts";

const session = (output) => ({ id: "terminal", chat_id: "chat", title: "Terminal", output, running: true });

test("large terminal pastes preserve Unicode and fit the WebSocket byte limit", () => {
  for (const input of ["", "a".repeat(16384), "a".repeat(16383) + "🦀", "Tiếng Việt 🦀\r\n".repeat(6000)]) {
    const chunks = terminalInputChunks(input);
    assert.equal(chunks.join(""), input);
    for (const chunk of chunks) {
      assert.ok(Buffer.byteLength(chunk) <= 16384);
      assert.ok(chunk.length > 0);
      assert.equal(Buffer.from(chunk).toString(), chunk, "must not split surrogate pairs");
    }
  }
});

test("terminal appends only new output across history truncation", () => {
  const initial = session("x".repeat(999998));
  const next = appendTerminalOutput(initial, "hello");
  assert.equal(next.output.length, 1000000);
  assert.equal(next.outputOffset, 3);
  assert.deepEqual(terminalOutputUpdate(next, initial.output.length), {
    reset: false, data: "hello", position: 1000003,
  });
  const final = appendTerminalOutput(appendTerminalOutput(next, "\u001b["), "31mred");
  assert.equal(terminalOutputUpdate(final, 1000003).data, "\u001b[31mred");
});

test("identical full buffers still advance the terminal stream", () => {
  const initial = session("x".repeat(1000000));
  const next = appendTerminalOutput(initial, "xxx");
  assert.equal(next.output, initial.output);
  assert.deepEqual(terminalOutputUpdate(next, 1000000), {
    reset: false, data: "xxx", position: 1000003,
  });
});

test("terminal recovers when unread output was evicted or a fresh snapshot arrives", () => {
  const next = appendTerminalOutput(session("start"), "x".repeat(1000001));
  assert.equal(terminalOutputUpdate(next, 5).reset, true);
  assert.equal(terminalOutputUpdate(next, 5).data.length, 1000000);
  assert.deepEqual(terminalOutputUpdate(session("fresh"), 1000006), {
    reset: true, data: "fresh", position: 5,
  });
});
