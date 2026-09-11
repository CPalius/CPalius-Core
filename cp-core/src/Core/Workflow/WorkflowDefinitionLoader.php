<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads workflow definitions from cp-content/config/sync/workflow/*.yaml.
 * A malformed file is skipped, not fatal (Core Never Dies). File name = workflow name.
 *
 *   places:   [draft, review, published, archived]
 *   initial:  draft
 *   property: status
 *   auditable: true
 *   labels:   { draft: 'Taslak', ... }
 *   transitions:
 *     submit:   { from: [draft], to: review, capability: content.moderate }
 *     publish:  { from: [review], to: published, capability: content.publish }
 */
final class WorkflowDefinitionLoader
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    public function __construct(
        private readonly string $workflowDir,
    ) {
    }

    /**
     * @return array<string, WorkflowDefinition>
     */
    public function loadAll(): array
    {
        if (!is_dir($this->workflowDir)) {
            return [];
        }

        $definitions = [];
        foreach (glob(rtrim($this->workflowDir, '/').'/*.{yaml,yml}', \GLOB_BRACE) ?: [] as $file) {
            $name = pathinfo($file, \PATHINFO_FILENAME);
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }

            $definition = $this->parse($name, $file);
            if ($definition !== null) {
                $definitions[$name] = $definition;
            }
        }

        return $definitions;
    }

    private function parse(string $name, string $file): ?WorkflowDefinition
    {
        try {
            $raw = Yaml::parseFile($file);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($raw)) {
            return null;
        }

        $places = array_values(array_filter(
            (array) ($raw['places'] ?? []),
            static fn (mixed $p): bool => \is_string($p) && preg_match(self::NAME_PATTERN, $p) === 1,
        ));
        if ($places === []) {
            return null;
        }

        $initial = (string) ($raw['initial'] ?? $places[0]);
        if (!\in_array($initial, $places, true)) {
            return null;
        }

        $transitions = [];
        foreach ((array) ($raw['transitions'] ?? []) as $tName => $spec) {
            if (!\is_string($tName) || preg_match(self::NAME_PATTERN, $tName) !== 1 || !\is_array($spec)) {
                continue;
            }
            $from = array_values(array_filter(
                (array) ($spec['from'] ?? []),
                static fn (mixed $p): bool => \is_string($p) && \in_array($p, $places, true),
            ));
            $to = (string) ($spec['to'] ?? '');
            if ($from === [] || !\in_array($to, $places, true)) {
                continue;
            }
            $capability = isset($spec['capability']) && \is_string($spec['capability']) && $spec['capability'] !== ''
                ? strtolower($spec['capability'])
                : null;
            $label = isset($spec['label']) && \is_string($spec['label']) ? $spec['label'] : null;

            $transitions[$tName] = new WorkflowTransition($tName, $from, $to, $capability, $label);
        }
        if ($transitions === []) {
            return null;
        }

        $property = isset($raw['property']) && \is_string($raw['property']) && $raw['property'] !== ''
            ? $raw['property']
            : 'status';

        $labels = [];
        foreach ((array) ($raw['labels'] ?? []) as $place => $label) {
            if (\is_string($place) && \is_string($label) && \in_array($place, $places, true)) {
                $labels[$place] = $label;
            }
        }

        $publishPlaces = array_values(array_filter(
            (array) ($raw['publish_places'] ?? []),
            static fn (mixed $p): bool => \is_string($p) && \in_array($p, $places, true),
        ));

        return new WorkflowDefinition(
            $name,
            $places,
            $transitions,
            $initial,
            $property,
            (bool) ($raw['auditable'] ?? true),
            $labels,
            $publishPlaces,
        );
    }
}
