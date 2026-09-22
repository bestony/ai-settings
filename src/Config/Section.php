<?php

/**
 * Group of configuration fields.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * One group of fields on the settings screen.
 *
 * Every AI plugin feature becomes a section; the plugin-wide master switch gets its own.
 */
final class Section
{
    /**
     * The id used by the Settings API and by {@see Field::section()}.
     *
     * @var string
     */
    private string $id;

    /**
     * The heading shown above the group.
     *
     * @var string
     */
    private string $label;

    /**
     * A sentence explaining what the group does.
     *
     * @var string
     */
    private string $description;

    /**
     * The id of the {@see Module} this section is filed under.
     *
     * Empty for the master switch, which is shown before any module.
     *
     * @var string
     */
    private string $module;

    /**
     * Whether this section holds the plugin-wide master switch.
     *
     * @var bool
     */
    private bool $master;

    /**
     * The feature's effective runtime state.
     *
     * `Feature::is_enabled()` — which is the master switch AND the feature's own switch, both run
     * through their filters. Null when the AI plugin's registry was not reachable, in which case the
     * screen falls back to the stored value alone.
     *
     * @var bool|null
     */
    private ?bool $effective;

    /**
     * Constructor.
     *
     * @param string    $id The section id.
     * @param string    $label The heading.
     * @param string    $description The description.
     * @param string    $module The owning module id.
     * @param bool      $master Whether this is the master switch section.
     * @param bool|null $effective The effective runtime state.
     */
    public function __construct(
        string $id,
        string $label,
        string $description = '',
        string $module = '',
        bool $master = false,
        ?bool $effective = null
    ) {
        $this->id          = $id;
        $this->label       = $label;
        $this->description = $description;
        $this->module      = $module;
        $this->master      = $master;
        $this->effective   = $effective;
    }

    /**
     * Gets the section id.
     *
     * @return string The id.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Gets the heading.
     *
     * @return string The label.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Gets the description.
     *
     * @return string The description.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Gets the owning module id.
     *
     * @return string The module id, or an empty string for the master switch.
     */
    public function module(): string
    {
        return $this->module;
    }

    /**
     * Whether this section holds the master switch.
     *
     * @return bool True for the master switch section.
     */
    public function is_master(): bool
    {
        return $this->master;
    }

    /**
     * Gets the feature's effective runtime state.
     *
     * @return bool|null True when the feature is running, false when it is not, null when unknown.
     */
    public function effective(): ?bool
    {
        return $this->effective;
    }
}
