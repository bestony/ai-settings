<?php

/**
 * Complete set of discovered configuration fields.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * The sections and fields found on the AI plugin, in display order.
 */
final class Collection
{
    /**
     * The modules that hold at least one section, keyed by module id, in display order.
     *
     * @var array<string, Module>
     */
    private array $modules;

    /**
     * The sections, keyed by section id.
     *
     * @var array<string, Section>
     */
    private array $sections;

    /**
     * The fields, keyed by option name.
     *
     * @var array<string, Field>
     */
    private array $fields;

    /**
     * Constructor.
     *
     * @param array<string, Module>  $modules The modules, keyed by id.
     * @param array<string, Section> $sections The sections, keyed by id.
     * @param array<string, Field>   $fields The fields, keyed by option name.
     */
    public function __construct(array $modules, array $sections, array $fields)
    {
        $this->modules  = $modules;
        $this->sections = $sections;
        $this->fields   = $fields;
    }

    /**
     * Gets the modules that hold at least one section.
     *
     * @return array<string, Module> The modules, keyed by id, in display order.
     */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Gets a module by id.
     *
     * @param string $id The module id.
     * @return Module|null The module, or null when nothing is filed under it.
     */
    public function module(string $id): ?Module
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * Gets the sections filed under a module, in display order.
     *
     * @param string $module_id The module id.
     * @return array<string, Section> The matching sections, keyed by id.
     */
    public function sections_in(string $module_id): array
    {
        $sections = array();

        foreach ($this->sections as $id => $section) {
            if ($section->module() === $module_id) {
                $sections[$id] = $section;
            }
        }

        return $sections;
    }

    /**
     * Gets every section.
     *
     * @return array<string, Section> The sections, keyed by id.
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * Gets every field.
     *
     * @return array<string, Field> The fields, keyed by option name.
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Gets the fields belonging to a section, in display order.
     *
     * @param string $section_id The section id.
     * @return array<string, Field> The matching fields.
     */
    public function fields_in(string $section_id): array
    {
        $fields = array();

        foreach ($this->fields as $name => $field) {
            if ($field->section() === $section_id) {
                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    /**
     * Gets a section by id.
     *
     * @param string $id The section id.
     * @return Section|null The section, or null when it does not exist.
     */
    public function section(string $id): ?Section
    {
        return $this->sections[$id] ?? null;
    }

    /**
     * Gets a field by option name.
     *
     * @param string $name The option name.
     * @return Field|null The field, or null when it is not one this plugin manages.
     */
    public function field(string $name): ?Field
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * Gets every managed option name.
     *
     * This is the write whitelist: nothing outside it is ever saved.
     *
     * @return list<string> The option names.
     */
    public function names(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Whether any field was discovered.
     *
     * @return bool True when the collection is empty.
     */
    public function is_empty(): bool
    {
        return array() === $this->fields;
    }
}
