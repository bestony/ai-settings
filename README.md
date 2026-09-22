# AI Settings

Edit every configuration option of the [WordPress AI plugin](https://github.com/WordPress/ai) from a
single screen.

The AI plugin spreads its configuration across one switch per feature, a provider and model override
per feature, and a handful of feature-specific options. This plugin discovers all of them at runtime
and puts them on one page under **Settings → AI Settings**.

## What it manages

| Group | Options |
| --- | --- |
| Master switch | `wpai_features_enabled` |
| Per feature | `wpai_feature_{id}_enabled`, `wpai_feature_{id}_field_developer` |
| Feature-specific | Anything a feature registers, e.g. Content Classification's `strategy` and `max_suggestions`, Comment Moderation's `moderate_guests` |
| Unregistered | Type-ahead Text's `mode`, `delay`, `confidence`, `max_words` and `headings` |

API keys are **not** managed here — they stay on Settings → Connectors.

## How the page is organised

Features are grouped by where they take effect, not by the AI plugin's own `editor` / `admin`
classification — that says where a feature is registered but nothing about what it does.

| Module | Features |
| --- | --- |
| Content generation | title, excerpt, summarization, resizing, translation |
| Editing assistance | type-ahead, editorial notes, editorial updates |
| SEO and taxonomy | meta description, slug, content classification |
| Media | alt text, image generation |
| Comments | comment moderation, suggest reply |
| Site administration | abilities explorer, custom abilities, request logging, connector approval, key encryption |
| Other | anything a future AI plugin release adds that is not mapped yet |

The mapping lives in `Collector::feature_modules()`; naming a feature there is all it takes to file
it. Headings are `Module` objects, registered as field-less Settings API sections ahead of the
sections they contain — `do_settings_sections()` skips their (empty) field tables but still calls
their callbacks, which is where the heading is printed.

## How it finds the options

The AI plugin registers all of its options in one settings group (`ai_experiments`), so
`get_registered_settings()` is the authoritative list — the same source the AI plugin's own settings
import/export controller uses. Feature labels, descriptions and custom field definitions come from
the plugin's feature registry, which is captured on the `wpai_register_features` action because the
plugin exposes no global accessor for it.

Because the list is discovered rather than hard-coded, features added by a future AI plugin release
show up without a change here. The only hard-coded list is the small set of options the AI plugin
reads but never registers; any of those that later gain a `register_setting()` call are picked up
through the normal path instead.

## Behaviour worth knowing

**Running state.** A feature runs only when the master switch and its own switch are both on. Each
feature's header says whether it is currently running, and a switch that is saved on but not in
effect is explained — the master switch is off, or code on the site is forcing the feature off.

**Dropping a value.** A dropdown whose stored value is not among the offered choices shows that value
as an extra option, so saving the form never silently replaces a value written elsewhere.

**Validation.** A value is validated against the option's registered REST schema, then its sanitize
callback, then the field type. A value that fails is reported in a warning notice and not stored.

**Import and export.** Both directions call the AI plugin's own `ai/v1/settings/export` and
`ai/v1/settings/import` endpoints, so the JSON is interchangeable with the AI plugin's export and the
sensitive-option filter and schema validation stay owned by the plugin that defines them.

**Models.** The Models section under the feature list offers two ways to set per-feature overrides: a
bulk form that pushes one provider and model onto every feature (optionally only the running ones),
and a table that edits them feature by feature. Because of that, the model controls no longer appear
inside the feature sections — one option never has two competing edit forms. The table reuses the
main form's field names, so it saves through the same whitelist and schema validation.

## Requirements

* WordPress 7.0 or later
* PHP 7.4 or later
* The [AI plugin](https://cn.wordpress.org/plugins/ai/), installed and active

The AI plugin is declared as a dependency in the plugin header (`Requires Plugins: ai`), so WordPress
enforces it: AI Settings cannot be activated while the AI plugin is missing or inactive, and the
Plugins screen offers to install the AI plugin instead.

## Development

No build step, no dependencies. PSR-4 autoloading is a 20-line `src/autoload.php`, matching the other
provider plugins in this repository's family.

```
src/
├── Plugin.php                 bootstrap; captures the AI plugin's feature registry
├── Config/
│   ├── Field.php              value object for one option
│   ├── Section.php            value object for one feature's group of options
│   ├── Module.php             value object for a group of sections (generation, media, …)
│   ├── Collection.php         the discovered modules, sections and fields
│   ├── Collector.php          discovery, module mapping and ordering
│   └── Writer.php             validation and saving, including bulk switches and bulk models
└── Admin/
    ├── Settings_Page.php      menu, module headings, field rendering, model forms, save handlers
    └── Import_Export.php      JSON export and import
```

## License

GPL-2.0-or-later.
