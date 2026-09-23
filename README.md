# Bestony's AI Settings

Edit every configuration option of the [WordPress AI plugin](https://github.com/WordPress/ai) from a
single screen.

The AI plugin spreads its configuration across one switch per feature, a provider and model override
per feature, and a handful of feature-specific options. This plugin discovers all of them at runtime
and puts them on one page under **Settings → Bestony's AI Settings**.

## What it manages

| Group | Options |
| --- | --- |
| Master switch | `wpai_features_enabled` |
| Per feature | `wpai_feature_{id}_enabled`, `wpai_feature_{id}_field_developer` |
| Feature-specific | Anything a feature registers, e.g. Content Classification's `strategy` and `max_suggestions`, Comment Moderation's `moderate_guests` |
| Unregistered | Type-ahead Text's `mode`, `delay`, `confidence`, `max_words` and `headings` |

API keys are **not** managed here — they stay on Settings → Connectors.

## How the page is organised

The screen is split into tabs: **General** (the master switch and the bulk switches), one tab per
module, then **Models** and **Import and export**. The active tab is named by a `tab` query argument
that is validated against the tab list and falls back to General, so each tab is an ordinary page
load and the screen works without JavaScript. The bar is WordPress' own `nav-tab-wrapper`. Because
each tab renders its own form, saving one tab submits only that tab's options.

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
it. A module holding at least one section becomes a tab, and `Collection::modules()` already drops
the empty ones. Each tab registers its sections and fields under its own Settings API page id
(`bestonys-ai-settings-<tab>`), because `do_settings_sections()` renders every section registered for a page
and offers no way to render a single one.

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

**Models.** The Models tab offers two ways to set per-feature overrides: a bulk form that pushes one
provider and model onto every feature (optionally only the running ones), and a table that edits
them feature by feature. Because of that, the model controls never appear inside the feature
sections — one option never has two competing edit forms. The table reuses the main form's field
names, so it saves through the same whitelist and schema validation.

**Choosing a model.** The model field is a dropdown of the models the selected provider exposes,
filtered when the provider changes; with no provider chosen the models are grouped by provider. The
list comes from the AI plugin's own `ai/v1/providers` route, asked for the capability each feature
declares, so an image feature is not offered text models. A model the list does not know — one the
provider added since, or a capability that reported none — can still be typed: choosing **Custom…**
reveals the text input, and a stored value that is not listed is shown that way rather than dropped.
The dropdown is the plugin's only script (`assets/models.js`); without JavaScript the field is the
plain text input it always was.

## Requirements

* WordPress 7.0 or later
* PHP 7.4 or later
* The [AI plugin](https://cn.wordpress.org/plugins/ai/), installed and active

The AI plugin is declared as a dependency in the plugin header (`Requires Plugins: ai`), so WordPress
enforces it: Bestony's AI Settings cannot be activated while the AI plugin is missing or inactive, and
the Plugins screen offers to install the AI plugin instead.

## Development

No build step, no dependencies. PSR-4 autoloading is a 20-line `src/autoload.php`, matching the other
provider plugins in this repository's family. The one script is plain ES5 with no framework, loaded
on the settings screen only. Translations are not shipped: they are served by translate.wordpress.org
for hosts on the plugin directory, and WordPress loads them automatically since 4.6.

```
src/
├── Plugin.php                 bootstrap; captures the AI plugin's feature registry
├── Config/
│   ├── Field.php              value object for one option
│   ├── Section.php            value object for one feature's group of options
│   ├── Module.php             value object for a group of sections (generation, media, …)
│   ├── Collection.php         the discovered modules, sections and fields
│   ├── Collector.php          discovery, module mapping, capability lookup and ordering
│   ├── Model_Catalog.php      the models each provider exposes, from the AI plugin's REST route
│   └── Writer.php             validation and saving, including bulk switches and bulk models
└── Admin/
    ├── Settings_Page.php      menu, tab bar, field rendering, model forms, save handlers
    └── Import_Export.php      JSON export and import
assets/
└── models.js                  turns each model field into a provider-filtered dropdown
```

## License

GPL-2.0-or-later.
