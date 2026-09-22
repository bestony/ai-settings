<?php

/**
 * Writes AI plugin configuration options.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * Saves the AI plugin's options.
 *
 * Only options the {@see Collector} discovered are ever written, and every value is validated the
 * same way the AI plugin's own settings import endpoint validates it: against the option's
 * registered REST schema first, then its sanitize callback, then the field kind. A value that fails
 * validation is reported instead of being stored.
 */
final class Writer
{
    /**
     * The collector supplying the write whitelist.
     *
     * @var Collector
     */
    private Collector $collector;

    /**
     * Constructor.
     *
     * @param Collector $collector The collector supplying the write whitelist.
     */
    public function __construct(Collector $collector)
    {
        $this->collector = $collector;
    }

    /**
     * Saves a set of submitted values.
     *
     * @param array<string, mixed> $input Option names to raw submitted values. Anything not in the
     *                                    collector's whitelist is ignored.
     * @return array{updated: int, rejected: array<int, array{name: string, reason: string}>} The
     *                                                                                        outcome.
     */
    public function save(array $input): array
    {
        $collection = $this->collector->collect();
        $updated    = 0;
        $rejected   = array();

        foreach ($input as $name => $raw) {
            if (!is_string($name)) {
                continue;
            }

            $field = $collection->field($name);
            if (null === $field) {
                continue;
            }

            $value = $this->normalize($field, $raw);

            if (is_wp_error($value)) {
                $rejected[] = array(
                    'name'   => $name,
                    'reason' => $value->get_error_message(),
                );
                continue;
            }

            update_option($name, $value);
            ++$updated;
        }

        return array(
            'updated'  => $updated,
            'rejected' => $rejected,
        );
    }

    /**
     * Turns every feature switch — and the master switch — on or off.
     *
     * @param bool $enabled Whether to enable or disable everything.
     * @return int The number of options written.
     */
    public function set_all_enabled(bool $enabled): int
    {
        $updated = 0;

        foreach ($this->collector->collect()->fields() as $field) {
            // Unregistered options have no switch, and the ones that do are all registered.
            if (!$field->is_toggle() || !$field->is_registered()) {
                continue;
            }

            update_option($field->name(), $enabled);
            ++$updated;
        }

        return $updated;
    }

    /**
     * Points every feature's model override at the same provider and model.
     *
     * @param string|null $provider The provider id, an empty string to clear it back to the AI
     *                              plugin's default, or null to leave it untouched.
     * @param string|null $model The model id, or null/empty to leave it untouched.
     * @param bool        $enabled_only Whether to skip features that are not currently running.
     * @return int The number of options written.
     */
    public function set_model_for_all(?string $provider, ?string $model, bool $enabled_only): int
    {
        $collection = $this->collector->collect();
        $updated    = 0;

        foreach ($collection->sections() as $section) {
            if ($section->is_master()) {
                continue;
            }

            if ($enabled_only && true !== $section->effective()) {
                continue;
            }

            $name  = 'wpai_feature_' . $section->id() . '_field_developer';
            $field = $collection->field($name);

            if (null === $field) {
                continue;
            }

            $value = get_option($name, array());
            $value = is_array($value) ? $value : array();

            if (null !== $provider) {
                $value['provider'] = $provider;
            }

            if (null !== $model && '' !== $model) {
                $value['model'] = $model;
            }

            $normalized = $this->normalize($field, $value);

            if (is_wp_error($normalized)) {
                continue;
            }

            update_option($name, $normalized);
            ++$updated;
        }

        return $updated;
    }

    /**
     * Validates and sanitizes one submitted value.
     *
     * @param Field $field The field being written.
     * @param mixed $raw The submitted value.
     * @return mixed|\WP_Error The value to store, or an error describing why it was rejected.
     */
    private function normalize(Field $field, $raw)
    {
        $schema = $field->schema();

        if (array() !== $schema) {
            $valid = rest_validate_value_from_schema($raw, $schema, $field->name());

            if (is_wp_error($valid)) {
                return $valid;
            }

            $sanitized = rest_sanitize_value_from_schema($raw, $schema, $field->name());

            return $sanitized;
        }

        $callback = $field->sanitize_callback();

        if (null !== $callback) {
            return $callback($raw);
        }

        return $this->normalize_by_kind($field, $raw);
    }

    /**
     * Coerces a value using only the field kind.
     *
     * Used for options the AI plugin reads but never registers, which therefore have neither a
     * schema nor a sanitize callback.
     *
     * @param Field $field The field being written.
     * @param mixed $raw The submitted value.
     * @return mixed The value to store.
     */
    private function normalize_by_kind(Field $field, $raw)
    {
        switch ($field->kind()) {
            case Field::KIND_BOOL:
                return rest_sanitize_boolean($raw);

            case Field::KIND_INTEGER:
                $value = is_numeric($raw) ? (int) $raw : (int) $field->default_value();
                $min   = $field->min();
                $max   = $field->max();

                if (null !== $min && $value < $min) {
                    $value = $min;
                }

                if (null !== $max && $value > $max) {
                    $value = $max;
                }

                return $value;

            case Field::KIND_PROVIDER_MODEL:
                $raw = is_array($raw) ? $raw : array();

                return array(
                    'provider' => sanitize_text_field((string) ($raw['provider'] ?? '')),
                    'model'    => sanitize_text_field((string) ($raw['model'] ?? '')),
                );

            case Field::KIND_SELECT:
            case Field::KIND_STRING:
            default:
                return is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
        }
    }
}
