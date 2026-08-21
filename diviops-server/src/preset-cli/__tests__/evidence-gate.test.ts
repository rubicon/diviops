// SPDX-License-Identifier: MIT
/**
 * Tests for the verified-attrs evidence gate that stands in front of every
 * preset write emitter (#230, adopted from upstream in #240).
 *
 * Upstream ships this CLI with no tests at all, so these are the fork's own
 * characterization of the safety property we are taking on. The property is
 * not "the gate runs" — it is that the gate is FAIL-CLOSED in each of the
 * three ways it can be asked to guess:
 *
 *   1. A pattern verified at the top level does not vouch for a module whose
 *      own cell is weaker. Effective evidence is min(pattern, cell), so a
 *      VB_PRESET_STORAGE_VERIFIED family cannot smuggle a SCHEMA_OBSERVED
 *      module past the write threshold.
 *   2. A module absent from `applicability` resolves to UNVERIFIED (0), not
 *      to the pattern level. There is no invisible inheritance.
 *   3. A pattern family absent from the registry throws rather than
 *      resolving to zero and continuing.
 *
 * The fourth test is the one that matters most for this repo specifically:
 * a gate that refuses everything passes tests 1-3 while being useless. The
 * shipped registry is asserted to still ALLOW a real write, so an emptied or
 * truncated `verified-attrs.json` fails the suite instead of sailing through
 * it looking maximally safe.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
  EvidenceGateError,
  WRITE_EMITTER_THRESHOLD_LEVEL,
  gateWriteAttr,
  loadRegistry,
  resolveEvidence,
  writeThresholdNumber,
} from '../registry.js';

const registry = loadRegistry();

describe('effective evidence is the weaker of pattern and cell', () => {
  it('refuses a module whose cell is weaker than its fully verified pattern', () => {
    // `module.decoration.background` is VB_PRESET_STORAGE_VERIFIED at the
    // pattern level, but divi/heading's own cell is only SCHEMA_OBSERVED.
    const resolution = resolveEvidence(
      registry,
      'divi/heading',
      'module.decoration.background',
    );

    assert.equal(resolution.patternLevelName, 'VB_PRESET_STORAGE_VERIFIED');
    assert.equal(resolution.cellLevelName, 'SCHEMA_OBSERVED');
    assert.equal(
      resolution.effectiveLevel,
      Math.min(resolution.patternLevel, resolution.cellLevel),
    );
    assert.ok(
      resolution.effectiveLevel < writeThresholdNumber(registry),
      'the weaker cell must decide the outcome, not the stronger pattern',
    );

    assert.throws(
      () =>
        gateWriteAttr(registry, 'divi/heading', 'module.decoration.background'),
      EvidenceGateError,
    );
  });

  it('allows the same family on the module whose own cell is verified', () => {
    // divi/section carries VB_PRESET_STORAGE_VERIFIED on the same family.
    // Without this the previous test would also pass on a gate that refuses
    // every input for any reason.
    const resolution = gateWriteAttr(
      registry,
      'divi/section',
      'module.decoration.background',
    );
    assert.equal(resolution.effectiveLevelName, WRITE_EMITTER_THRESHOLD_LEVEL);
  });
});

describe('a module missing from applicability is untrusted, not inherited', () => {
  it('resolves an absent cell to UNVERIFIED and refuses the write', () => {
    // `divi/button.background` lists divi/button and nothing else.
    const resolution = resolveEvidence(
      registry,
      'divi/section',
      'divi/button.background',
    );

    assert.equal(resolution.applicabilityMissing, true);
    assert.equal(resolution.cellLevel, 0);
    assert.equal(resolution.cellLevelName, 'UNVERIFIED');
    assert.equal(resolution.effectiveLevel, 0);

    assert.throws(
      () => gateWriteAttr(registry, 'divi/section', 'divi/button.background'),
      (err: unknown) =>
        err instanceof EvidenceGateError &&
        err.resolution.applicabilityMissing === true,
    );
  });
});

describe('an unregistered pattern family is an error, not a zero', () => {
  it('throws instead of resolving a family the registry has never seen', () => {
    assert.throws(
      () => resolveEvidence(registry, 'divi/button', 'divi/not-a-real-family'),
      /absent from verified-attrs\.json/,
    );
  });

  it('refuses to let a Pattern A entry vouch for a Pattern B caller', () => {
    // divi/font carries two variants. Pattern A lists divi/testimonial;
    // Pattern B lists divi/heading only. Asking for divi/testimonial while
    // naming Pattern B must not silently match the Pattern A entry.
    const patternA = resolveEvidence(
      registry,
      'divi/testimonial',
      'divi/font',
      'google_fonts_pattern_a',
    );
    assert.equal(patternA.applicabilityMissing, false);

    const patternB = resolveEvidence(
      registry,
      'divi/testimonial',
      'divi/font',
      'local_hosted_pattern_b',
    );
    assert.equal(
      patternB.applicabilityMissing,
      true,
      'the variant must select the entry; Pattern B has no divi/testimonial cell',
    );
  });
});

describe('the shipped registry still permits real writes', () => {
  it('clears the threshold for every family the emitters actually emit', () => {
    // A registry that refused everything would satisfy every test above while
    // making the CLI inert. These are the exact families the button, font,
    // and spacing emitters gate on, so an emptied or truncated data file
    // fails here rather than passing as maximally cautious.
    const emitted: Array<[string, string, string | undefined]> = [
      ['divi/button', 'divi/button.background', undefined],
      ['divi/button', 'divi/button.border', undefined],
      ['divi/button', 'divi/button.font', 'google_fonts_pattern_a'],
      ['divi/heading', 'divi/font', 'google_fonts_pattern_a'],
      ['divi/heading', 'divi/font', 'local_hosted_pattern_b'],
      ['divi/text', 'divi/font-body', 'google_fonts_pattern_a'],
      ['divi/section', 'divi/spacing', undefined],
    ];

    for (const [module, family, variant] of emitted) {
      const resolution = gateWriteAttr(registry, module, family, variant);
      assert.ok(
        resolution.effectiveLevel >= writeThresholdNumber(registry),
        `${family} on ${module} must remain writable`,
      );
    }
  });

  it('reads the write threshold from the registry rather than assuming it', () => {
    assert.equal(
      writeThresholdNumber(registry),
      registry.evidence_level_ordering[WRITE_EMITTER_THRESHOLD_LEVEL],
    );
    assert.ok(writeThresholdNumber(registry) > 0);
  });
});
