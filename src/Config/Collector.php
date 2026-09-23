<?php

/**
 * Discovers the AI plugin's configuration options.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * Collects every configuration option the WordPress AI plugin owns.
 *
 * The AI plugin registers all of its options in a single settings group, so
 * `get_registered_settings()` is the authoritative list — the same source its own settings
 * import/export controller uses. Feature labels, descriptions and custom settings fields come from
 * the plugin's feature registry, which does not expose a global accessor and is therefore captured
 * from the `wpai_register_features` action (see {@see \AISettings\Plugin}).
 *
 * Everything here is defensive: the AI plugin is explicitly experimental, so any registry method
 * may disappear. When that happens the screen still renders from the registered options alone.
 */
final class Collector
{
    /**
     * The settings group every AI plugin option is registered in.
     *
     * @var string
     */
    public const GROUP = 'ai_experiments';

    /**
     * The option holding the plugin-wide master switch.
     *
     * @var string
     */
    public const GLOBAL_OPTION = 'wpai_features_enabled';

    /**
     * The section id used for the master switch.
     *
     * @var string
     */
    public const GLOBAL_SECTION = 'global';

    /**
     * Matches a feature's own on/off switch.
     *
     * @var string
     */
    private const TOGGLE_PATTERN = '/^wpai_feature_(?P<id>.+?)_enabled$/';

    /**
     * Matches a feature's developer provider/model override.
     *
     * Checked before {@see self::FIELD_PATTERN}, which would also match it.
     *
     * @var string
     */
    private const DEVELOPER_PATTERN = '/^wpai_feature_(?P<id>.+?)_field_developer$/';

    /**
     * Matches any other feature-specific settings field.
     *
     * @var string
     */
    private const FIELD_PATTERN = '/^wpai_feature_(?P<id>.+?)_field_(?P<field>.+)$/';

    /**
     * The places AI features take effect, in the order they are shown.
     *
     * Declared as a method rather than a constant so the labels can be translated.
     *
     * @return array<string, Module> The modules, keyed by id.
     */
    private function modules(): array
    {
        return array(
            'generate' => new Module(
                'generate',
                __('Content generation', 'bestonys-ai-settings'),
                __('Generate or rework the body of a post.', 'bestonys-ai-settings')
            ),
            'assist'   => new Module(
                'assist',
                __('Editing assistance', 'bestonys-ai-settings'),
                __('Help while you are writing, in the block editor.', 'bestonys-ai-settings')
            ),
            'seo'      => new Module(
                'seo',
                __('SEO and taxonomy', 'bestonys-ai-settings'),
                __('Metadata, permalinks and taxonomy suggestions.', 'bestonys-ai-settings')
            ),
            'media'    => new Module(
                'media',
                __('Media', 'bestonys-ai-settings'),
                __('Attachments and images.', 'bestonys-ai-settings')
            ),
            'comments' => new Module(
                'comments',
                __('Comments', 'bestonys-ai-settings'),
                __('Moderation and replies.', 'bestonys-ai-settings')
            ),
            'site'     => new Module(
                'site',
                __('Site administration', 'bestonys-ai-settings'),
                __('Behaviour administrators configure for the whole site.', 'bestonys-ai-settings')
            ),
            'other'    => new Module(
                'other',
                __('Other', 'bestonys-ai-settings'),
                __("Features this version of Bestony's AI Settings does not know yet.", 'bestonys-ai-settings')
            ),
        );
    }

    /**
     * Maps each module to the AI plugin features it holds.
     *
     * A feature that is not listed here — one added by a future AI plugin release — lands in the
     * "other" module rather than disappearing.
     *
     * @return array<string, array<int, string>> Module id to feature ids.
     */
    private function feature_modules(): array
    {
        return array(
            'generate' => array(
                'title-generation',
                'excerpt-generation',
                'summarization',
                'content-resizing',
                'content-translation',
            ),
            'assist'   => array(
                'type-ahead',
                'editorial-notes',
                'editorial-updates',
            ),
            'seo'      => array(
                'meta-description',
                'slug-generation',
                'content-classification',
            ),
            'media'    => array(
                'alt-text-generation',
                'image-generation',
            ),
            'comments' => array(
                'comment-moderation',
                'suggest-reply',
            ),
            'site'     => array(
                'abilities-explorer',
                'custom-abilities',
                'ai-request-logging',
                'connector-approval',
                'key-encryption',
            ),
        );
    }

    /**
     * Gets the module a feature belongs to.
     *
     * @param string $feature_id The feature id.
     * @return string The module id, or `other` when the feature is not mapped.
     */
    private function module_of(string $feature_id): string
    {
        foreach ($this->feature_modules() as $module_id => $feature_ids) {
            if (in_array($feature_id, $feature_ids, true)) {
                return $module_id;
            }
        }

        return 'other';
    }

    /**
     * Narrows the module list to the ones that actually hold a section.
     *
     * @param array<string, Section> $sections The sections in display order.
     * @return array<string, Module> The used modules, in their defined order.
     */
    private function used_modules(array $sections): array
    {
        $used = array();

        foreach ($sections as $section) {
            if ('' !== $section->module()) {
                $used[ $section->module() ] = true;
            }
        }

        return array_intersect_key($this->modules(), $used);
    }

    /**
     * Options the AI plugin reads but never registers.
     *
     * These are written and read with `get_option()`/`update_option()` under the standard
     * `wpai_feature_{id}_field_{name}` naming, but no `register_setting()` call exists for them, so
     * they cannot be discovered from `get_registered_settings()` and there is no schema to validate
     * against. Only options that are still unregistered are added, so the list silently stops
     * mattering if the AI plugin ever registers them.
     *
     * Declared as a method rather than a constant so the labels can be translated.
     *
     * @return array<string, array<int, array<string, mixed>>> Definitions keyed by feature id.
     */
    private function unregistered_definitions(): array
    {
        return array(
            'type-ahead' => array(
                array(
                    'field'       => 'mode',
                    'label'       => __('Completion mode', 'bestonys-ai-settings'),
                    'kind'        => Field::KIND_SELECT,
                    'description' => __(
                        'Smart suggests after any pause; Word only after a sentence ends.',
                        'bestonys-ai-settings'
                    ),
                    'default'     => 'smart',
                    'choices'     => array(
                        array(
                            'value' => 'smart',
                            'label' => __('Smart', 'bestonys-ai-settings'),
                        ),
                        array(
                            'value' => 'word',
                            'label' => __('Word', 'bestonys-ai-settings'),
                        ),
                    ),
                ),
                array(
                    'field'       => 'delay',
                    'label'       => __('Trigger delay (ms)', 'bestonys-ai-settings'),
                    'kind'        => Field::KIND_INTEGER,
                    'description' => __(
                        'How long typing must pause before a suggestion is requested.',
                        'bestonys-ai-settings'
                    ),
                    'default'     => 500,
                    'min'         => 200,
                ),
                array(
                    'field'       => 'confidence',
                    'label'       => __('Minimum confidence (%)', 'bestonys-ai-settings'),
                    'kind'        => Field::KIND_INTEGER,
                    'description' => __(
                        'Suggestions scoring below this are discarded.',
                        'bestonys-ai-settings'
                    ),
                    'default'     => 70,
                    'min'         => 0,
                    'max'         => 100,
                ),
                array(
                    'field'       => 'max_words',
                    'label'       => __('Maximum words', 'bestonys-ai-settings'),
                    'kind'        => Field::KIND_INTEGER,
                    'description' => __(
                        'Upper bound on the length of a suggestion.',
                        'bestonys-ai-settings'
                    ),
                    'default'     => 20,
                    'min'         => 1,
                    'max'         => 50,
                ),
                array(
                    'field'       => 'headings',
                    'label'       => __('Suggest while editing headings', 'bestonys-ai-settings'),
                    'kind'        => Field::KIND_BOOL,
                    'description' => __(
                        'Off by default: suggestions are limited to paragraph blocks.',
                        'bestonys-ai-settings'
                    ),
                    'default'     => false,
                ),
            ),
        );
    }

    /**
     * The AI plugin's feature registry, when it was captured.
     *
     * Intentionally untyped: the class belongs to the AI plugin and may be renamed between versions.
     *
     * @var object|null
     */
    private $registry;

    /**
     * The collected result, built once per request.
     *
     * @var Collection|null
     */
    private ?Collection $cache = null;

    /**
     * Constructor.
     *
     * @param object|null $registry The AI plugin's feature registry, when available.
     */
    public function __construct($registry = null)
    {
        $this->registry = is_object($registry) ? $registry : null;
    }

    /**
     * Collects every configuration option, in display order.
     *
     * @return Collection The collected sections and fields.
     */
    public function collect(): Collection
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $registered = $this->registered_options();
        $features   = $this->features();
        $metadata   = $this->feature_field_metadata($features);

        $sections = array();
        $fields   = array();

        if (isset($registered[self::GLOBAL_OPTION])) {
            $sections[self::GLOBAL_SECTION] = new Section(
                self::GLOBAL_SECTION,
                __('AI plugin master switch', 'bestonys-ai-settings'),
                __(
                    'Every feature below needs this switch and its own switch to be on. A feature reports as inactive until both are enabled.',
                    'bestonys-ai-settings'
                ),
                '',
                true
            );

            $fields[self::GLOBAL_OPTION] = $this->build_field(
                self::GLOBAL_OPTION,
                self::GLOBAL_SECTION,
                $registered[self::GLOBAL_OPTION],
                __('Enable AI features', 'bestonys-ai-settings'),
                $metadata
            );
        }

        foreach ($registered as $name => $args) {
            if (self::GLOBAL_OPTION === $name) {
                continue;
            }

            $parsed = $this->parse_name($name);
            if (null === $parsed) {
                continue;
            }

            $feature_id = $parsed['feature'];

            if (!isset($sections[$feature_id])) {
                $sections[$feature_id] = $this->build_section($feature_id, $features);
            }

            $fields[$name] = $this->build_field(
                $name,
                $feature_id,
                $args,
                $this->default_label($parsed),
                $metadata
            );
        }

        foreach ($this->unregistered_fields($sections, $features) as $name => $field) {
            $fields[$name] = $field;
        }

        $sections = $this->sort_sections($sections);
        $fields   = $this->sort_fields($fields, $sections);
        $modules  = $this->used_modules($sections);

        $this->cache = new Collection($modules, $sections, $fields);

        return $this->cache;
    }

    /**
     * Whether the AI plugin is exposing any configuration.
     *
     * @return bool True when at least one managed option is registered.
     */
    public function is_available(): bool
    {
        return array() !== $this->registered_options();
    }

    /**
     * Gets the AI providers that can be named in a provider/model override.
     *
     * @return array<string, string> Provider id to display name.
     */
    public function providers(): array
    {
        if (!function_exists('wp_get_connectors')) {
            return array();
        }

        $providers = array();

        foreach ((array) wp_get_connectors() as $connector_id => $data) {
            if (!is_string($connector_id) || !is_array($data)) {
                continue;
            }

            if ('ai_provider' !== ($data['type'] ?? '')) {
                continue;
            }

            $name                     = $data['name'] ?? '';
            $providers[$connector_id] = is_string($name) && '' !== $name ? $name : $connector_id;
        }

        return $providers;
    }

    /**
     * Gets the capability a feature declared.
     *
     * The AI plugin files each feature under the capability its model has to serve —
     * `text_generation`, `image_generation`, `vision`, or `none` for the features that use no model
     * at all. It decides which models the feature's model override can be pointed at.
     *
     * @param string $feature_id The feature id.
     * @return string The capability, defaulting to `text_generation` when the feature does not say.
     */
    public function capability_of(string $feature_id): string
    {
        $feature = $this->features()[$feature_id] ?? null;

        if (!is_object($feature) || !method_exists($feature, 'get_capability')) {
            return 'text_generation';
        }

        $capability = $feature->get_capability();

        return is_string($capability) && '' !== $capability ? $capability : 'text_generation';
    }

    /**
     * Gets the AI plugin's registered options.
     *
     * @return array<string, array<string, mixed>> Option name to its registered arguments.
     */
    private function registered_options(): array
    {
        if (!function_exists('get_registered_settings')) {
            return array();
        }

        $managed = array();

        foreach (get_registered_settings() as $name => $args) {
            if (!is_string($name) || !is_array($args)) {
                continue;
            }

            if (self::GROUP !== ($args['group'] ?? '')) {
                continue;
            }

            $managed[$name] = $args;
        }

        return $managed;
    }

    /**
     * Gets the AI plugin's features, keyed by feature id.
     *
     * @return array<string, object> The feature instances.
     */
    private function features(): array
    {
        if (null === $this->registry || !method_exists($this->registry, 'get_all_features')) {
            return array();
        }

        $features = $this->registry->get_all_features();

        return is_array($features) ? $features : array();
    }

    /**
     * Indexes the settings fields the features declare, by full option name.
     *
     * @param array<string, object> $features The feature instances.
     * @return array<string, array<string, mixed>> Option name to field metadata.
     */
    private function feature_field_metadata(array $features): array
    {
        $metadata = array();

        foreach ($features as $feature) {
            if (!is_object($feature) || !method_exists($feature, 'get_settings_fields_metadata')) {
                continue;
            }

            $fields = $feature->get_settings_fields_metadata();
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                if (is_array($field) && isset($field['id']) && is_string($field['id'])) {
                    $metadata[$field['id']] = $field;
                }
            }
        }

        return $metadata;
    }

    /**
     * Builds a section for a feature.
     *
     * @param string                $feature_id The feature id.
     * @param array<string, object> $features The feature instances.
     * @return Section The section.
     */
    private function build_section(string $feature_id, array $features): Section
    {
        $feature     = $features[$feature_id] ?? null;
        $label       = $feature_id;
        $description = '';
        $effective   = null;

        if (is_object($feature)) {
            $feature_label = $this->call_string($feature, 'get_label');
            if ('' !== $feature_label) {
                $label = $feature_label;
            }

            $description = $this->call_string($feature, 'get_description');

            if (method_exists($feature, 'is_enabled')) {
                $effective = (bool) $feature->is_enabled();
            }
        }

        return new Section(
            $feature_id,
            $label,
            $description,
            $this->module_of($feature_id),
            false,
            $effective
        );
    }

    /**
     * Calls a method that is expected to return a string.
     *
     * @param object $object The object to call on.
     * @param string $method The method name.
     * @return string The returned string, or an empty string when unusable.
     */
    private function call_string($object, string $method): string
    {
        if (!method_exists($object, $method)) {
            return '';
        }

        $value = $object->{$method}();

        return is_string($value) ? $value : '';
    }

    /**
     * Builds a field from a registered option.
     *
     * @param string                              $name The option name.
     * @param string                              $section_id The owning section id.
     * @param array<string, mixed>                $args The option's registered arguments.
     * @param string                              $label The fallback label.
     * @param array<string, array<string, mixed>> $metadata Feature field metadata, by option name.
     * @return Field The field.
     */
    private function build_field(string $name, string $section_id, array $args, string $label, array $metadata): Field
    {
        $schema  = $this->schema_of($args);
        $meta    = $metadata[$name] ?? array();
        $kind    = $this->kind_from_metadata($meta);
        $choices = $this->choices_from_metadata($meta);

        if ('' !== ($meta['label'] ?? '')) {
            $label = (string) $meta['label'];
        }

        if (null === $kind) {
            $kind = $this->kind_from_schema($schema);
        }

        if (array() === $choices) {
            $choices = $this->choices_from_schema($schema);
        }

        $min = $meta['isValid']['min'] ?? $schema['minimum'] ?? null;
        $max = $meta['isValid']['max'] ?? $schema['maximum'] ?? null;

        return new Field(
            array(
                'name'              => $name,
                'label'             => $label,
                'kind'              => $kind,
                'section'           => $section_id,
                'description'       => '',
                'default'           => $args['default'] ?? null,
                'choices'           => $choices,
                'min'               => $min,
                'max'               => $max,
                'schema'            => $schema,
                'sanitize_callback' => $args['sanitize_callback'] ?? null,
                'registered'        => true,
            )
        );
    }

    /**
     * Adds the options the AI plugin reads but never registers.
     *
     * @param array<string, Section> $sections The sections collected so far.
     * @param array<string, object>  $features The feature instances.
     * @return array<string, Field> The extra fields, keyed by option name.
     */
    private function unregistered_fields(array $sections, array $features): array
    {
        $fields = array();

        foreach ($this->unregistered_definitions() as $feature_id => $definitions) {
            // Only add these when the AI plugin actually ships the feature; a registry that could
            // not be captured is treated as "unknown", which is the safer default here.
            if (!isset($sections[$feature_id]) || !isset($features[$feature_id])) {
                continue;
            }

            foreach ($definitions as $definition) {
                $name = 'wpai_feature_' . $feature_id . '_field_' . $definition['field'];

                if ($this->is_registered_option($name)) {
                    // The AI plugin registers it after all: the normal path already covered it.
                    continue;
                }

                $fields[$name] = new Field(
                    array(
                        'name'        => $name,
                        'label'       => (string) $definition['label'],
                        'kind'        => (string) $definition['kind'],
                        'section'     => $feature_id,
                        'description' => (string) ($definition['description'] ?? ''),
                        'default'     => $definition['default'] ?? null,
                        'choices'     => (array) ($definition['choices'] ?? array()),
                        'min'         => $definition['min'] ?? null,
                        'max'         => $definition['max'] ?? null,
                        'registered'  => false,
                    )
                );
            }
        }

        return $fields;
    }

    /**
     * Whether the AI plugin registers an option.
     *
     * @param string $name The option name.
     * @return bool True when the option is registered.
     */
    private function is_registered_option(string $name): bool
    {
        $registered = get_registered_settings();

        return isset($registered[$name]) && is_array($registered[$name])
            && self::GROUP === ($registered[$name]['group'] ?? '');
    }

    /**
     * Splits an option name into its feature and role.
     *
     * @param string $name The option name.
     * @return array{feature: string, role: string, field?: string}|null The parsed parts, or null.
     */
    private function parse_name(string $name): ?array
    {
        if (1 === preg_match(self::DEVELOPER_PATTERN, $name, $matches)) {
            return array(
                'feature' => $matches['id'],
                'role'    => 'developer',
            );
        }

        if (1 === preg_match(self::TOGGLE_PATTERN, $name, $matches)) {
            return array(
                'feature' => $matches['id'],
                'role'    => 'toggle',
            );
        }

        if (1 === preg_match(self::FIELD_PATTERN, $name, $matches)) {
            return array(
                'feature' => $matches['id'],
                'role'    => 'field',
                'field'   => $matches['field'],
            );
        }

        return null;
    }

    /**
     * Gets the label to use when the AI plugin declares none.
     *
     * @param array{feature: string, role: string, field?: string} $parsed The parsed option name.
     * @return string The label.
     */
    private function default_label(array $parsed): string
    {
        if ('toggle' === $parsed['role']) {
            return __('Enable', 'bestonys-ai-settings');
        }

        if ('developer' === $parsed['role']) {
            return __('Provider and model override', 'bestonys-ai-settings');
        }

        return (string) ($parsed['field'] ?? '');
    }

    /**
     * Extracts an option's REST schema.
     *
     * Mirrors the AI plugin's own import controller so both read the registered settings the same way.
     *
     * @param array<string, mixed> $args The option's registered arguments.
     * @return array<string, mixed> The schema.
     */
    private function schema_of(array $args): array
    {
        $show_in_rest = $args['show_in_rest'] ?? false;

        if (is_array($show_in_rest) && isset($show_in_rest['schema']) && is_array($show_in_rest['schema'])) {
            return $show_in_rest['schema'];
        }

        return array('type' => (string) ($args['type'] ?? 'string'));
    }

    /**
     * Determines a field kind from the AI plugin's own field metadata.
     *
     * @param array<string, mixed> $meta The field metadata.
     * @return string|null One of the Field::KIND_* constants, or null when the metadata is silent.
     */
    private function kind_from_metadata(array $meta): ?string
    {
        $type    = (string) ($meta['type'] ?? '');
        $choices = $this->choices_from_metadata($meta);

        switch ($type) {
            case 'boolean':
                return Field::KIND_BOOL;
            case 'integer':
            case 'number':
                return Field::KIND_INTEGER;
            case 'text':
            case 'string':
                return array() === $choices ? Field::KIND_STRING : Field::KIND_SELECT;
            case '':
                return null;
            default:
                return array() === $choices ? null : Field::KIND_SELECT;
        }
    }

    /**
     * Determines a field kind from a REST schema.
     *
     * @param array<string, mixed> $schema The option's REST schema.
     * @return string One of the Field::KIND_* constants.
     */
    private function kind_from_schema(array $schema): string
    {
        $type = (string) ($schema['type'] ?? 'string');

        if ('boolean' === $type) {
            return Field::KIND_BOOL;
        }

        if ('integer' === $type) {
            return Field::KIND_INTEGER;
        }

        // Every AI plugin option is a switch (handled above), a fixed choice, a scalar, or the
        // feature's `{provider, model}` override — the only object-shaped one.
        if ('object' === $type) {
            return Field::KIND_PROVIDER_MODEL;
        }

        if (array() !== $this->choices_from_schema($schema)) {
            return Field::KIND_SELECT;
        }

        return Field::KIND_STRING;
    }

    /**
     * Extracts the choices from field metadata.
     *
     * @param array<string, mixed> $meta The field metadata.
     * @return array<int, array{value: string, label: string}> The choices.
     */
    private function choices_from_metadata(array $meta): array
    {
        $elements = $meta['elements'] ?? null;

        if (!is_array($elements)) {
            return array();
        }

        $choices = array();

        foreach ($elements as $element) {
            if (!is_array($element) || !isset($element['value']) || !is_scalar($element['value'])) {
                continue;
            }

            $value     = (string) $element['value'];
            $label     = isset($element['label']) && is_scalar($element['label'])
                ? (string) $element['label']
                : $value;
            $choices[] = array(
                'value' => $value,
                'label' => $label,
            );
        }

        return $choices;
    }

    /**
     * Extracts the choices from a REST schema's enum.
     *
     * @param array<string, mixed> $schema The REST schema.
     * @return array<int, array{value: string, label: string}> The choices.
     */
    private function choices_from_schema(array $schema): array
    {
        $enum = $schema['enum'] ?? null;

        if (!is_array($enum)) {
            return array();
        }

        $choices = array();

        foreach ($enum as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $choices[] = array(
                'value' => (string) $value,
                'label' => (string) $value,
            );
        }

        return $choices;
    }

    /**
     * Orders sections: master switch first, then by module, then by label.
     *
     * @param array<string, Section> $sections The sections to sort.
     * @return array<string, Section> The sorted sections.
     */
    private function sort_sections(array $sections): array
    {
        $order = array_flip(array_keys($this->modules()));

        uasort(
            $sections,
            static function (Section $a, Section $b) use ($order): int {
                if ($a->is_master() !== $b->is_master()) {
                    return $a->is_master() ? -1 : 1;
                }

                $order_a = $order[ $a->module() ] ?? PHP_INT_MAX;
                $order_b = $order[ $b->module() ] ?? PHP_INT_MAX;

                if ($order_a !== $order_b) {
                    return $order_a <=> $order_b;
                }

                $by_label = strcasecmp($a->label(), $b->label());

                // uasort is not stable, so ties are broken explicitly to keep the screen steady.
                return 0 !== $by_label ? $by_label : strcmp($a->id(), $b->id());
            }
        );

        return $sections;
    }

    /**
     * Orders fields to follow their sections.
     *
     * @param array<string, Field>   $fields The fields to sort.
     * @param array<string, Section> $sections The sorted sections.
     * @return array<string, Field> The sorted fields.
     */
    private function sort_fields(array $fields, array $sections): array
    {
        $ordered = array();

        foreach (array_keys($sections) as $section_id) {
            foreach ($fields as $name => $field) {
                if ($field->section() === $section_id) {
                    $ordered[$name] = $field;
                }
            }
        }

        return $ordered;
    }
}
