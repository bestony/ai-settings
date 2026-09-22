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
use AISettings\Config\Module;
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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
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
     * These are used for rendering only; saving goes through {@see self::handle_save()}.
     *
     * @return void
     */
    public function register_fields(): void
    {
        if (!$this->plugin->collector()->is_available()) {
            return;
        }

        $collection     = $this->plugin->collector()->collect();
        $current_module = null;

        foreach ($collection->sections() as $section) {
            // A heading for each module, registered ahead of the sections filed under it.
            if ($section->module() !== $current_module) {
                $current_module = $section->module();
                $module         = $collection->module($current_module);

                if (null !== $module) {
                    add_settings_section(
                        'ai-settings-module-' . $module->id(),
                        '',
                        function () use ($module): void {
                            $this->render_module_intro($module);
                        },
                        self::PAGE_SLUG
                    );
                }
            }

            add_settings_section(
                $section->id(),
                $section->label(),
                function () use ($section): void {
                    $this->render_section_intro($section);
                },
                self::PAGE_SLUG
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
                self::PAGE_SLUG,
                $field->section()
            );
        }
    }

    /**
     * Adds the screen's own styles, on this screen only.
     *
     * @param string $hook_suffix The current admin page.
     * @return void
     */
    public function enqueue_styles($hook_suffix): void
    {
        if ('settings_page_' . self::PAGE_SLUG !== $hook_suffix) {
            return;
        }

        wp_register_style('ai-settings', false, array(), AISETTINGS_VERSION);
        wp_enqueue_style('ai-settings');
        wp_add_inline_style(
            'ai-settings',
            '.ai-settings-module{margin:2em 0 .2em;padding-bottom:.3em;border-bottom:1px solid #c3c4c7;font-size:1.15em}'
            . '.ai-settings-module-description{margin:.2em 0 1em;color:#646970}'
            . '.ai-settings-model-bulk{margin:1em 0;padding:1em;background:#fff;border:1px solid #c3c4c7}'
            . '.ai-settings-model-bulk label{margin-right:.3em}'
            . '.ai-settings-scope{margin-left:1em}'
            . '.ai-settings-model-table{margin-top:1em}'
            . '.ai-settings-model-table .ai-settings-model-module td{background:#f0f0f1;font-weight:600}'
        );
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

        $this->render_toolbar();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::SAVE_ACTION));
        wp_nonce_field(self::SAVE_ACTION);
        do_settings_sections(self::PAGE_SLUG);
        submit_button(__('Save settings', 'ai-settings'));
        echo '</form>';

        $this->render_model_section();
        $this->render_import_export();

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

        $this->redirect(array('ai_settings_bulk' => $count));
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
            '<select id="%s-provider" name="%s[%s][provider]">',
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
     * @param Field  $field The developer field.
     * @param string $model The stored model id.
     * @param string $context A suffix that keeps element ids unique.
     * @return void
     */
    private function render_model_input(Field $field, string $model, string $context = ''): void
    {
        $id = '' === $context ? $field->name() : $field->name() . '-' . $context;

        printf(
            '<input type="text" class="regular-text" id="%s-model" name="%s[%s][model]" value="%s" placeholder="%s" />',
            esc_attr($id),
            esc_attr(self::INPUT_NAME),
            esc_attr($field->name()),
            esc_attr($model),
            esc_attr__('Model ID', 'ai-settings')
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
     * @return void
     */
    private function render_toolbar(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0;">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::BULK_ACTION));
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

        echo '<hr />';
        printf('<h2>%s</h2>', esc_html__('Import and export', 'ai-settings'));

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
        wp_nonce_field(Import_Export::IMPORT_ACTION);

        printf(
            '<input type="file" name="%s" accept="application/json,.json" /> ',
            esc_attr(Import_Export::FILE_FIELD)
        );

        submit_button(__('Import settings', 'ai-settings'), 'secondary', 'submit', false);

        echo '</form>';
    }

    /**
     * Renders a module heading.
     *
     * The section it belongs to carries no fields of its own, so the heading is all it outputs.
     *
     * @param Module $module The module.
     * @return void
     */
    private function render_module_intro(Module $module): void
    {
        printf('<h2 class="ai-settings-module">%s</h2>', esc_html($module->label()));

        if ('' !== $module->description()) {
            printf(
                '<p class="ai-settings-module-description">%s</p>',
                esc_html($module->description())
            );
        }
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
        echo '<hr />';
        printf('<h2>%s</h2>', esc_html__('Models', 'ai-settings'));

        printf(
            '<p class="description">%s</p>',
            esc_html__(
                'Each feature can be pointed at a specific provider and model. Leaving every override empty keeps the AI plugin\'s own preference order, which is usually what you want.',
                'ai-settings'
            )
        );

        $this->render_model_bulk_form();
        $this->render_model_table($this->plugin->collector()->collect());
    }

    /**
     * Renders the form that applies one provider and model to every feature.
     *
     * @return void
     */
    private function render_model_bulk_form(): void
    {
        $providers = $this->plugin->collector()->providers();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ai-settings-model-bulk">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::MODEL_ACTION));
        wp_nonce_field(self::MODEL_ACTION);

        printf(
            '<label for="ai-settings-bulk-provider">%s</label>',
            esc_html__('Provider', 'ai-settings')
        );
        echo '<select id="ai-settings-bulk-provider" name="ai_settings_provider">';
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
            '<input type="text" id="ai-settings-bulk-model" name="ai_settings_model" value="" placeholder="%s" /> ',
            esc_attr__('Leave empty to keep', 'ai-settings')
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
     * @param Collection $collection The collected configuration.
     * @return void
     */
    private function render_model_table(Collection $collection): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        printf('<input type="hidden" name="action" value="%s" />', esc_attr(self::SAVE_ACTION));
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

            echo '<tr>';
            printf('<td>%s</td><td>', esc_html($section->label()));
            $this->render_provider_select($field, $provider);
            echo '</td><td>';
            $this->render_model_input($field, $model);
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

        $this->redirect(array('ai_settings_models' => $count));
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
     * @param array<string, int|string> $args The query arguments to append.
     * @return void
     */
    private function redirect(array $args): void
    {
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
