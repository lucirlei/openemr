<?php

/**
 * Maps clinical procedure codes to required product inventory consumption.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\Inventory;

class ProcedureProductService
{
    /**
     * Build a set of inventory consumption requirements keyed by mapping identifier.
     *
     * @param array $billItems The fee sheet service items submitted by the user.
     *
     * @return array<int, array{drug_id:int, quantity:float, code_type:string, code:string}>
     */
    public function buildRequirementsFromBill(array $billItems): array
    {
        $serviceUnits = [];
        foreach ($billItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['billed']) || !empty($item['del'])) {
                continue;
            }

            $codeType = $item['code_type'] ?? '';
            $code = $item['code'] ?? '';
            if ($codeType === '' || $code === '') {
                continue;
            }

            $units = $item['units'] ?? 1;
            $units = is_numeric($units) ? (float)$units : 1.0;
            if ($units <= 0) {
                $units = 1.0;
            }

            $key = $codeType . '|' . $code;
            if (!isset($serviceUnits[$key])) {
                $serviceUnits[$key] = 0.0;
            }
            $serviceUnits[$key] += $units;
        }

        if (empty($serviceUnits)) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($serviceUnits as $key => $units) {
            [$codeType, $code] = explode('|', $key, 2);
            $conditions[] = '(code_type = ? AND code = ?)';
            $params[] = $codeType;
            $params[] = $code;
        }

        $sql = 'SELECT id, code_type, code, drug_id, quantity FROM procedure_products WHERE ' . implode(' OR ', $conditions);
        $result = \sqlStatement($sql, $params);

        $requirements = [];
        while ($row = \sqlFetchArray($result)) {
            $key = $row['code_type'] . '|' . $row['code'];
            $units = $serviceUnits[$key] ?? 0;
            if ($units <= 0) {
                continue;
            }

            $quantityPerUnit = isset($row['quantity']) ? (float)$row['quantity'] : 0.0;
            if ($quantityPerUnit <= 0) {
                continue;
            }

            $requirements[(int)$row['id']] = [
                'drug_id' => (int)$row['drug_id'],
                'quantity' => $quantityPerUnit * $units,
                'code_type' => $row['code_type'],
                'code' => $row['code'],
            ];
        }

        return $requirements;
    }
}

