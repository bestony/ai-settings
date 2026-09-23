=== Bestony's AI Settings ===
Contributors:      bestony
Tags:              ai, settings, experiments, connector
Requires at least: 7.0
Tested up to:      7.1
Stable tag:        0.2.0
Requires PHP:      7.4
Requires Plugins:  ai
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Edit every configuration option of the WordPress AI plugin from one screen.

== Description ==

This plugin edits the settings of the [AI plugin](https://cn.wordpress.org/plugins/ai/), which has
to be installed and active — WordPress will not activate Bestony's AI Settings without it.

The WordPress AI plugin spreads its settings across one switch per feature, a provider and model
override per feature, and a handful of feature-specific options. This plugin puts all of them on a
single screen, so you can see and change the whole configuration in one place.

**Settings → Bestony's AI Settings** splits the whole configuration into tabs, one per group, plus a
**General** tab (the master switch and the bulk switches), a **Models** tab and an
**Import and export** tab. Features are grouped by where they take effect:

* **Content generation** — title, excerpt, summary, resize and translation.
* **Editing assistance** — type-ahead, editorial notes, and applying those notes.
* **SEO and taxonomy** — meta description, slug, and tag and category suggestions.
* **Media** — alt text, image generation and editing.
* **Comments** — moderation and reply suggestions.
* **Site administration** — abilities, request logging, connector approval and key encryption.

Under each tab sits the feature's own on/off switch plus the options that feature adds — the
taxonomy strategy and suggestion limit for Content Classification, guest moderation for Comment
Moderation, or type-ahead's mode, delay, confidence, word limit and heading support. That last group
is read by the AI plugin but never registered with WordPress, so no other screen can change it.

The list is discovered at runtime by reading the settings the AI plugin registers, so features added
by a future AI plugin release appear on their own — filed under **Other** until Bestony's AI Settings
knows where they belong.

=== What this screen tells you that the AI plugin's own screen does not ===

A feature only runs when the master switch and its own switch are both on. Each feature's header
shows whether it is currently running, and a switch saved as on while the feature is not running is
called out with the reason — usually the master switch. A switch saved as off that is nonetheless
running is called out too, because code on the site is forcing it on.

=== Models ===

The **Models** tab sets the per-feature provider and model override, two ways:

* **Apply to all** pushes one provider and one model onto every feature at once, optionally limited
  to the features that are running. Set the provider to "Keep unchanged", or leave the model empty,
  to change only the other half.
* The table below it edits each feature's override individually and saves them together.

The model is a dropdown of the models the chosen provider offers, and it follows the provider you
pick; with no provider set, the models are grouped by provider. Pick **Custom…** to type a model the
list does not know. Without JavaScript the field is a plain text input, which is also what you get
for a feature that uses no model.

Leaving every override empty is the usual choice — the AI plugin then picks a model using its own
preference order.

=== Bulk changes and moving settings between sites ===

* **Enable everything** / **Disable everything**, on the **General** tab, flip every switch,
  including the master one.
* **Export settings** downloads the same JSON the AI plugin's own export produces.
* **Import settings** accepts that JSON. Both buttons live on the **Import and export** tab. Only
  options the AI plugin registers are written, and a
  value that fails the option's schema is rejected and reported rather than stored.

Because both directions use the AI plugin's own endpoints, the files are interchangeable with the
AI plugin's export/import, and API keys are excluded in both — as they are by the AI plugin itself.

=== What this plugin does not do ===

API keys stay on **Settings → Connectors**. This plugin neither reads nor writes them.

== Screenshots ==

1. The configuration, split into tabs by feature group.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/bestonys-ai-settings/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings → Bestony's AI Settings.

The WordPress AI plugin must be active; without it this screen reports that no options were found.

== Frequently Asked Questions ==

= Does this replace the AI plugin's own settings screen? =

No. Both edit the same options, and either screen shows the changes made in the other.

= Why does it say a feature is saved as on but not running? =

A feature runs only when the AI plugin's master switch and that feature's own switch are both on.
Turn the master switch on at the top of the screen.

= Why is an option not on the AI plugin's own screen? =

Some features read options they never register with WordPress — Type-ahead Text's settings are the
current example. They are real and take effect, but the AI plugin's screen does not know about them.

= Are API keys included in an export? =

No. The AI plugin's export endpoint deliberately omits any option whose name looks sensitive, and
this plugin uses that endpoint unchanged.

= Which WordPress and PHP versions are required? =

WordPress 7.0 or later — the Connectors API and the AI plugin both need it — and PHP 7.4 or later.

== Changelog ==

= 0.2.0 =
* Renamed the plugin to **Bestony's AI Settings**: the plugin slug, folder and text domain are now
  `bestonys-ai-settings`. Replace the plugin folder with the new zip and reactivate.
* Removed the bundled translation files (`.po`, `.mo` and `.pot`) and the `load_plugin_textdomain()`
  call. Translations are served by translate.wordpress.org.

= 0.1.0 =
* Initial release.
* One screen, split into tabs, listing the AI plugin's master switch, every feature switch, every
  provider and model override, and the options individual features add.
* Enable everything / Disable everything.
* Export and import the AI plugin's settings JSON.
* Per-feature running state, with a note when a saved switch is not in effect.

== Upgrade Notice ==

= 0.2.0 =
Renames the plugin, folder, slug and text domain to `bestonys-ai-settings`, and drops the bundled
translations in favour of translate.wordpress.org. Replace the folder with the new zip and
reactivate; the AI plugin's options and your API keys are untouched.

= 0.1.0 =
Initial release.
