<?php

/**
 * Group of sections shown under one heading.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * A place where AI features take effect — content generation, media, comments and so on.
 *
 * The AI plugin only classifies its features as `editor` or `admin`, which says where a feature is
 * registered but nothing about what it does. Modules re-group the same features by where an editor
 * or administrator would go looking for them.
 */
final class Module
{
    /**
     * The module id.
     *
     * @var string
     */
    private string $id;

    /**
     * The heading shown above the module.
     *
     * @var string
     */
    private string $label;

    /**
     * A sentence explaining what the module covers.
     *
     * @var string
     */
    private string $description;

    /**
     * Constructor.
     *
     * @param string $id The module id.
     * @param string $label The heading.
     * @param string $description The description.
     */
    public function __construct(string $id, string $label, string $description = '')
    {
        $this->id          = $id;
        $this->label       = $label;
        $this->description = $description;
    }

    /**
     * Gets the module id.
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
     * @return string The description, or an empty string when there is none.
     */
    public function description(): string
    {
        return $this->description;
    }
}
