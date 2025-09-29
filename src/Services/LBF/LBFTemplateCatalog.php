<?php

/**
 * Catalog of LBF templates stored as JSON definitions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\LBF;

use RuntimeException;

class LBFTemplateCatalog
{
    /**
     * @var string
     */
    private $directory;

    /**
     * @param string $directory Absolute path where template JSON files are stored.
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    /**
     * Returns a list of template metadata indexed by slug (filename without extension).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        $templates = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') as $file) {
            $templates[] = $this->buildTemplateSummary($file);
        }

        usort($templates, function (array $a, array $b) {
            return strcasecmp($a['title'], $b['title']);
        });

        return $templates;
    }

    /**
     * Loads the full template payload for the given slug.
     *
     * @param string $slug Filename without the `.json` extension.
     *
     * @return array<string, mixed>
     */
    public function getTemplate(string $slug): array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $slug . '.json';
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Template "%s" not found in %s', $slug, $this->directory));
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException(sprintf('Template "%s" is not a valid JSON document', $slug));
        }

        return $payload;
    }

    /**
     * Returns the absolute path to the template directory.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * @param string $file
     * @return array<string, mixed>
     */
    private function buildTemplateSummary(string $file): array
    {
        $slug = basename($file, '.json');
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'slug' => $slug,
            'file' => $file,
            'form_id' => $payload['form_id'] ?? $slug,
            'title' => $payload['title'] ?? $slug,
            'category' => $payload['category'] ?? '',
            'description' => $payload['description'] ?? '',
            'consents' => $payload['consents'] ?? [],
        ];
    }
}
