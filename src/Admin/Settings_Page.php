<?php

/**
 * The plugin's settings screen.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Admin;

use AISettings\Config\Collection;
use AISettings\Config\Collector;
use AISettings\Config\Field;
use AISettings\Config\Model_Catalog;
use AISettings\Config\Section;
use AISettings\Config\Writer;
use AISettings\Plugin;

/**
 * Settings → AI Settings.
 *
 * The AI plugin's options are registered in another plugin's settings group, so this screen renders
 * its own fields through the Settings API but saves through its own `admin-post` handler. That keeps
 * the write path whitelisted to the options the collector discovered, and lets one handler cover
 * saving, the bulk switches and the import form.
 */
final class Settings_Page
{
    /**
     * The admin page slug.
     *
     * @var string
     */
    public const PAGE_SLUG = 'ai-settings';

    /**
     * The `name` attribute wrapping every submitted field, e.g. `ai_settings[<option name>]`.
     *
     * @var string
     */
    public const INPUT_NAME = 'ai_settings';

    /**
     * The `admin_post` action used to save the form.
     *
     * @var string
     */
    public const SAVE_ACTION = 'ai_settings_save';

    /**
     * The `admin_post` action used for the bulk switches.
     *
     * @var string
     */
    public const BULK_ACTION = 'ai_settings_bulk';

    /**
     * The `admin_post` action used to push one provider and model onto every feature.
     *
     * @var string
     */
    public const MODEL_ACTION = 'ai_settings_bulk_model';

    /**
     * The query argument and form field that names the visible tab.
     *
     * @var string
     */
    public const TAB_ARG = 'tab';

    /**
     * The tab holding the master switch and the bulk switches.
     *
     * @var string
     */
    public const TAB_GENERAL = 'general';

    /**
     * The tab holding the per-feature provider and model overrides.
     *
     * @var string
     */
    public const TAB_MODELS = 'models';

    /**
     * The tab holding the import and export controls.
     *
     * @var string
     */
    public const TAB_EXPORT = 'import-export';

    /**
     * The value of the provider dropdown that leaves a feature's provider untouched.
     *
     * @var string
     */
    private const PROVIDER_KEEP = '__keep__';

    /**
     * The capability required to view and change these settings.
     *
     * @var string
     */
    private const CAPABILITY = 'manage_options';

    /**
     * The bootstrap, used to reach the collector.
     *
     * @var Plugin
     */
    private Plugin $plugin;

    /**
     * Constructor.
     *
     * @param Plugin $plugin The plugin bootstrap.
     */
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Registers the admin hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_init', array($this, 'register_fields'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_' . self::SAVE_ACTION, array($this, 'handle_save'));
        add_action('admin_post_' . self::BULK_ACTION, array($this, 'handle_bulk'));
        add_action('admin_post_' . self::MODEL_ACTION, array($this, 'handle_bulk_model'));
        add_filter(
            'plugin_action_links_' . plugin_basename(AISETTINGS_PLUGIN_FILE),
            array($this, 'add_action_link')
        );
    }

    /**
     * Adds the page under the Settings menu.
     *
     * @return void
     */
    public function add_page(): void
    {
        add_options_page(
            __('AI Settings', 'ai-settings'),
            __('AI Settings', 'ai-settings'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    /**
     * Adds a shortcut to the settings screen on the Plugins screen.
     *
     * @param array<int, string> $links The existing action links.
     * @return array<int, string> The action links, with the shortcut appended.
     */
    public function add_action_link(array $links): array
    {
        $links[] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->page_url()),
            esc_html__('Settings', 'ai-settings')
        );

        return $links;
    }

    /**
     * Declares a Settings API section and field for everything the collector found.
     *
     * Each section is filed under its tab's own page id, because `do_settings_sections()` renders
     * every section registered for a page and offers no way to render a single section. These are
     * used for rendering only; saving goes through {@see self::handle_save()}.
     *
     * @return void
     */
    public function register_fields(): void
    {
        if (!$this->plugin->collector()->is_available()) {
            return;
        }

        $collection   = $this->plugin->collector()->collect();
        $section_tabs = array();

        foreach ($collection->sections() as $section) {
            $tab                          = $this->tab_of($section);
            $section_tabs[$section->id()] = $tab;

            add_settings_section(
                $section->id(),
                $section->label(),
                function () use ($section): void {
                    $this->render_section_intro($section);
                },
                $this->tab_page($tab)
            );
        }

        foreach ($collection->fields() as $field) {
            // Model overrides are edited in the model table instead, so that one option never has
            // two competing edit forms on the same screen.
            if (Field::KIND_PROVIDER_MODEL === $field->kind()) {
                continue;
            }

            add_settings_field(
                $field->name(),
                $field->label(),
                function () use ($field): void {
                    $this->render_field($field);
                },
                $this->tab_page($section_tabs[$field->section()]),
                $field->section()
            );
        }
    }

    /**
     * Adds the screen's own styles and script, on this screen only.
     *
     * @param string $hook_suffix The current admin page.
     * @return void
     */
    public function enqueue_assets($hook_suffix): void
    {
        if ('settings_page_' . self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        wp_register_style('ai-settings', false, array(), AISETTINGS_VERSION);
        wp_enqueue_style('ai-settings');
        wp_add_inline_style(
            'ai-settings',
            '.ai-settings-module-description{margin:1em 0 .5em;color:#646970}'
            . '.ai-settings-model-bulk{margin:1em 0;padding:1em;background:#fff;border:1px solid #c3c4c7}'
            . '.ai-settings-model-bulk label{margin-right:.3em}'
            . '.ai-settings-scope{margin-left:1em}'
            . '.ai-settings-model-table{margin-top:1em}'
            . '.ai-settings-model-table .ai-settings-model-module td{background:#f0f0f1;font-weight:600}'
            . '.ai-settings-model-picker{min-width:12em;max-width:25em;vertical-align:middle}'
            . '.ai-settings-model-picker+input{margin-left:.5em}'
        );

        wp_enqueue_script(
            'ai-settings-models',
            plugins_url('assets/models.js', AISETTINGS_PLUGIN_FILE),
            array(),
            AISETTINGS_VERSION,
            true
        );
    }

    /**
     * Builds the tab list: the tab ids, and the labels shown on the tab bar.
     *
     * One tab per module, preceded by the master switch and followed by the model overrides and the
     * import and export controls. The module order is the collector's, and only modules holding at
     * least one section are offered, so no tab is ever empty.
     *
     * @param Collection $collection The collected configuration.
     * @return array<string, string> The tab labels, keyed by tab id.
     */
    private function tabs(Collection $collection): array
    {
        $tabs = array(self::TAB_GENERAL => __('General', 'ai-settings'));

        foreach ($collection->modules() as $id => $module) {
            $tabs[$id] = $module->label();
        }

        $tabs[self::TAB_MODELS] = __('Models', 'ai-settings');
        $tabs[self::TAB_EXPORT] = __('Import and export', 'ai-settings');

        return $tabs;
    }

    /**
     * Builds the tab list for the post handlers, where no collection is at hand.
     *
     * @return array<string, string> The tab labels, keyed by tab id, or an empty array when the AI
     *                               plugin is not providing any options.
     */
    private function tab_list(): array
    {
        if (!$this->plugin->collector()->is_available()) {
            return array();
        }

        return $this->tabs($this->plugin->collector()->collect());
    }

    /**
     * Gets the tab a section belongs to.
     *
     * @param Section $section The section.
     * @return string The tab id.
     */
    private function tab_of(Section $section): string
    {
        return '' === $section->module() ? self::TAB_GENERAL : $section->module();
    }

    /**
     * Gets the Settings API page id a tab's sections are registered under.
     *
     * `do_settings_sections()` renders every section registered for one page, so each tab is given
     * its own page id and renders only the sections filed under it.
     *
     * @param string $tab The tab id.
     * @return string The page id.
     */
    private function tab_page(string $tab): string
    {
        return self::PAGE_SLUG . '-' . $tab;
    }

    /**
     * Resolves the tab to show, falling back to the first one.
     *
     * @param array<string, string> $tabs The tab labels, keyed by tab id.
     * @return string The tab id.
     */
    private function current_tab(array $tabs): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to show.
        $tab = isset($_GET[self::TAB_ARG]) ? sanitize_key(wp_unslash($_GET[self::TAB_ARG])) : '';

        return isset($tabs[$tab]) ? $tab : self::TAB_GENERAL;
    }

    /**
     * Resolves the tab a form was submitted from, so the result shows on the same screen.
     *
     * @param array<string, string> $tabs The tab labels, keyed by tab id.
     * @return string The tab id, or an empty string when none was submitted.
     */
    private function posted_tab(array $tabs): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by the handler.
        $tab = isset($_POST[self::TAB_ARG]) ? sanitize_key(wp_unslash($_POST[self::TAB_ARG])) : '';

        return isset($tabs[$tab]) ? $tab : '';
    }

    /**
     * Renders a tab's form.
     *
     * Only the sections registered under the tab's page id are output, so saving a tab submits that
     * tab's options alone — the writer ignores everything else.
     *
     * @param string $tab The tab id.
     * @return void
     */
    private function render_form(string $tab): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::SAVE_ACTION));
        printf('<input type="hidden" name="%s" value="%s" />', esc_attr(self::TAB_ARG), esc_attr($tab));
        wp_nonce_field(self::SAVE_ACTION);

        do_settings_sections($this->tab_page($tab));

        submit_button(__('Save settings', 'ai-settings'));

        echo '</form>';
    }

    /**
     * Renders the tab bar.
     *
     * @param array<string, string> $tabs   The tab labels, keyed by tab id.
     * @param string                $active The active tab id.
     * @return void
     */
    private function render_tabs(array $tabs, string $active): void
    {
        printf(
            '<nav class="nav-tab-wrapper wp-clearfix" aria-label="%s">',
            esc_attr__('Secondary menu', 'ai-settings')
        );

        foreach ($tabs as $id => $label) {
            $is_active = $id === $active;

            printf(
                '<a href="%s" class="nav-tab%s"%s>%s</a>',
                esc_url(add_query_arg(self::TAB_ARG, $id, $this->page_url())),
                $is_active ? ' nav-tab-active' : '',
                $is_active ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }

        echo '</nav>';
    }

    /**
     * Renders the master switch and the bulk switches.
     *
     * @return void
     */
    private function render_general_tab(): void
    {
        $this->render_toolbar(self::TAB_GENERAL);
        $this->render_form(self::TAB_GENERAL);
    }

    /**
     * Renders the sections of one module.
     *
     * @param Collection $collection The collected configuration.
     * @param string     $module_id  The module id.
     * @return void
     */
    private function render_module_tab(Collection $collection, string $module_id): void
    {
        $module = $collection->module($module_id);

        if (null !== $module && '' !== $module->description()) {
            printf(
                '<p class="ai-settings-module-description">%s</p>',
                esc_html($module->description())
            );
        }

        $this->render_form($module_id);
    }

    /**
     * Renders the settings screen.
     *
     * @return void
     */
    public function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $collection = $this->plugin->collector()->collect();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';

        $this->render_notices();

        if ($collection->is_empty()) {
            $this->render_unavailable_notice();
            echo '</div>';

            return;
        }

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'These are the settings the WordPress AI plugin owns: its master switch, one switch per feature, and the per-feature provider, model and option overrides.',
                'ai-settings'
            )
        );

        $tabs   = $this->tabs($collection);
        $active = $this->current_tab($tabs);

        $this->render_tabs($tabs, $active);

        switch ($active) {
            case self::TAB_MODELS:
                $this->render_model_section();
                break;

            case self::TAB_EXPORT:
                $this->render_import_export();
                break;

            case self::TAB_GENERAL:
                $this->render_general_tab();
                break;

            default:
                $this->render_module_tab($collection, $active);
                break;
        }

        echo '</div>';
    }

    /**
     * Saves the submitted form.
     *
     * @return void
     */
    public function handle_save(): void
    {
        check_admin_referer(self::SAVE_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'ai-settings'));
        }

        /*
         * Every value is unslashed here and then validated against the option's registered schema by
         * the writer, which is the same validation the AI plugin's own import endpoint applies.
         */
        $raw = array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Writer.
        if (isset($_POST[self::INPUT_NAME]) && is_array($_POST[self::INPUT_NAME])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Writer.
            $raw = wp_unslash($_POST[self::INPUT_NAME]);
        }

        $result = (new Writer($this->plugin->collector()))->save($raw);

        $this->redirect(
            $this->posted_tab($this->tab_list()),
            array(
                'ai_settings_updated'  => $result['updated'],
                'ai_settings_rejected' => count($result['rejected']),
            )
        );
    }

    /**
     * Turns every switch on or off.
     *
     * @return void
     */
    public function handle_bulk(): void
    {
        check_admin_referer(self::BULK_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'ai-settings'));
        }

        $mode = isset($_POST['ai_settings_mode'])
            ? sanitize_key(wp_unslash($_POST['ai_settings_mode']))
            : '';

        $count = (new Writer($this->plugin->collector()))->set_all_enabled('enable' === $mode);

        $this->redirect($this->posted_tab($this->tab_list()), array('ai_settings_bulk' => $count));
    }

    /**
     * Renders one field.
     *
     * @param Field $field The field to render.
     * @return void
     */
    private function render_field(Field $field): void
    {
        $value = get_option($field->name(), $field->default_value());

        switch ($field->kind()) {
            case Field::KIND_BOOL:
                $this->render_bool($field, $value);
                break;

            case Field::KIND_SELECT:
                $this->render_select($field, $value);
                break;

            case Field::KIND_INTEGER:
                $this->render_integer($field, $value);
                break;

            case Field::KIND_STRING:
            default:
                $this->render_text($field, $value);
                break;
        }

        $this->render_notes($field, $value);
    }

    /**
     * Renders a checkbox.
     *
     * The hidden input keeps an unchecked box from being indistinguishable from an untouched one:
     * PHP resolves the duplicate name to the checkbox, so an unchecked box submits "0".
     *
     * @param Field $field The field.
     * @param mixed $value The stored value.
     * @return void
     */
    private function render_bool(Field $field, $value): void
    {
        printf(
            '<input type="hidden" name="%s[%s]" value="0" />',
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name())
        );

        printf(
            '<label for="%s"><input type="checkbox" id="%s" name="%s[%s]" value="1" %s /> %s</label>',
            esc_attr($field->name()),
            esc_attr($field->name()),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name()),
            checked((bool) $value, true, false),
            esc_html__('Enabled', 'ai-settings')
        );
    }

    /**
     * Renders a dropdown.
     *
     * A stored value that is not among the choices is offered as an extra option, so a value written
     * elsewhere is never silently replaced by saving the form.
     *
     * @param Field $field The field.
     * @param mixed $value The stored value.
     * @return void
     */
    private function render_select(Field $field, $value): void
    {
        $current = is_scalar($value) ? (string) $value : '';
        $choices = $field->choices();
        $known   = false;

        foreach ($choices as $choice) {
            if ($choice['value'] === $current) {
                $known = true;
                break;
            }
        }

        if (!$known && '' !== $current) {
            $choices[] = array(
                'value' => $current,
                'label' => $current,
            );
        }

        printf(
            '<select id="%s" name="%s[%s]">',
            esc_attr($field->name()),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name())
        );

        foreach ($choices as $choice) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($choice['value']),
                selected($choice['value'], $current, false),
                esc_html($choice['label'])
            );
        }

        echo '</select>';
    }

    /**
     * Renders a number input.
     *
     * @param Field $field The field.
     * @param mixed $value The stored value.
     * @return void
     */
    private function render_integer(Field $field, $value): void
    {
        echo '<input type="number" class="small-text"';

        printf(
            ' id="%s" name="%s[%s]" value="%s"',
            esc_attr($field->name()),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name()),
            esc_attr(is_scalar($value) ? (string) $value : '')
        );

        $min = $field->min();

        if (null !== $min) {
            printf(' min="%s"', esc_attr((string) $min));
        }

        $max = $field->max();

        if (null !== $max) {
            printf(' max="%s"', esc_attr((string) $max));
        }

        echo ' />';
    }

    /**
     * Renders a text input.
     *
     * @param Field $field The field.
     * @param mixed $value The stored value.
     * @return void
     */
    private function render_text(Field $field, $value): void
    {
        printf(
            '<input type="text" class="regular-text" id="%s" name="%s[%s]" value="%s" />',
            esc_attr($field->name()),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name()),
            esc_attr(is_scalar($value) ? (string) $value : '')
        );
    }

    /**
     * Renders the provider dropdown of a model override.
     *
     * @param Field  $field The developer field.
     * @param string $provider The stored provider id.
     * @param string $context A suffix that keeps element ids unique when the same field is rendered
     *                        in more than one place.
     * @return void
     */
    private function render_provider_select(Field $field, string $provider, string $context = ''): void
    {
        $providers = $this->plugin->collector()->providers();
        $id        = '' === $context ? $field->name() : $field->name() . '-' . $context;

        printf(
            '<select id="%s-provider" name="%s[%s][provider]" data-ai-settings-provider>',
            esc_attr($id),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name())
        );

        printf('<option value="">%s</option>', esc_html__('(AI plugin default)', 'ai-settings'));

        foreach ($providers as $provider_id => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($provider_id),
                selected($provider_id, $provider, false),
                esc_html($label)
            );
        }

        // A provider that is no longer registered still gets an option, so opening the screen and
        // saving never silently drops it.
        if ('' !== $provider && !isset($providers[$provider])) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($provider),
                selected($provider, $provider, false),
                esc_html($provider)
            );
        }

        echo '</select>';
    }

    /**
     * Renders the model input of a model override.
     *
     * The input is what gets submitted. When the provider's models are known, the script in
     * `assets/models.js` turns the field into a dropdown and leaves the input behind it for model
     * ids the provider does not list.
     *
     * @param Field  $field      The developer field.
     * @param string $model      The stored model id.
     * @param string $context    A suffix that keeps element ids unique.
     * @param string $capability The catalog to offer models from, or an empty string to leave the
     *                           field as plain free text.
     * @return void
     */
    private function render_model_input(Field $field, string $model, string $context = '', string $capability = ''): void
    {
        $id = '' === $context ? $field->name() : $field->name() . '-' . $context;

        printf(
            '<input type="text" class="regular-text" id="%s-model" name="%s[%s][model]" value="%s" placeholder="%s"%s />',
            esc_attr($id),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name()),
            esc_attr($model),
            esc_attr__('Model ID', 'ai-settings'),
            '' === $capability ? '' : ' data-ai-settings-model="' . esc_attr($capability) . '"'
        );
    }

    /**
     * Renders the description and state notes under a field.
     *
     * @param Field $field The field.
     * @param mixed $value The stored value.
     * @return void
     */
    private function render_notes(Field $field, $value): void
    {
        if ('' !== $field->description()) {
            printf('<p class="description">%s</p>', esc_html($field->description()));
        }

        if (!$field->is_registered()) {
            printf(
                '<p class="description"><em>%s</em></p>',
                esc_html__(
                    'Not registered with WordPress by the AI plugin — only this screen writes it.',
                    'ai-settings'
                )
            );
        }

        if (!$field->is_toggle() || Collector::GLOBAL_OPTION === $field->name()) {
            return;
        }

        $section = $this->plugin->collector()->collect()->section($field->section());

        if (null === $section || null === $section->effective()) {
            return;
        }

        $stored = (bool) $value;

        if ($stored && !$section->effective()) {
            printf(
                '<p class="description"><strong>%s</strong> %s</p>',
                esc_html__('Saved as on, not running:', 'ai-settings'),
                esc_html__('the master switch is off.', 'ai-settings')
            );
        }

        if (!$stored && $section->effective()) {
            printf(
                '<p class="description"><strong>%s</strong> %s</p>',
                esc_html__('Saved as off, running:', 'ai-settings'),
                esc_html__('code on this site forces it on.', 'ai-settings')
            );
        }
    }

    /**
     * Renders a section's description and current state.
     *
     * @param Section $section The section.
     * @return void
     */
    private function render_section_intro(Section $section): void
    {
        if ('' !== $section->description()) {
            printf('<p>%s</p>', esc_html($section->description()));
        }

        if ($section->is_master() || null === $section->effective()) {
            return;
        }

        printf(
            '<p class="description">%s</p>',
            esc_html(
                $section->effective()
                    ? __('Currently running.', 'ai-settings')
                    : __('Currently not running.', 'ai-settings')
            )
        );
    }

    /**
     * Renders the bulk switches and a link to the AI plugin's own screen.
     *
     * @param string $tab The tab the form belongs to.
     * @return void
     */
    private function render_toolbar(string $tab): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::BULK_ACTION));
        printf('<input type="hidden" name="%s" value="%s" />', esc_attr(self::TAB_ARG), esc_attr($tab));
        wp_nonce_field(self::BULK_ACTION);

        printf(
            '<button type="submit" class="button" name="ai_settings_mode" value="enable">%s</button> ',
            esc_html__('Enable everything', 'ai-settings')
        );
        printf(
            '<button type="submit" class="button" name="ai_settings_mode" value="disable">%s</button> ',
            esc_html__('Disable everything', 'ai-settings')
        );
        printf(
            '<a class="button" href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=ai-wp-admin')),
            esc_html__('Open the AI plugin screen', 'ai-settings')
        );

        echo '</form>';
    }

    /**
     * Renders the import and export controls.
     *
     * @return void
     */
    private function render_import_export(): void
    {
        $export_url = wp_nonce_url(
            add_query_arg('action', Import_Export::EXPORT_ACTION, admin_url('admin-post.php')),
            Import_Export::EXPORT_ACTION
        );

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Export produces the same JSON as the AI plugin\'s own settings export, so the two are interchangeable. Import only writes options the AI plugin registers and rejects anything that fails its schema.',
                'ai-settings'
            )
        );

        printf(
            '<p><a class="button" href="%s">%s</a></p>',
            esc_url($export_url),
            esc_html__('Export settings', 'ai-settings')
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(Import_Export::IMPORT_ACTION));
        printf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr(self::TAB_ARG),
            esc_attr(self::TAB_EXPORT)
        );
        wp_nonce_field(Import_Export::IMPORT_ACTION);

        printf(
            '<input type="file" name="%s" accept="application/json,.json" /> ',
            esc_attr(Import_Export::FILE_FIELD)
        );

        submit_button(__('Import settings', 'ai-settings'), 'secondary', 'submit', false);

        echo '</form>';
    }

    /**
     * Renders the model controls.
     *
     * Two ways to set a model override: push one provider and model onto every feature at once, or
     * edit them feature by feature in a table. Both write the same options.
     *
     * @return void
     */
    private function render_model_section(): void
    {
        $collection = $this->plugin->collector()->collect();

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Each feature can be pointed at a specific provider and model. Leaving every override empty keeps the AI plugin\'s own preference order, which is usually what you want.',
                'ai-settings'
            )
        );

        $catalog = $this->model_catalog($collection);

        $this->render_model_bulk_form($catalog);
        $this->render_model_table($collection, $catalog);
    }

    /**
     * Builds the model catalogs the pickers need.
     *
     * One catalog per capability a feature actually declares, plus the union of them all for the
     * bulk form. The map is handed to the script; a capability that is missing from it is what
     * leaves a field as plain free text.
     *
     * @param Collection $collection The collected configuration.
     * @return array<string, array<string, array{name: string, models: array<string, string>}>> The
     *         catalogs, keyed by capability, with {@see Model_Catalog::ALL} holding the union.
     */
    private function model_catalog(Collection $collection): array
    {
        $capabilities = array();

        foreach ($collection->sections() as $section) {
            if (!$section->is_master()) {
                $capabilities[$this->plugin->collector()->capability_of($section->id())] = true;
            }
        }

        $catalog = new Model_Catalog();
        $map     = array();

        foreach (array_intersect(Model_Catalog::CAPABILITIES, array_keys($capabilities)) as $capability) {
            $models = $catalog->models($capability);

            if (array() !== $models) {
                $map[$capability] = $models;
            }
        }

        if (array() === $map) {
            return array();
        }

        $map[Model_Catalog::ALL] = $catalog->union(array_keys($map));

        wp_add_inline_script(
            'ai-settings-models',
            'window.aiSettingsModelCatalog='
            . wp_json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
            . 'window.aiSettingsModelI18n='
            . wp_json_encode(
                array(
                    'custom'  => __('Custom…', 'ai-settings'),
                    'default' => __('(AI plugin default)', 'ai-settings'),
                )
            ) . ';',
            'before'
        );

        return $map;
    }

    /**
     * Renders the form that applies one provider and model to every feature.
     *
     * @param array<string, mixed> $catalog The model catalogs, empty when no provider reported any.
     * @return void
     */
    private function render_model_bulk_form(array $catalog): void
    {
        $providers = $this->plugin->collector()->providers();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ai-settings-model-bulk">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::MODEL_ACTION));
        printf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr(self::TAB_ARG),
            esc_attr(self::TAB_MODELS)
        );
        wp_nonce_field(self::MODEL_ACTION);

        printf(
            '<label for="ai-settings-bulk-provider">%s</label>',
            esc_html__('Provider', 'ai-settings')
        );
        echo '<select id="ai-settings-bulk-provider" name="ai_settings_provider" data-ai-settings-provider>';
        printf(
            '<option value="%s">%s</option>',
            esc_attr(self::PROVIDER_KEEP),
            esc_html__('Keep unchanged', 'ai-settings')
        );
        printf('<option value="">%s</option>', esc_html__('(AI plugin default)', 'ai-settings'));

        foreach ($providers as $id => $label) {
            printf('<option value="%s">%s</option>', esc_attr($id), esc_html($label));
        }

        echo '</select> ';

        printf(
            '<label for="ai-settings-bulk-model">%s</label>',
            esc_html__('Model', 'ai-settings')
        );
        printf(
            '<input type="text" id="ai-settings-bulk-model" name="ai_settings_model" value="" placeholder="%s"%s /> ',
            esc_attr__('Leave empty to keep', 'ai-settings'),
            // The bulk form spans every feature, so it offers the union of the capabilities.
            array() === $catalog ? '' : ' data-ai-settings-model="' . esc_attr(Model_Catalog::ALL) . '"'
        );

        printf('<span class="ai-settings-scope">%s</span>', esc_html__('Apply to', 'ai-settings'));
        printf(
            '<label><input type="radio" name="ai_settings_scope" value="all" checked="checked" /> %s</label>',
            esc_html__('All features', 'ai-settings')
        );
        printf(
            '<label><input type="radio" name="ai_settings_scope" value="enabled" /> %s</label> ',
            esc_html__('Only features that are running', 'ai-settings')
        );

        submit_button(__('Apply to all', 'ai-settings'), 'secondary', 'submit', false);

        echo '</form>';
    }

    /**
     * Renders one row per feature for editing model overrides individually.
     *
     * Field names match the main form's, so the submission goes through {@see Writer::save()} and
     * the same schema validation, with no separate write path.
     *
     * @param Collection           $collection The collected configuration.
     * @param array<string, mixed> $catalog    The model catalogs, keyed by capability.
     * @return void
     */
    private function render_model_table(Collection $collection, array $catalog): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::SAVE_ACTION));
        printf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr(self::TAB_ARG),
            esc_attr(self::TAB_MODELS)
        );
        wp_nonce_field(self::SAVE_ACTION);

        echo '<table class="widefat striped ai-settings-model-table"><thead><tr>';
        printf('<th>%s</th>', esc_html__('Feature', 'ai-settings'));
        printf('<th>%s</th>', esc_html__('Provider', 'ai-settings'));
        printf('<th>%s</th>', esc_html__('Model', 'ai-settings'));
        echo '</tr></thead><tbody>';

        $current_module = null;

        foreach ($collection->sections() as $section) {
            if ($section->is_master()) {
                continue;
            }

            $field = $collection->field('wpai_feature_' . $section->id() . '_field_developer');

            if (null === $field) {
                continue;
            }

            if ($section->module() !== $current_module) {
                $current_module = $section->module();
                $module         = $collection->module($current_module);

                printf(
                    '<tr class="ai-settings-model-module"><td colspan="3">%s</td></tr>',
                    esc_html(null === $module ? $current_module : $module->label())
                );
            }

            $config   = get_option($field->name(), array());
            $config   = is_array($config) ? $config : array();
            $provider = is_scalar($config['provider'] ?? null) ? (string) $config['provider'] : '';
            $model    = is_scalar($config['model'] ?? null) ? (string) $config['model'] : '';

            // A feature whose capability carries no models — or one that uses none at all — keeps
            // the plain text input.
            $capability = $this->plugin->collector()->capability_of($section->id());

            if (!isset($catalog[$capability])) {
                $capability = '';
            }

            echo '<tr>';
            printf('<td>%s</td><td>', esc_html($section->label()));
            $this->render_provider_select($field, $provider);
            echo '</td><td>';
            $this->render_model_input($field, $model, '', $capability);
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        submit_button(__('Save models', 'ai-settings'));

        echo '</form>';
    }

    /**
     * Pushes one provider and model onto every feature.
     *
     * @return void
     */
    public function handle_bulk_model(): void
    {
        check_admin_referer(self::MODEL_ACTION);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'ai-settings'));
        }

        $provider = isset($_POST['ai_settings_provider'])
            ? sanitize_text_field(wp_unslash($_POST['ai_settings_provider']))
            : self::PROVIDER_KEEP;
        $model    = isset($_POST['ai_settings_model'])
            ? sanitize_text_field(wp_unslash($_POST['ai_settings_model']))
            : '';
        $scope    = isset($_POST['ai_settings_scope'])
            ? sanitize_key(wp_unslash($_POST['ai_settings_scope']))
            : 'all';

        $count = (new Writer($this->plugin->collector()))->set_model_for_all(
            self::PROVIDER_KEEP === $provider ? null : $provider,
            $model,
            'enabled' === $scope
        );

        $this->redirect($this->posted_tab($this->tab_list()), array('ai_settings_models' => $count));
    }

    /**
     * Renders the notice explaining that the AI plugin is not providing any options.
     *
     * @return void
     */
    private function render_unavailable_notice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses_post(
                sprintf(
                    /* translators: %s: URL of the WordPress AI plugin on WordPress.org. */
                    __(
                        'No AI plugin options were found. Install and activate the <a href="%s">AI plugin</a>, then reload this page.',
                        'ai-settings'
                    ),
                    esc_url('https://wordpress.org/plugins/ai/')
                )
            )
        );
    }

    /**
     * Renders the result notices carried over from the redirect.
     *
     * @return void
     */
    private function render_notices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags.
        if (isset($_GET['ai_settings_updated'])) {
            $updated = (int) $_GET['ai_settings_updated'];

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of settings. */
                        _n('%d setting saved.', '%d settings saved.', $updated, 'ai-settings'),
                        $updated
                    )
                )
            );
        }

        if (isset($_GET['ai_settings_bulk'])) {
            $bulk = (int) $_GET['ai_settings_bulk'];

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of switches. */
                        _n('%d switch updated.', '%d switches updated.', $bulk, 'ai-settings'),
                        $bulk
                    )
                )
            );
        }

        if (isset($_GET['ai_settings_models'])) {
            $models = (int) $_GET['ai_settings_models'];

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of features. */
                        _n(
                            '%d model override updated.',
                            '%d model overrides updated.',
                            $models,
                            'ai-settings'
                        ),
                        $models
                    )
                )
            );
        }

        if (!empty($_GET['ai_settings_rejected'])) {
            $rejected = (int) $_GET['ai_settings_rejected'];

            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of rejected settings. */
                        _n(
                            '%d setting was rejected because its value is invalid.',
                            '%d settings were rejected because their values are invalid.',
                            $rejected,
                            'ai-settings'
                        ),
                        $rejected
                    )
                )
            );
        }

        if (isset($_GET['ai_settings_error'])) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(
                    sanitize_text_field(wp_unslash((string) $_GET['ai_settings_error']))
                )
            );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Redirects back to the settings screen with result flags.
     *
     * @param string                    $tab  The tab to return to, or an empty string to leave the
     *                                        screen on its default tab.
     * @param array<string, int|string> $args The query arguments to append.
     * @return void
     */
    private function redirect(string $tab, array $args): void
    {
        if ('' !== $tab) {
            $args[self::TAB_ARG] = $tab;
        }

        wp_safe_redirect(add_query_arg($args, $this->page_url()));
        exit;
    }

    /**
     * Gets the settings screen URL.
     *
     * @return string The URL.
     */
    private function page_url(): string
    {
        return add_query_arg('page', self::PAGE_SLUG, admin_url('options-general.php'));
    }
}
