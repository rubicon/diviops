// SPDX-License-Identifier: MIT
/**
 * `diviops_meta_info` reports Divi drift, not only plugin drift (#480).
 *
 * #215 gave the plugin a live re-read and a three-valued `stale`. Divi got
 * nothing — and Divi is the thing whose changes move behaviour under us. #414
 * exists because Divi 5.12.1 refactored `StyleDeclarations.php` so a padding
 * corner we write stopped suppressing anything; #445 is a Divi bug in
 * `ButtonModule.php`. Both were found by reading Divi's source at one version.
 *
 * Measured on the host this repository points at: 5.12.1 → 5.13 → 5.13.1 within
 * days. A Divi upgrade mid-session left every capability gate reporting itself
 * current while the code it was negotiated against had been replaced.
 *
 * ── Why the version string and not a fingerprint ──────────────────────────
 *
 * The plugin needs `code_fingerprint` because our own builds change constantly
 * at an unchanged version (#215). Divi does not: it is a third-party release
 * whose version moves when its code does. And the heavy option was measured
 * rather than assumed — Divi is 1,650 PHP files and 135 MB against our plugin's
 * 22 files, so hashing it on every `meta_info` call is not viable.
 *
 * The residual blind spot is a hand-patched Divi at an unchanged version. That
 * is stated in the field's own warning rather than papered over.
 *
 * ── Why a separate field and not a merged one ─────────────────────────────
 *
 * `stale` answers "restart the MCP client". A Divi change usually does NOT need
 * that — it needs re-reading Divi's source before trusting anything derived
 * from it. Collapsing the two into one flag makes the operator's next action
 * ambiguous, which is the same reasoning #343 used to keep site identity out of
 * the staleness signals.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { buildLiveHandshakeReport } from '../compatibility.js';

const FP = 'a'.repeat(64);
const SPAWN = { pluginVersion: '1.25.0', codeFingerprint: FP };
const spawnWith = (diviVersion: string | null) => ({ ...SPAWN, diviVersion });
const liveWith = (diviVersion: string | null) => ({
  ok: true as const,
  pluginVersion: '1.25.0',
  codeFingerprint: FP,
  diviVersion,
});

describe('buildLiveHandshakeReport — Divi drift', () => {
  it('reports the live Divi version beside the plugin one', () => {
    const report = buildLiveHandshakeReport(spawnWith('5.13.1'), liveWith('5.13.1'));

    assert.equal(report.divi_version, '5.13.1');
    assert.equal(report.divi_stale, false);
  });

  it('flags a Divi upgrade that happened mid-session', () => {
    const report = buildLiveHandshakeReport(spawnWith('5.12.1'), liveWith('5.13.1'));

    assert.equal(report.divi_stale, true);
    assert.match(String(report.divi_warning), /5\.12\.1/);
    assert.match(String(report.divi_warning), /5\.13\.1/);
    // The action differs from the plugin's. A Divi change does not usually need
    // a client restart; it needs Divi's source re-read before anything derived
    // from it is trusted.
    assert.match(String(report.divi_warning), /Divi/);
  });

  it('does NOT fold Divi drift into the plugin stale flag', () => {
    const report = buildLiveHandshakeReport(spawnWith('5.12.1'), liveWith('5.13.1'));

    // The plugin did not change. Saying it did would send an operator to
    // restart a client that is not the problem.
    assert.equal(report.stale, false);
    assert.equal(report.divi_stale, true);
  });

  it('does NOT fold plugin drift into the Divi flag either', () => {
    const report = buildLiveHandshakeReport(
      { pluginVersion: '1.24.0', codeFingerprint: FP, diviVersion: '5.13.1' },
      { ok: true, pluginVersion: '1.25.0', codeFingerprint: FP, diviVersion: '5.13.1' },
    );

    assert.equal(report.stale, true);
    assert.equal(report.divi_stale, false);
  });

  it('answers unknown, not false, when either side is missing', () => {
    // Divi inactive, or a plugin predating the handshake block. `false` here
    // would be the same confident-but-unfounded answer #215 exists to replace.
    assert.equal(buildLiveHandshakeReport(spawnWith(null), liveWith('5.13.1')).divi_stale, null);
    assert.equal(buildLiveHandshakeReport(spawnWith('5.13.1'), liveWith(null)).divi_stale, null);
    assert.equal(buildLiveHandshakeReport(spawnWith(null), liveWith(null)).divi_stale, null);
  });

  it('names the residual blind spot when it cannot compare', () => {
    const report = buildLiveHandshakeReport(spawnWith(null), liveWith(null));

    assert.equal(report.divi_stale, null);
    assert.ok(
      String(report.divi_warning).length > 0,
      'an unknown must say why it is unknown, or it reads as a bug rather than a limit',
    );
  });

  it('reports nothing about Divi when the live re-check itself failed', () => {
    const report = buildLiveHandshakeReport(spawnWith('5.13.1'), {
      ok: false,
      message: 'ECONNREFUSED',
    });

    // Not false, not true: the re-check never happened, so every field it would
    // have populated is unknown — the plugin's own contract, applied here too.
    assert.equal(report.stale, null);
    assert.equal(report.divi_stale, null);
    assert.equal(report.divi_version, null);
  });

  it('leaves the existing plugin contract untouched', () => {
    // The #215 behaviour is load-bearing and must not shift under this addition.
    const unchanged = buildLiveHandshakeReport(
      { pluginVersion: '1.25.0', codeFingerprint: FP },
      { ok: true, pluginVersion: '1.25.0', codeFingerprint: FP },
    );

    assert.equal(unchanged.state, 'ok');
    assert.equal(unchanged.stale, false);
    // A caller that never passes a Divi version still gets a coherent answer
    // rather than an exception or a fabricated `false`.
    assert.equal(unchanged.divi_stale, null);
  });
});
