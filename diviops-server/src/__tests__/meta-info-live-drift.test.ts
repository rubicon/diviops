// SPDX-License-Identifier: MIT
/**
 * `diviops_meta_info` reports live plugin state, not a spawn-time snapshot (#215).
 *
 * The capability handshake runs once, at process start, and is never
 * re-negotiated. Everything `meta_info` said about the plugin was therefore a
 * claim about whatever was installed when the process started — which, for a
 * server process that outlives the app that spawned it (2d15h observed), can
 * be arbitrarily old. Twice in one week a deploy landed and `meta_info` kept
 * reporting the previous version for hours, both times while someone was
 * trying to determine whether a fix had shipped.
 *
 * The snapshot itself is not the bug — capability gates legitimately reflect
 * what was negotiated. The bug is that nothing in the response said the
 * snapshot might no longer describe the site. So the live re-check is reported
 * alongside the negotiated values rather than replacing them, and the
 * comparison is performed here rather than left for the caller: a signal a
 * caller has to construct from two fields is one most callers will not
 * construct.
 *
 * `stale: null` is load-bearing. When the live re-check fails, or when the
 * plugin is too old to report a fingerprint, the honest answer is "unknown" —
 * `false` there would be the same silent wrong answer this issue is about.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { buildLiveHandshakeReport } from '../compatibility.js';

const SPAWN_FP = 'a'.repeat(64);
const LIVE_FP = 'b'.repeat(64);

describe('buildLiveHandshakeReport', () => {
  it('reports not-stale when the live re-check matches the spawn snapshot', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.2', codeFingerprint: SPAWN_FP },
      { ok: true, pluginVersion: '1.19.2', codeFingerprint: SPAWN_FP },
    );

    assert.equal(report.state, 'ok');
    assert.equal(report.stale, false);
    assert.equal(report.plugin_version, '1.19.2');
    assert.equal(report.code_fingerprint, SPAWN_FP);
    assert.equal(report.warning, undefined);
  });

  it('flags a version change and names both versions', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.1', codeFingerprint: SPAWN_FP },
      { ok: true, pluginVersion: '1.19.2', codeFingerprint: LIVE_FP },
    );

    assert.equal(report.stale, true);
    assert.equal(report.plugin_version, '1.19.2');
    assert.match(report.warning ?? '', /1\.19\.1/);
    assert.match(report.warning ?? '', /1\.19\.2/);
    // The actionable half: the gates did not move, so a restart is required.
    assert.match(report.warning ?? '', /restart/i);
  });

  it('flags same-version code drift — the case a version comparison cannot see', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.2', codeFingerprint: SPAWN_FP },
      { ok: true, pluginVersion: '1.19.2', codeFingerprint: LIVE_FP },
    );

    assert.equal(report.stale, true);
    assert.match(report.warning ?? '', /fingerprint/i);
    assert.equal(report.code_fingerprint, LIVE_FP);
  });

  it('answers "unknown" rather than "in sync" when the live re-check fails', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.2', codeFingerprint: SPAWN_FP },
      { ok: false, message: 'WordPress API error (401): unauthorized' },
    );

    assert.equal(report.state, 'failed');
    assert.equal(report.stale, null);
    assert.equal(report.plugin_version, null);
    assert.equal(report.code_fingerprint, null);
    // The underlying cause survives; a bare "could not check" would send the
    // reader hunting for a failure the response already knew the name of.
    assert.match(report.warning ?? '', /401/);
  });

  it('answers "unknown" when the plugin is too old to report a fingerprint', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.2', codeFingerprint: null },
      { ok: true, pluginVersion: '1.19.2', codeFingerprint: null },
    );

    assert.equal(report.state, 'ok');
    assert.equal(report.stale, null);
    assert.match(report.warning ?? '', /fingerprint/i);
  });

  it('still reports drift on a version change when no fingerprint is available', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.19.1', codeFingerprint: null },
      { ok: true, pluginVersion: '1.19.2', codeFingerprint: null },
    );

    assert.equal(report.stale, true);
    assert.match(report.warning ?? '', /1\.19\.2/);
  });
});
