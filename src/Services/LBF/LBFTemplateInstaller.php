<?php

/**
 * Installs or duplicates Layout Based Form (LBF) definitions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\LBF;

use DateTimeImmutable;
use RuntimeException;

class LBFTemplateInstaller
{
    /**
     * Installs a template definition in layout tables.
     *
     * @param array<string, mixed> $template
     * @param string $targetFormId
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function installFromTemplate(array $template, string $targetFormId, array $overrides = []): array
    {
        $payload = $this->normalizeTemplate($template, $targetFormId, $overrides);
        $this->assertFormDoesNotExist($payload['form_id']);

        $this->insertGroups($payload);
        $this->insertFields($payload);

        return $payload;
    }

    /**
     * Duplicates an existing LBF form to a new identifier.
     *
     * @param string $sourceFormId
     * @param string $targetFormId
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function duplicateLayout(string $sourceFormId, string $targetFormId, array $overrides = []): array
    {
        $this->assertFormExists($sourceFormId);
        $this->assertFormDoesNotExist($targetFormId);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $groupRows = $this->fetchRows('layout_group_properties', 'grp_form_id', $sourceFormId);
        if (!$groupRows) {
            throw new RuntimeException(sprintf('Fonte %s não possui grupos cadastrados.', $sourceFormId));
        }

        foreach ($groupRows as $row) {
            $row['grp_form_id'] = $targetFormId;
            if ($row['grp_group_id'] === '') {
                $row['grp_title'] = $overrides['title'] ?? $row['grp_title'];
                if (!empty($overrides['category'])) {
                    $row['grp_mapping'] = $overrides['category'];
                }
            }
            $row['grp_last_update'] = $now;
            $this->insertRow('layout_group_properties', $row);
        }

        $fieldRows = $this->fetchRows('layout_options', 'form_id', $sourceFormId);
        foreach ($fieldRows as $row) {
            $row['form_id'] = $targetFormId;
            $this->insertRow('layout_options', $row);
        }

        return [
            'form_id' => $targetFormId,
            'title' => $overrides['title'] ?? $this->resolveTitle($sourceFormId),
            'category' => $overrides['category'] ?? $this->resolveCategory($sourceFormId),
        ];
    }

    /**
     * @param array<string, mixed> $template
     * @param string $targetFormId
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function normalizeTemplate(array $template, string $targetFormId, array $overrides): array
    {
        if (empty($template['groups']) || !is_array($template['groups'])) {
            throw new RuntimeException('Modelo LBF precisa declarar pelo menos um grupo.');
        }

        $template['form_id'] = $targetFormId;
        $template['title'] = $overrides['title'] ?? ($template['title'] ?? $targetFormId);
        if (!empty($overrides['category'])) {
            $template['category'] = $overrides['category'];
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertGroups(array $payload): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($payload['groups'] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $row = [
                'grp_form_id' => $payload['form_id'],
                'grp_group_id' => $group['id'] ?? '',
                'grp_title' => $group['title'] ?? '',
                'grp_subtitle' => $group['subtitle'] ?? '',
                'grp_mapping' => $group['mapping'] ?? ($payload['category'] ?? ''),
                'grp_seq' => $group['sequence'] ?? 0,
                'grp_columns' => $group['columns'] ?? 0,
                'grp_activity' => 1,
                'grp_last_update' => $now,
            ];
            if ($row['grp_group_id'] === '') {
                $row['grp_title'] = $payload['title'];
            }
            $this->insertRow('layout_group_properties', $row);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertFields(array $payload): void
    {
        if (empty($payload['fields']) || !is_array($payload['fields'])) {
            return;
        }

        foreach ($payload['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $row = [
                'form_id' => $payload['form_id'],
                'field_id' => $field['id'] ?? '',
                'group_id' => $field['group'] ?? '',
                'title' => $field['title'] ?? '',
                'seq' => $field['sequence'] ?? 0,
                'data_type' => $field['data_type'] ?? 2,
                'uor' => $field['uor'] ?? 1,
                'fld_length' => $field['length'] ?? 0,
                'fld_rows' => $field['rows'] ?? 0,
                'max_length' => $field['max_length'] ?? 0,
                'list_id' => $field['list_id'] ?? '',
                'titlecols' => $field['titlecols'] ?? 1,
                'datacols' => $field['datacols'] ?? 1,
                'edit_options' => $field['edit_options'] ?? '',
                'default_value' => $field['default_value'] ?? '',
                'description' => $field['description'] ?? '',
                'source' => $field['source'] ?? '',
                'list_backup_id' => $field['list_backup_id'] ?? '',
                'codes' => $field['codes'] ?? '',
                'conditions' => $field['conditions'] ?? '',
                'validation' => $field['validation'] ?? '',
            ];
            if (empty($row['field_id'])) {
                throw new RuntimeException('Campo do modelo LBF sem identificador.');
            }
            $this->insertRow('layout_options', $row);
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @param string $value
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(string $table, string $column, string $value): array
    {
        $sql = sprintf('SELECT * FROM %s WHERE %s = ?', $table, $column);
        $res = sqlStatement($sql, [$value]);
        $rows = [];
        while ($row = sqlFetchArray($res)) {
            $rows[] = $this->filterAssoc($row);
        }

        return $rows;
    }

    /**
     * @param string $table
     * @param array<string, mixed> $row
     */
    private function insertRow(string $table, array $row): void
    {
        $row = $this->filterAssoc($row);
        $columns = array_keys($row);
        if (empty($columns)) {
            throw new RuntimeException('Sem dados para inserir em ' . $table);
        }
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(function ($col) {
                return '`' . $col . '`';
            }, $columns)),
            $placeholders
        );
        sqlStatement($sql, array_values($row));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterAssoc(array $row): array
    {
        $filtered = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $filtered[$key] = $value;
        }

        return $filtered;
    }

    private function assertFormExists(string $formId): void
    {
        $row = sqlQuery(
            'SELECT 1 FROM layout_group_properties WHERE grp_form_id = ? LIMIT 1',
            [$formId]
        );
        if (!$row) {
            throw new RuntimeException(sprintf('Layout %s não encontrado.', $formId));
        }
    }

    private function assertFormDoesNotExist(string $formId): void
    {
        $row = sqlQuery(
            'SELECT 1 FROM layout_group_properties WHERE grp_form_id = ? LIMIT 1',
            [$formId]
        );
        if ($row) {
            throw new RuntimeException(sprintf('Já existe um layout com identificador %s.', $formId));
        }
    }

    private function resolveTitle(string $formId): string
    {
        $row = sqlQuery(
            'SELECT grp_title FROM layout_group_properties WHERE grp_form_id = ? AND grp_group_id = ? LIMIT 1',
            [$formId, '']
        );
        return $row['grp_title'] ?? $formId;
    }

    private function resolveCategory(string $formId): string
    {
        $row = sqlQuery(
            'SELECT grp_mapping FROM layout_group_properties WHERE grp_form_id = ? AND grp_group_id = ? LIMIT 1',
            [$formId, '']
        );
        return $row['grp_mapping'] ?? '';
    }
}
