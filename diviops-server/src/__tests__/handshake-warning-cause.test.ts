// SPDX-License-Identifier: MIT
/**
 * The boot handshake warning carries the transport cause chain (#281).
 *
 * `main()` logs `Handshake warning (gate disabled): ${msg}` when the startup
 * handshake fails. That line is frequently the ONLY record of the failure —
 * the server keeps running with the capability gate disabled, so a session can
 * go a long time before a tool call surfaces anything else. During the
 * 2026-08-27 incident this warning appeared 43 times across ten days in the
 * desktop host's log carrying nothing but `fetch failed`.
 *
 * `main()` reads the environment, constructs a client and binds a stdio
 * transport, so there is no unit harness for it and building one to cover a
 * single substitution would cost far more than it protects. This is therefore
 * a structural assertion: the catch must route through `describeError` rather
 * than restringing the error itself.
 *
 * Per this repository's standing rule, a gate that derives pass/fail only from
 * problems-found will pass while inspecting nothing. So this test asserts
 * first that it actually found the handshake catch, and only then what that
 * catch does.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const INDEX_SOURCE = fileURLToPath(new URL('../../src/index.ts', import.meta.url));

describe('the boot handshake warning reports why the transport failed', () => {
  it('routes the caught handshake error through the cause-chain formatter', () => {
    const source = readFileSync(INDEX_SOURCE, 'utf8');

    const warningLine = source
      .split('\n')
      .findIndex((line) => line.includes('Handshake warning (gate disabled)'));
    assert.notEqual(
      warningLine,
      -1,
      'did not find the handshake warning in index.ts — this gate inspected nothing and must not report success',
    );

    // The catch that builds `msg` sits just above the warning it logs.
    const block = source.split('\n').slice(Math.max(0, warningLine - 20), warningLine + 1).join('\n');

    assert.match(
      block,
      /describeError\(/,
      'the handshake catch must use describeError so the errno reaches the log',
    );
    assert.doesNotMatch(
      block,
      /instanceof Error \? error\.message : String\(error\)/,
      'restringing the error here drops the cause chain that #281 exists to preserve',
    );
  });

  it('attaches the remediation hint to the warning too (#284)', () => {
    // This log line is where the failure actually hid: the desktop host emitted
    // it 43 times across ten days while the server kept running with the gate
    // disabled, and no envelope was ever consulted. A hint that reaches only
    // meta_ping would leave the exact scenario that motivated #284 silent.
    const source = readFileSync(INDEX_SOURCE, 'utf8');

    const warningLine = source
      .split('\n')
      .findIndex((line) => line.includes('Handshake warning (gate disabled)'));
    assert.notEqual(
      warningLine,
      -1,
      'did not find the handshake warning in index.ts — this gate inspected nothing',
    );

    const block = source
      .split('\n')
      .slice(Math.max(0, warningLine - 20), warningLine + 1)
      .join('\n');

    assert.match(
      block,
      /transportHint\(/,
      'the handshake catch must consult transportHint so a self-signed cert explains itself here',
    );
  });
});
