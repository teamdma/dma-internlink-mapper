# Link Opportunities

Link Opportunities helps you find places where one existing page may naturally link to another.

The feature is meant to reduce the boring part of internal-link research. It does not remove the need to read the suggestion and decide whether the link makes sense for a visitor.

## How opportunities are generated

Opportunities are built from the latest completed local scan.

The plugin checks existing content, candidate anchors, current links, confidence and whether the location is technically suitable for insertion. It also avoids unsupported or sensitive areas according to the current settings and editor support.

## Recommended workflow

1. Finish a fresh scan.
2. Open **Link Opportunities**.
3. Generate opportunities.
4. Review the suggested source page, destination page and anchor text.
5. Preview the proposed change.
6. Insert only the links that genuinely improve the page.

If the site has changed significantly since the last opportunity generation, regenerate the suggestions before inserting links.

## Safe insertion

DMA InternLink Mapper uses validation before changing content. The plugin checks the current source content instead of blindly assuming it is identical to the scan snapshot.

Insertion support also depends on the editor and field type.

### Gutenberg and Classic Editor

Supported body content can be analyzed and used for reviewed insertion.

### Elementor

Supported saved text and WYSIWYG controls can be analyzed.

Automatic insertion is intentionally limited. Headings, buttons, URL fields, code fields, Dynamic Tags, headers, hero sections, calls to action and footers are not automatically modified.

That limitation is deliberate. A link tool should not turn a button label or a hero heading into a surprise experiment just because two words happened to match.

## History and undo

The plugin keeps insertion history and supports undo for supported changes. Optional WordPress revisions can provide another recovery layer.

Still, use your normal backup process before large batches of content edits.

## What makes a good opportunity?

A useful internal link usually does at least one of these things:

- Helps the reader continue naturally to a related page.
- Gives an important page stronger contextual support.
- Connects an isolated page to the rest of the site.
- Replaces vague anchor text with something more descriptive.

Do not insert a link simply because the plugin found a possible match. Internal linking works best when the page reads better after the link is added, not merely when a counter goes up.
