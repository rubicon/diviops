/**
 * `parseWpCliJson` — recovering a `--format=json` payload from a polluted
 * stdout stream (#167).
 *
 * wp-cli's `--format=json` contract is "stdout is JSON." PHP breaks that
 * contract before wp-cli ever runs: anything the interpreter prints at
 * startup lands on the child's stdout ahead of the payload. Reproduced live
 * against the reference site, where an imagick module-API mismatch yields
 *
 *     Warning: PHP Startup: imagick: Unable to initialize module
 *     Module compiled with module API=20230831
 *     PHP    compiled with module API=20240924
 *     These options need to match
 *      in Unknown on line 0
 *     []
 *
 * on stdout with **exit code 0**, so the runner reports `success: true` while
 * `JSON.parse` throws. That combination is what made the SCF tools unusable.
 *
 * Suppressing the noise at the source was investigated and rejected on
 * evidence, recorded here so it is not re-attempted: `-d display_errors=0`
 * is a PHP flag, and the runner invokes `execFile('wp', args)` — wp-cli is
 * the executable, not an argument to PHP. `WP_CLI_PHP_ARGS` is inert because
 * Local ships `wp` as a bare phar behind a `#!/usr/bin/env php` shebang, and
 * that variable is read only by wp-cli's shell-wrapper script. Suppression is
 * also strictly weaker than extraction: `display_errors` governs PHP
 * diagnostics only, while a mu-plugin `echo` pollutes stdout regardless.
 *
 * So extraction is the fix, and these assertions pin its two halves:
 * it must find the payload inside noise, and it must refuse to invent one.
 *
 * The scan is deliberately not a regex. A bounded pattern over nested,
 * escaped JSON text is fooled eventually — this repository has already spent
 * four rounds relearning that on the PHP side (#97). Instead the extractor
 * walks characters with string/escape awareness to delimit a candidate span
 * and then hands that span to `JSON.parse`, so the real parser, not the
 * scanner, is the oracle for whether a span is valid.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { parseWpCliJson, WpCliJsonParseError } from '../wp-cli.js';

/**
 * Verbatim capture from the reference site (Local by Flywheel,
 * colleyvillelions), `wp post list --post_type=acf-field-group
 * --format=json`. Leading newline included — the stream really does start
 * with one.
 */
const IMAGICK_POLLUTED_EMPTY =
  '\nWarning: PHP Startup: imagick: Unable to initialize module\n' +
  'Module compiled with module API=20230831\n' +
  'PHP    compiled with module API=20240924\n' +
  'These options need to match\n' +
  ' in Unknown on line 0\n' +
  '[]\n';

describe('parseWpCliJson: clean streams are unchanged', () => {
  it('parses an unpolluted array exactly as JSON.parse would', () => {
    assert.deepEqual(parseWpCliJson('[{"ID":42,"post_name":"group_abc"}]'), [
      { ID: 42, post_name: 'group_abc' },
    ]);
  });

  it('parses an unpolluted object', () => {
    assert.deepEqual(parseWpCliJson('{"ID":42,"post_status":"publish"}'), {
      ID: 42,
      post_status: 'publish',
    });
  });

  it('parses an empty array, the no-rows result wp-cli emits', () => {
    assert.deepEqual(parseWpCliJson('[]'), []);
  });

  it('tolerates the trailing newline wp-cli always appends', () => {
    assert.deepEqual(parseWpCliJson('[]\n'), []);
  });
});

describe('parseWpCliJson: leading pollution', () => {
  it('recovers the payload from the live imagick-warning capture', () => {
    assert.deepEqual(parseWpCliJson(IMAGICK_POLLUTED_EMPTY), []);
  });

  it('recovers a non-empty array from behind the same warning', () => {
    const stdout = IMAGICK_POLLUTED_EMPTY.replace(
      '[]',
      '[{"ID":900,"post_name":"group_hero"}]',
    );
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 900, post_name: 'group_hero' }]);
  });

  it('recovers an object payload, the shape `wp post get` returns', () => {
    const stdout = IMAGICK_POLLUTED_EMPTY.replace('[]', '{"ID":900,"post_status":"publish"}');
    assert.deepEqual(parseWpCliJson(stdout), { ID: 900, post_status: 'publish' });
  });
});

describe('parseWpCliJson: pollution that looks structural', () => {
  it('skips a bracket in the noise that does not begin valid JSON', () => {
    // A PHP notice citing an array offset puts a `[` on stdout before the
    // payload. Anchoring on the first `[` and giving up when it fails to
    // parse would lose a payload that is sitting right there.
    const stdout =
      'Notice: Undefined index: [foo] in /wp/plugin.php on line 3\n[{"ID":7}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 7 }]);
  });

  it('skips a brace in the noise that does not begin valid JSON', () => {
    const stdout = 'Warning: syntax near { in /wp/mu-plugins/x.php on line 9\n{"ID":7}\n';
    assert.deepEqual(parseWpCliJson(stdout), { ID: 7 });
  });
});

describe('parseWpCliJson: where a payload is allowed to begin', () => {
  /**
   * Noise and payload are told apart by position, not by content: wp-cli
   * starts its JSON on a fresh line, and PHP's diagnostics end with one. A
   * bracket quoted mid-sentence in a warning is therefore never a candidate,
   * which is what keeps a truncated stream from being salvaged down to some
   * inner fragment that happens to parse.
   */
  it('accepts a payload indented by wrapper output', () => {
    assert.deepEqual(parseWpCliJson('Warning: noise\n  [{"ID":7}]\n'), [{ ID: 7 }]);
  });

  it('ignores a bracket quoted mid-line even when it would parse alone', () => {
    // `[1,2]` mid-sentence is valid JSON in isolation. Accepting it would
    // return a fabricated payload while a real one sits on the next line.
    assert.deepEqual(parseWpCliJson('Notice: bad offset [1,2] in x.php\n[{"ID":7}]\n'), [
      { ID: 7 },
    ]);
  });

  it('refuses a mid-line value when no line-anchored payload follows', () => {
    assert.throws(
      () => parseWpCliJson('Notice: bad offset [1,2] in x.php\n'),
      WpCliJsonParseError,
    );
  });

  /**
   * Starting a line is necessary but not sufficient. `print_r` and `var_dump`
   * — the two commonest things a debugging mu-plugin echoes, and exactly the
   * "plugin output" that motivates extraction over suppression — indent an
   * array index so the line begins `[0] => …`. `[0]` is valid JSON on its
   * own, so an anchor that only checks where a span *starts* hands back `[0]`
   * as the payload, with `ok: true`, while the real rows sit on the next
   * line. A span must therefore also *end* its line.
   */
  it('does not mistake a print_r array index for the payload', () => {
    const stdout = 'Array\n(\n    [0] => alpha\n)\n[{"ID":900,"post_name":"group_hero"}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 900, post_name: 'group_hero' }]);
  });

  it('does not mistake a var_dump array index for the payload', () => {
    const stdout = 'array(1) {\n  [0]=>\n  int(1)\n}\n[{"ID":900}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 900 }]);
  });

  it('prefers a later payload over an earlier line-anchored span that also parses', () => {
    // The general form of both cases above: the first parsable candidate is
    // not automatically the payload.
    assert.deepEqual(parseWpCliJson('[0] => noise\n[{"ID":7}]\n'), [{ ID: 7 }]);
  });

  it('accepts a payload followed by trailing spaces on its own line', () => {
    assert.deepEqual(parseWpCliJson('Warning: noise\n[{"ID":7}]  \n'), [{ ID: 7 }]);
  });

  it('accepts a payload on the final line with no trailing newline', () => {
    assert.deepEqual(parseWpCliJson('Warning: noise\n[{"ID":7}]'), [{ ID: 7 }]);
  });

  it('reads a payload behind a UTF-8 BOM', () => {
    // A plugin file saved with a BOM is the classic "output started at"
    // cause. The BOM is invisible, so without this the error quotes stdout
    // that looks like perfectly valid JSON and nobody believes the failure.
    assert.deepEqual(parseWpCliJson('﻿[{"ID":900}]\n'), [{ ID: 900 }]);
  });
});

describe('parseWpCliJson: trailing pollution', () => {
  it('recovers a payload followed by a shutdown warning', () => {
    // PHP can also print during shutdown, i.e. after wp-cli wrote its JSON.
    const stdout = '[{"ID":7}]\nWarning: Cannot modify header information in Unknown on line 0\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 7 }]);
  });

  it('recovers a payload wrapped in noise on both sides', () => {
    const stdout = `${IMAGICK_POLLUTED_EMPTY.replace('[]', '[{"ID":7}]')}Warning: late failure\n`;
    assert.deepEqual(parseWpCliJson(stdout), [{ ID: 7 }]);
  });
});

describe('parseWpCliJson: the scan is string-aware', () => {
  it('does not end the span on a brace inside a string value', () => {
    const stdout = 'Warning: noise\n[{"post_title":"a } and a ] walk into a bar","ID":7}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [
      { post_title: 'a } and a ] walk into a bar', ID: 7 },
    ]);
  });

  it('does not end the span on an escaped quote inside a string value', () => {
    const stdout = 'Warning: noise\n[{"post_title":"he said \\"}\\" loudly","ID":7}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ post_title: 'he said "}" loudly', ID: 7 }]);
  });

  it('handles an escaped backslash immediately before the closing quote', () => {
    // The classic off-by-one: `"…\\"` ends the string, `"…\"` does not.
    const stdout = 'Warning: noise\n[{"path":"C:\\\\dir\\\\","ID":7}]\n';
    assert.deepEqual(parseWpCliJson(stdout), [{ path: 'C:\\dir\\', ID: 7 }]);
  });

  it('preserves a serialized-PHP blob in post_content, which is full of braces', () => {
    // `acf-field-group` rows carry a serialized fields blob — the realistic
    // worst case for a brace-counting scan.
    const blob = 'a:1:{s:5:"width";s:3:"100";}';
    const stdout = `Warning: noise\n[{"post_content":${JSON.stringify(blob)},"ID":7}]\n`;
    assert.deepEqual(parseWpCliJson(stdout), [{ post_content: blob, ID: 7 }]);
  });
});

describe('parseWpCliJson: refusing to invent a payload', () => {
  it('throws WpCliJsonParseError when the stream carries no JSON at all', () => {
    assert.throws(
      () => parseWpCliJson('Error: could not connect to the database\n'),
      WpCliJsonParseError,
    );
  });

  it('throws on an empty stream rather than returning undefined', () => {
    assert.throws(() => parseWpCliJson(''), WpCliJsonParseError);
  });

  it('throws on a truncated payload rather than salvaging a prefix', () => {
    // Returning the parsable head would be worse than failing: the caller
    // would act on a silently partial result.
    //
    // Defence in depth rather than a reachable case: a stream clipped at the
    // runner's 5MB `maxBuffer` comes back with `success: false`
    // (`ERR_CHILD_PROCESS_STDIO_MAXBUFFER`, verified), so `failScfCommand`
    // fires before any parse. This keeps the pure function honest anyway.
    assert.throws(() => parseWpCliJson('[{"ID":7},{"ID":8'), WpCliJsonParseError);
  });

  it('throws on a truncated payload even when earlier noise parses on its own', () => {
    // The assertion above pins one fixture; this pins the property. A
    // var_dump ahead of the clipped payload gives the scan an earlier
    // candidate that parses, which is precisely how a "returns a fragment"
    // regression would slip back in while the fixture test stayed green.
    assert.throws(
      () => parseWpCliJson('array(1) {\n  [0]=>\n  int(1)\n}\n[{"ID":900},{"ID":901'),
      WpCliJsonParseError,
    );
  });

  it('gives up loudly instead of hanging on a stream full of unclosed brackets', () => {
    // The scan is O(candidates x stream), so a stream that is nothing but
    // line-anchored unclosed brackets is quadratic. Measured unbounded: 8.4s
    // at 100KB, ~4x per doubling, extrapolating to hours at the 5MB ceiling —
    // and `parseWpCliJson` is synchronous, so that blocks the whole MCP
    // server and ignores the request AbortSignal. A work budget converts it
    // into a prompt failure.
    const pathological = '[\n'.repeat(100_000); // 200KB
    const startedAt = Date.now();
    assert.throws(() => parseWpCliJson(pathological), WpCliJsonParseError);
    const elapsed = Date.now() - startedAt;
    assert.ok(elapsed < 5_000, `should fail fast, took ${elapsed}ms`);
  });

  it('carries an excerpt of the offending stream so the cause is visible', () => {
    try {
      parseWpCliJson('Error: could not connect to the database\n');
      assert.fail('expected parseWpCliJson to throw');
    } catch (e) {
      if (!(e instanceof WpCliJsonParseError)) throw e;
      assert.match(e.excerpt, /could not connect to the database/);
    }
  });

  it('bounds the excerpt so a 5MB buffer cannot be inlined into an error message', () => {
    const huge = 'x'.repeat(200_000);
    try {
      parseWpCliJson(huge);
      assert.fail('expected parseWpCliJson to throw');
    } catch (e) {
      if (!(e instanceof WpCliJsonParseError)) throw e;
      assert.ok(
        e.excerpt.length <= 512,
        `excerpt should be bounded, got ${e.excerpt.length} chars`,
      );
    }
  });
});

describe('parseWpCliJson: scalar payloads', () => {
  it('does not treat a bare scalar stream as a payload', () => {
    // Every caller in index.ts passes `--format=json` against a list/get,
    // which yields an array or object. Accepting bare scalars would mean
    // `parseWpCliJson('0')` succeeding on a stream that is really an
    // error code, so the extractor anchors on `[` / `{` only.
    assert.throws(() => parseWpCliJson('0\n'), WpCliJsonParseError);
  });
});
