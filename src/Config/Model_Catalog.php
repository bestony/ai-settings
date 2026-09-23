<?php

/**
 * The models the AI providers expose.
 *
 * @package AISettings
 */

declare(strict_types=1);

namespace AISettings\Config;

/**
 * Reads the models each AI provider offers, for the model dropdowns.
 *
 * The list comes from the AI plugin's own `ai/v1/providers` route rather than from the connector
 * registry, which carries no model information. That route only considers connectors whose plugin
 * is active and whose credentials are in place, and the AI client caches each provider's model
 * metadata for a day, so rendering a screen does not query every provider's API.
 */
final class Model_Catalog
{
    /**
     * The AI plugin route that lists providers and their models.
     *
     * @var string
     */
    private const ROUTE = '/ai/v1/providers';

    /**
     * The capabilities the route accepts.
     *
     * @var list<string>
     */
    public const CAPABILITIES = array('text_generation', 'image_generation', 'vision');

    /**
     * The catalog key that stands for every capability at once.
     *
     * Controls that are not tied to one feature — the bulk form — use it.
     *
     * @var string
     */
    public const ALL = '__all__';

    /**
     * The catalog found per capability, keyed by capability.
     *
     * @var array<string, array<string, array{name: string, models: array<string, string>}>>
     */
    private array $cache = array();

    /**
     * Gets the models the providers expose for a capability.
     *
     * @param string $capability The capability, e.g. `text_generation`.
     * @return array<string, array{name: string, models: array<string, string>}> Provider id to its
     *                                                                           name and models, or
     *                                                                           an empty array when
     *                                                                           none were found.
     */
    public function models(string $capability): array
    {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            return array();
        }

        if (!isset($this->cache[$capability])) {
            $this->cache[$capability] = $this->fetch($capability);
        }

        return $this->cache[$capability];
    }

    /**
     * Merges the models of several capabilities.
     *
     * @param list<string> $capabilities The capabilities.
     * @return array<string, array{name: string, models: array<string, string>}> Provider id to its
     *                                                                           name and models.
     */
    public function union(array $capabilities): array
    {
        $union = array();

        foreach ($capabilities as $capability) {
            foreach ($this->models($capability) as $provider_id => $provider) {
                if (!isset($union[$provider_id])) {
                    $union[$provider_id] = $provider;
                    continue;
                }

                $union[$provider_id]['models'] += $provider['models'];
            }
        }

        return $union;
    }

    /**
     * Performs the request and normalises the response.
     *
     * An unavailable AI client, a provider that fails, or any other error yields an empty catalog
     * rather than an exception, so the screen falls back to the plain model field.
     *
     * @param string $capability The capability.
     * @return array<string, array{name: string, models: array<string, string>}> Provider id to its
     *                                                                           name and models.
     */
    private function fetch(string $capability): array
    {
        $request = new \WP_REST_Request('GET', self::ROUTE);
        $request->set_param('capability', $capability);

        try {
            $response = rest_do_request($request);
        } catch (\Throwable $e) {
            return array();
        }

        if (is_wp_error($response) || !$response instanceof \WP_REST_Response || $response->is_error()) {
            return array();
        }

        $data = $response->get_data();

        if (!is_array($data)) {
            return array();
        }

        $catalog = array();

        foreach ($data as $provider) {
            if (!is_array($provider) || !isset($provider['id']) || !is_string($provider['id'])) {
                continue;
            }

            $provider_id = $provider['id'];
            $name        = isset($provider['name']) && is_string($provider['name'])
                ? $provider['name']
                : $provider_id;
            $models      = array();

            foreach ((array) ($provider['models'] ?? array()) as $model) {
                if (!is_array($model) || !isset($model['id']) || !is_string($model['id'])) {
                    continue;
                }

                $models[$model['id']] = isset($model['name']) && is_string($model['name'])
                    ? $model['name']
                    : $model['id'];
            }

            $catalog[$provider_id] = array(
                'name'   => $name,
                'models' => $models,
            );
        }

        return $catalog;
    }
}
