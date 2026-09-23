# Translations

This directory is what the plugin header's `Domain Path: /languages` points at. It
is where a translation **bundled with the plugin** must live:

```
diviops-agent-<locale>.mo      e.g. diviops-agent-es_ES.mo
```

Without the header, core has nowhere to look for such a file and a bundled
translation can never load — which is the gap
[#491](https://github.com/rubicon/diviops/issues/491) closed. A translation
installed in the **global** directory
(`wp-content/languages/plugins/diviops-agent-<locale>.mo`) loads either way; core
has auto-loaded those since WordPress 4.6.

There is no `load_plugin_textdomain()` call, deliberately. Core auto-loads plugin
translations on WordPress 6.5+, which is this plugin's declared floor, so the call
would be dead weight — and called on the wrong hook it is the usual cause of the
"translation loaded too early" notice. `tests/test-i18n-text-domain.php` asserts it
stays absent so it is not added back by reflex.

## No `.pot` ships, on purpose

A `.pot` is a snapshot of the strings at the moment it was generated. Shipping one
without a regeneration step in CI means shipping a file that rots silently and hands
translators strings the plugin no longer emits — worse than having none, because it
looks authoritative.

Generate one on demand instead:

```bash
wp i18n make-pot plugins/diviops-agent plugins/diviops-agent/languages/diviops-agent.pot
```

Revisit this when a translation is actually being produced. That is the point at
which a stale `.pot` would cost something, and also the point at which somebody is
looking at it often enough to notice.

## What is guarded

`tests/test-i18n-text-domain.php` asserts the header, this directory, and that every
translatable string in `plugins/diviops-agent` uses the `diviops-agent` domain and
passes it as a literal. It tokenizes with PHP's own lexer rather than pattern
matching, and it asserts how many files and call sites it inspected before reporting
that it found nothing wrong.
