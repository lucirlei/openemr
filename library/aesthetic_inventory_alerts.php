<?php

/**
 * Background service for aesthetic inventory alerts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

function run_aesthetic_inventory_alerts(): void
{
    $today = date('Y-m-d');
    $lowStockNotices = [];
    $expiringNotices = [];

    $lowStockSql = "SELECT d.drug_id, d.name, d.reorder_point, d.last_notify,"
        . " SUM(COALESCE(di.on_hand, 0)) AS total_on_hand"
        . " FROM drugs AS d"
        . " LEFT JOIN drug_inventory AS di ON di.drug_id = d.drug_id"
        . " AND di.destroy_date IS NULL"
        . " WHERE d.reorder_point > 0 AND d.active = 1"
        . " GROUP BY d.drug_id";
    $lowStockResult = sqlStatement($lowStockSql);
    while ($row = sqlFetchArray($lowStockResult)) {
        $onHand = (float) ($row['total_on_hand'] ?? 0);
        $reorderPoint = (float) ($row['reorder_point'] ?? 0);
        if ($onHand <= $reorderPoint) {
            if (!empty($row['last_notify']) && $row['last_notify'] >= $today) {
                continue;
            }

            $lowStockNotices[] = sprintf(
                '%s — on hand: %0.2f, minimum: %0.2f',
                $row['name'],
                $onHand,
                $reorderPoint
            );

            sqlStatement(
                'UPDATE drugs SET last_notify = ? WHERE drug_id = ?',
                array($today, $row['drug_id'])
            );
        }
    }

    $expirationSql = "SELECT di.inventory_id, di.drug_id, di.lot_number, di.expiration,"
        . " di.last_notify, d.name"
        . " FROM drug_inventory AS di"
        . " INNER JOIN drugs AS d ON d.drug_id = di.drug_id"
        . " WHERE di.destroy_date IS NULL"
        . " AND di.expiration IS NOT NULL"
        . " AND di.expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    $expResult = sqlStatement($expirationSql);
    while ($row = sqlFetchArray($expResult)) {
        if (empty($row['expiration'])) {
            continue;
        }

        if (!empty($row['last_notify']) && $row['last_notify'] >= $today) {
            continue;
        }

        $expiryDate = $row['expiration'];
        $days = (int) floor((strtotime($expiryDate) - strtotime($today)) / 86400);
        $daysLabel = $days >= 0 ? sprintf(xlt('%s days remaining'), $days) : xlt('expired');
        $expiringNotices[] = sprintf(
            '%s lot %s — expires %s (%s)',
            $row['name'],
            $row['lot_number'] ?: '#',
            oeFormatShortDate($expiryDate),
            $daysLabel
        );

        sqlStatement(
            'UPDATE drug_inventory SET last_notify = ? WHERE inventory_id = ?',
            array($today, $row['inventory_id'])
        );
    }

    $messages = [];
    if (!empty($lowStockNotices)) {
        $messages[] = "Low stock alerts:\n" . implode("\n", $lowStockNotices);
    }
    if (!empty($expiringNotices)) {
        $messages[] = "Expiring lots:\n" . implode("\n", $expiringNotices);
    }

    if (empty($messages)) {
        return;
    }

    $body = implode("\n\n", $messages);
    $subject = 'Aesthetic Inventory Alert';
    queue_aesthetic_inventory_notification($subject, $body);
}

function queue_aesthetic_inventory_notification(string $subject, string $message): void
{
    $sender = $GLOBALS['practice_return_email_path'] ?? 'no-reply@openemr.local';
    $sentDate = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    sqlStatement(
        'INSERT INTO notification_log (pid, pc_eid, sms_gateway_type, smsgateway_info, message, email_sender, email_subject, '
        . 'type, patient_info, pc_eventDate, pc_endDate, pc_startTime, pc_endTime, dSentDateTime) '
        . 'VALUES (0, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(
            'CRM',
            'AestheticInventory',
            $message,
            $sender,
            $subject,
            'Email',
            '',
            $today,
            $today,
            '00:00:00',
            '23:59:59',
            $sentDate
        )
    );
}

