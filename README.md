# BrightLeaf Digital Code Snippets

Free, self-contained code snippets for Gravity Forms and its ecosystem — Gravity Flow,
GravityView, Gravity Perks, GravityKit, GravityOps — plus a handful for WordPress itself.

Every file here is also listed, with its description, in the
**[BrightLeaf Digital Code Snippet Library](https://brightleafdigital.io/code/)**, which reads
the code straight out of this repository. Browsing the library is the easiest way to find a
snippet; this repository is where the code lives.

## Using a snippet

1. Open the file and read its header docblock. It states the snippet's goal, what it requires,
   what you have to configure, and how to drive it once installed. Most snippets need at least
   one form ID, field ID, or step ID changed.
2. Install it where the docblock's `@install` line says, or — when there is no such line —
   according to the extension: `.php` files go in a PHP snippet manager such as **Code
   Snippets**, `.js` files in **Code Chest** or your site's custom JavaScript.
3. Test on staging first.

`.php` files are snippets, not plugins: they have no plugin header and are meant to be pasted
into a snippet manager (or into a small plugin of your own), not dropped into `wp-content/plugins`.

Snippets are provided as-is, with no warranty and no support commitment — see the
[license](LICENSE). Bug reports and corrections are welcome as GitHub issues.

## Repository layout

```
├── snippets.json           # GOCSM library manifest (the file GOCSM fetches)
├── <Snippet Name>.php      # one snippet per file; the filename is its title
├── <Snippet Name>.js
└── .github/
    ├── scripts/            # docblock parser + directory sync
    └── workflows/          # sync-snippet-directory.yml
```

There is no build step and no dependency between snippets: each file stands alone.

## Installing with GravityOps Code Snippet Manager

GOCSM imports this repository as a snippet library. It fetches `snippets.json` from the
repository root — not `manifest.json` — then loads each file listed there.

Snippets that need a form ID, field ID, email, or similar site-specific value declare those
values in a config block (`// === BEGIN CONFIG ===` … `// === END CONFIG ===`) immediately
after the header docblock. In GOCSM those values are fields on the snippet, so applying an
update does not wipe them. Snippets with nothing to configure have no config block.

## The header docblock

The docblock is the snippet's documentation, and it is also what the library entry is generated
from, so it follows a fixed format enforced in CI:

```php
<?php
/**
 * Human-readable title
 *
 * @gravityflow
 * @install Code Snippets
 *
 * GOAL:
 * What it does, and when it is useful.
 *
 * REQUIREMENTS:
 * - Gravity Flow, with at least one Approval step.
 *
 * CONFIGURATION:
 * None, or what to edit and where.
 *
 * USAGE:
 * How to drive it once installed.
 *
 * NOTES:
 * - Caveats and edge cases.
 */
```

- **Ecosystem flags** — at least one of `@gravityforms`, `@gravityflow`, `@gravityview`,
  `@gravityperks`, `@gravitykit`, `@gravityops`, `@wordpress`. Tag only what the snippet
  directly targets.
- **`@install`** — optional; one of `Code Snippets`, `Code Chest`, `Custom JS`.
- **Sections** — `GOAL:` is required; `REQUIREMENTS:`, `CONFIGURATION:`, `USAGE:`, `FEATURES:`,
  `FILTERS:` and `NOTES:` are optional, and must appear in that order. Within a section, `- `
  makes a list, a 4-space indent makes a code block, and `` `code` ``, `**bold**` and
  `# Sub-heading` work as expected.

Validate before pushing:

```bash
php .github/scripts/parse-snippet-doc.php --all            # every file, pass/fail
php .github/scripts/parse-snippet-doc.php --file="X.php"   # one file, with rendered HTML
```

## License

[MIT](LICENSE) © BrightLeaf Digital. Use them, change them, ship them in your own work.
