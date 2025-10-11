<?php
/**
 * Body composition API endpoint for weight management workflows.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\DocumentService;

require_once(__DIR__ . '/../globals.php');
require_once($GLOBALS['srcdir'] . '/api.inc');
require_once($GLOBALS['srcdir'] . '/formdata.inc.php');
require_once($GLOBALS['srcdir'] . '/classes/Document.class.php');

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? ($method === 'GET' ? 'list' : 'save');

try {
    switch (strtolower($action)) {
        case 'list':
            echo json_encode(handleList());
            break;
        case 'save':
            if ($method !== 'POST') {
                throw new RuntimeException(xlt('Invalid request method.'));
            }
            if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'body_composition_form')) {
                CsrfUtils::csrfNotVerified();
            }
            echo json_encode(handleSave());
            break;
        case 'delete-media':
            if ($method !== 'POST') {
                throw new RuntimeException(xlt('Invalid request method.'));
            }
            if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'body_composition_form')) {
                CsrfUtils::csrfNotVerified();
            }
            echo json_encode(handleDeleteMedia());
            break;
        default:
            throw new RuntimeException(xlt('Unsupported action.'));
    }
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage(),
    ]);
}

/**
 * Retrieve body composition records for a patient.
 *
 * @return array
 */
function handleList(): array
{
    $pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
    if (empty($pid)) {
        throw new RuntimeException(xlt('Patient not selected.'));
    }

    $records = [];
    $sql = "SELECT * FROM body_composition WHERE pid = ? ORDER BY recorded_date DESC";
    $res = sqlStatement($sql, [$pid]);
    $documentService = new DocumentService();
    while ($row = sqlFetchArray($res)) {
        $mediaRes = sqlStatement(
            "SELECT bcm.id, bcm.document_id, bcm.label, d.name, d.docdate, d.mimetype "
            . "FROM body_composition_media AS bcm "
            . "JOIN documents AS d ON d.id = bcm.document_id "
            . "WHERE bcm.body_composition_id = ? ORDER BY d.docdate DESC, d.id DESC",
            [$row['id']]
        );
        $media = [];
        while ($mediaRow = sqlFetchArray($mediaRes)) {
            $media[] = [
                'id' => (int)$mediaRow['id'],
                'document_id' => (int)$mediaRow['document_id'],
                'label' => $mediaRow['label'],
                'name' => $mediaRow['name'],
                'docdate' => $mediaRow['docdate'],
                'mimetype' => $mediaRow['mimetype'],
                'url' => $documentService->getDownloadLink($mediaRow['document_id'], $pid),
            ];
        }
        $row['media'] = $media;
        $records[] = $row;
    }

    return [
        'success' => true,
        'data' => $records,
    ];
}

/**
 * Persist a body composition record.
 *
 * @return array
 */
function handleSave(): array
{
    $pid = (int)($_POST['pid'] ?? $_SESSION['pid'] ?? 0);
    if (empty($pid)) {
        throw new RuntimeException(xlt('Patient not selected.'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $recordedDate = $_POST['recorded_date'] ?? date('Y-m-d');
    $weight = trim($_POST['weight_kg'] ?? '');
    $height = trim($_POST['height_m'] ?? '');
    $bmi = trim($_POST['bmi'] ?? '');
    $bodyFat = trim($_POST['body_fat_percent'] ?? '');
    $muscleMass = trim($_POST['muscle_mass_kg'] ?? '');
    $abdomen = trim($_POST['abdomen_cm'] ?? '');
    $hip = trim($_POST['hip_cm'] ?? '');
    $arm = trim($_POST['arm_cm'] ?? '');
    $thigh = trim($_POST['thigh_cm'] ?? '');
    $notes = $_POST['notes'] ?? null;

    if (!empty($weight) && !empty($height) && empty($bmi)) {
        $heightVal = (float)$height;
        if ($heightVal > 0) {
            $bmi = round(((float)$weight) / ($heightVal * $heightVal), 2);
        }
    }

    $userId = (int)($_SESSION['authUserID'] ?? 0);

    $data = [
        'recorded_date' => $recordedDate,
        'weight_kg' => $weight !== '' ? (float)$weight : null,
        'height_m' => $height !== '' ? (float)$height : null,
        'bmi' => $bmi !== '' ? (float)$bmi : null,
        'body_fat_percent' => $bodyFat !== '' ? (float)$bodyFat : null,
        'muscle_mass_kg' => $muscleMass !== '' ? (float)$muscleMass : null,
        'abdomen_cm' => $abdomen !== '' ? (float)$abdomen : null,
        'hip_cm' => $hip !== '' ? (float)$hip : null,
        'arm_cm' => $arm !== '' ? (float)$arm : null,
        'thigh_cm' => $thigh !== '' ? (float)$thigh : null,
        'notes' => $notes,
        'updated_by' => $userId ?: null,
    ];

    if (!empty($id)) {
        $placeholders = [];
        $values = [];
        foreach ($data as $column => $value) {
            $placeholders[] = "`$column` = ?";
            $values[] = $value;
        }
        $values[] = $id;
        $values[] = $pid;
        sqlStatement(
            'UPDATE body_composition SET ' . implode(', ', $placeholders) . ' WHERE id = ? AND pid = ?',
            $values
        );
        $recordId = $id;
    } else {
        $data['pid'] = $pid;
        $data['created_by'] = $userId ?: null;
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);
        $recordId = sqlInsert(
            'INSERT INTO body_composition (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')',
            $values
        );
    }

    // Handle media deletions
    $removeIds = array_filter(array_map('trim', explode(',', $_POST['remove_photo_ids'] ?? '')));
    if (!empty($removeIds)) {
        foreach ($removeIds as $removeId) {
            $mediaRow = sqlQuery(
                'SELECT document_id FROM body_composition_media WHERE id = ? AND body_composition_id = ?',
                [$removeId, $recordId]
            );
            if (!empty($mediaRow['document_id'])) {
                sqlStatement('UPDATE documents SET deleted = 1 WHERE id = ?', [$mediaRow['document_id']]);
            }
        }
        $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
        $params = array_merge($removeIds, [$recordId]);
        sqlStatement(
            'DELETE FROM body_composition_media WHERE id IN (' . $placeholders . ') AND body_composition_id = ?',
            $params
        );
    }

    // Handle uploads
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $documentService = new DocumentService();
        $preferredPaths = [
            'Patient Documents/Body Composition',
            'Patient Documents/Medical Record',
            'Patient Documents'
        ];
        $categoryId = null;
        foreach ($preferredPaths as $path) {
            if ($documentService->isValidPath($path)) {
                $categoryId = $documentService->getLastIdOfPath($path);
                break;
            }
        }
        if (!empty($categoryId)) {
            $fileCount = count($_FILES['photos']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $tmpName = $_FILES['photos']['tmp_name'][$i];
                $originalName = $_FILES['photos']['name'][$i];
                if (empty($tmpName) || !is_uploaded_file($tmpName)) {
                    continue;
                }
                $fileData = file_get_contents($tmpName);
                if (empty($fileData)) {
                    continue;
                }
                $document = new Document();
                $document->set_foreign_id($pid);
                $document->set_docdate($recordedDate);
                $error = $document->createDocument(
                    $pid,
                    $categoryId,
                    $originalName,
                    mime_content_type($tmpName) ?: 'application/octet-stream',
                    $fileData,
                    '',
                    1,
                    $_SESSION['authUserID'] ?? 0,
                    $tmpName
                );
                if (empty($error)) {
                    $docId = $document->get_id();
                    sqlInsert(
                        'INSERT INTO body_composition_media (body_composition_id, document_id, label) VALUES (?,?,?)',
                        [$recordId, $docId, null]
                    );
                }
            }
        }
    }

    return [
        'success' => true,
        'id' => $recordId,
    ];
}

/**
 * Remove a single media relation entry.
 *
 * @return array
 */
function handleDeleteMedia(): array
{
    $mediaId = (int)($_POST['media_id'] ?? 0);
    $pid = (int)($_POST['pid'] ?? $_SESSION['pid'] ?? 0);
    if (empty($mediaId) || empty($pid)) {
        throw new RuntimeException(xlt('Invalid parameters.'));
    }
    $row = sqlQuery(
        'SELECT bcm.document_id FROM body_composition_media AS bcm '
        . 'JOIN body_composition AS bc ON bc.id = bcm.body_composition_id '
        . 'WHERE bcm.id = ? AND bc.pid = ?',
        [$mediaId, $pid]
    );
    if (empty($row)) {
        throw new RuntimeException(xlt('Media item not found.'));
    }
    sqlStatement('UPDATE documents SET deleted = 1 WHERE id = ?', [$row['document_id']]);
    sqlStatement('DELETE FROM body_composition_media WHERE id = ?', [$mediaId]);

    return [
        'success' => true,
    ];
}
