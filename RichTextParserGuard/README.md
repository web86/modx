# RichTextParserGuard

Server-side parser guard for **MODX Revolution 2.x** RichText fields.

It protects content pasted from Google, AI search results, Word, websites, and similar sources when that pasted HTML contains invisible comments or accidental MODX/Fenom syntax such as:

```html
<!--TgQPHd|||[[ ... huge JSON ... ]]-->
```

Without protection MODX may try to execute `[[ ... ]]` as a tag/snippet and generate errors like:

```text
Could not find snippet with name ...
```

Fenom can run into a similar problem with accidental `{ ... }` content.

## What it does

Only RichText values are processed.

- removes HTML comments `<!-- ... -->`
- converts `[[` to `&#91;&#91;`
- converts `]]` to `&#93;&#93;`
- converts `{` to `&#123;`
- converts `}` to `&#125;`

The visible text in the browser stays the same, but MODX/Fenom no longer sees parser syntax.

The plugin intentionally does **not** strip tags, classes, styles, IDs or `data-*` attributes.

## Supported fields

- standard resource `content` when `richtext = 1`
- standard MODX TVs with input type `richtext`
- Polylang 1.3.x visual-editor fields
- Polylang RichText TVs

The Polylang path has been tested with **Polylang 1.3.19**.

## Installation

1. Create a MODX plugin named `RichTextParserGuard`.
2. Copy the contents of `plugin.php` into the plugin editor **without the opening `<?php` tag**.
3. Enable these system events:

```text
OnBeforeDocFormSave
OnDocFormSave
OnMODXInit
```

4. Clear the MODX cache.

No Polylang files need to be modified.

## Example

Input:

```html
<p>Test [[demo]] {"foo":"bar"}</p>
```

Stored value:

```html
<p>Test &#91;&#91;demo&#93;&#93; &#123;"foo":"bar"&#125;</p>
```

The browser still renders:

```text
Test [[demo]] {"foo":"bar"}
```

## TinyMCE note

TinyMCE decodes HTML entities when loading the field. Because of that, even its **Source code** dialog may show:

```html
<p>Test [[demo]] {"foo":"bar"}</p>
```

even though the database contains the protected entities.

When debugging, inspect the raw stored value or use plugin debug logging instead of relying on TinyMCE source view.

## Debugging

In `plugin.php` change:

```php
$debug = false;
```

to:

```php
$debug = true;
```

For Polylang saves the MODX log will contain a line similar to:

```text
[RichTextParserGuard] Polylang action: mgr/polylangcontent/update | changed: content
```

Set debug back to `false` after testing.
