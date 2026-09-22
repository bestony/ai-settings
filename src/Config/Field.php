<?php

/**
 * Single configuration field.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * One editable configuration option belonging to the WordPress AI plugin.
 *
 * Instances are built by {@see Collector} from the options the AI plugin registers in its
 * `ai_experiments` settings group, enriched with the metadata its feature registry exposes.
 */
final class Field
{
    /**
     * An on/off switch, rendered as a checkbox.
     *
     * @var string
     */
    public const KIND_BOOL = 'bool';

    /**
     * A fixed list of choices, rendered as a dropdown.
     *
     * @var string
     */
    public const KIND_SELECT = 'select';

    /**
     * A whole number, rendered as a number input.
     *
     * @var string
     */
    public const KIND_INTEGER = 'integer';

    /**
     * Free-form text, rendered as a text input.
     *
     * @var string
     */
    public const KIND_STRING = 'string';

    /**
     * A `{provider, model}` pair, rendered as a dropdown plus a text input.
     *
     * @var string
     */
    public const KIND_PROVIDER_MODEL = 'provider_model';

    /**
     * The full option name, e.g. `wpai_feature_excerpt-generation_enabled`.
     *
     * @var string
     */
    private string $name;

    /**
     * The label shown next to the control.
     *
     * @var string
     */
    private string $label;

    /**
     * One of the KIND_* constants.
     *
     * @var string
     */
    private string $kind;

    /**
     * The id of the section this field belongs to.
     *
     * @var string
     */
    private string $section;

    /**
     * A sentence explaining what the option does.
     *
     * @var string
     */
    private string $description;

    /**
     * The value used when the option has never been saved.
     *
     * @var mixed
     */
    private $default;

    /**
     * The allowed values as `array( array( 'value' => ..., 'label' => ... ), ... )`.
     *
     * @var array<int, array{value: string, label: string}>
     */
    private array $choices;

    /**
     * The lowest accepted value, for integers.
     *
     * @var int|null
     */
    private ?int $min;

    /**
     * The highest accepted value, for integers.
     *
     * @var int|null
     */
    private ?int $max;

    /**
     * The option's `show_in_rest.schema`, when it declares one.
     *
     * @var array<string, mixed>
     */
    private array $schema;

    /**
     * The option's registered sanitize callback, when it has one.
     *
     * @var callable|null
     */
    private $sanitize_callback;

    /**
     * Whether the AI plugin registers this option with `register_setting()`.
     *
     * Options that are read by the AI plugin but never registered (see
     * {@see Collector::UNREGISTERED_FIELDS}) have neither a schema nor a callback, so they are
     * validated from the field kind instead.
     *
     * @var bool
     */
    private bool $registered;

    /**
     * Constructor.
     *
     * @param array{
     *     name: string,
     *     label: string,
     *     kind: string,
     *     section: string,
     *     description?: string,
     *     default?: mixed,
     *     choices?: array<int, array{value: string, label: string}>,
     *     min?: int|null,
     *     max?: int|null,
     *     schema?: array<string, mixed>,
     *     sanitize_callback?: callable|null,
     *     registered?: bool
     * } $args The field definition.
     */
    public function __construct(array $args)
    {
        $this->name              = (string) $args['name'];
        $this->label             = (string) $args['label'];
        $this->kind              = (string) $args['kind'];
        $this->section           = (string) $args['section'];
        $this->description       = (string) ($args['description'] ?? '');
        $this->default           = $args['default'] ?? null;
        $this->choices           = (array) ($args['choices'] ?? array());
        $this->min               = isset($args['min']) && is_numeric($args['min']) ? (int) $args['min'] : null;
        $this->max               = isset($args['max']) && is_numeric($args['max']) ? (int) $args['max'] : null;
        $this->schema            = (array) ($args['schema'] ?? array());
        $this->sanitize_callback = $args['sanitize_callback'] ?? null;
        $this->registered        = (bool) ($args['registered'] ?? true);
    }

    /**
     * Gets the full option name.
     *
     * @return string The option name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Gets the label.
     *
     * @return string The label.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Gets the field kind.
     *
     * @return string One of the KIND_* constants.
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * Gets the id of the section this field belongs to.
     *
     * @return string The section id.
     */
    public function section(): string
    {
        return $this->section;
    }

    /**
     * Gets the description.
     *
     * @return string The description, or an empty string when there is none.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Gets the default value.
     *
     * @return mixed The default value.
     */
    public function default_value()
    {
        return $this->default;
    }

    /**
     * Gets the allowed values.
     *
     * @return array<int, array{value: string, label: string}> The choices.
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * Gets the lowest accepted value.
     *
     * @return int|null The minimum, or null when unbounded.
     */
    public function min(): ?int
    {
        return $this->min;
    }

    /**
     * Gets the highest accepted value.
     *
     * @return int|null The maximum, or null when unbounded.
     */
    public function max(): ?int
    {
        return $this->max;
    }

    /**
     * Gets the option's REST schema.
     *
     * @return array<string, mixed> The schema, or an empty array when the option declares none.
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Gets the option's registered sanitize callback.
     *
     * @return callable|null The callback, or null when the option has none.
     */
    public function sanitize_callback(): ?callable
    {
        return is_callable($this->sanitize_callback) ? $this->sanitize_callback : null;
    }

    /**
     * Whether the AI plugin registers this option.
     *
     * @return bool True when the option comes from `get_registered_settings()`.
     */
    public function is_registered(): bool
    {
        return $this->registered;
    }

    /**
     * Whether this field is an on/off switch.
     *
     * @return bool True for boolean fields.
     */
    public function is_toggle(): bool
    {
        return self::KIND_BOOL === $this->kind;
    }

    /**
     * Whether this field offers a fixed list of choices.
     *
     * @return bool True when choices are available.
     */
    public function has_choices(): bool
    {
        return array() !== $this->choices;
    }
}
