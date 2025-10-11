<?php
/**
 * Body composition summary widget
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\DocumentService;

$embedded = !empty($_POST['embeddedScreen']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? null)) {
    CsrfUtils::csrfNotVerified();
}

$pid = (int)($_SESSION['pid'] ?? ($_GET['pid'] ?? 0));
if (empty($pid)) {
    die(xlt('Patient not selected.'));
}

$canEdit = AclMain::aclCheckCore('patients', 'med', '', 'write');

$documentService = new DocumentService();
$records = [];
$sql = "SELECT * FROM body_composition WHERE pid = ? ORDER BY recorded_date ASC";
$res = sqlStatement($sql, [$pid]);
while ($row = sqlFetchArray($res)) {
    $mediaSql = "SELECT bcm.id, bcm.document_id, bcm.label, d.name, d.docdate, d.mimetype "
        . "FROM body_composition_media AS bcm "
        . "JOIN documents AS d ON d.id = bcm.document_id "
        . "WHERE bcm.body_composition_id = ? ORDER BY d.docdate DESC, d.id DESC";
    $mediaRes = sqlStatement($mediaSql, [$row['id']]);
    $media = [];
    while ($mediaRow = sqlFetchArray($mediaRes)) {
        $media[] = [
            'id' => (int)$mediaRow['id'],
            'document_id' => (int)$mediaRow['document_id'],
            'label' => $mediaRow['label'] ?? '',
            'name' => $mediaRow['name'],
            'docdate' => $mediaRow['docdate'],
            'mimetype' => $mediaRow['mimetype'],
            'url' => $documentService->getDownloadLink($mediaRow['document_id'], $pid)
        ];
    }
    $row['media'] = $media;
    $records[] = $row;
}

$chartDates = [];
$chartWeight = [];
$chartBodyFat = [];
foreach ($records as $record) {
    $chartDates[] = $record['recorded_date'];
    $chartWeight[] = is_null($record['weight_kg']) ? null : (float)$record['weight_kg'];
    $chartBodyFat[] = is_null($record['body_fat_percent']) ? null : (float)$record['body_fat_percent'];
}
?>
<?php if (!$embedded) : ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Body Composition'); ?></title>
    <?php Header::setupHeader(['chart']); ?>
    <style>
<?php else : ?>
<?php Header::setupAssets(['chart']); ?>
<style>
<?php endif; ?>
        #body-composition-table th,
        #body-composition-table td {
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .body-composition-gallery img {
            max-width: 120px;
            border-radius: 4px;
            margin: 0.25rem;
        }
        .body-composition-actions {
            margin-bottom: 1rem;
        }
    </style>
<?php if (!$embedded) : ?>
</head>
<body>
<?php endif; ?>
<div class="container-fluid">
    <div class="body-composition-actions d-flex justify-content-between align-items-center">
        <h5 class="my-2"><?php echo xlt('Body Composition Tracking'); ?></h5>
        <?php if ($canEdit) : ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="openBodyCompositionForm();">
                <i class="fa fa-plus"></i> <?php echo xlt('Add Measurement'); ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if (empty($records)) : ?>
        <div class="alert alert-info"><?php echo xlt('No body composition records found for this patient.'); ?></div>
    <?php else : ?>
        <div class="row">
            <div class="col-md-8">
                <canvas id="bodyCompositionChart" height="180"></canvas>
            </div>
            <div class="col-md-4">
                <div class="card mb-3">
                    <div class="card-header"><?php echo xlt('Latest Metrics'); ?></div>
                    <div class="card-body">
                        <?php $latest = end($records); ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-6"><?php echo xlt('Date'); ?></dt>
                            <dd class="col-sm-6"><?php echo text($latest['recorded_date']); ?></dd>
                            <dt class="col-sm-6"><?php echo xlt('Weight (kg)'); ?></dt>
                            <dd class="col-sm-6"><?php echo text(oeFormatNumber($latest['weight_kg'] ?? '')); ?></dd>
                            <dt class="col-sm-6"><?php echo xlt('Body Fat (%)'); ?></dt>
                            <dd class="col-sm-6"><?php echo text(oeFormatNumber($latest['body_fat_percent'] ?? '')); ?></dd>
                            <dt class="col-sm-6"><?php echo xlt('Muscle Mass (kg)'); ?></dt>
                            <dd class="col-sm-6"><?php echo text(oeFormatNumber($latest['muscle_mass_kg'] ?? '')); ?></dd>
                            <dt class="col-sm-6"><?php echo xlt('BMI'); ?></dt>
                            <dd class="col-sm-6"><?php echo text(oeFormatNumber($latest['bmi'] ?? '')); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped" id="body-composition-table">
                <thead>
                <tr>
                    <th><?php echo xlt('Date'); ?></th>
                    <th><?php echo xlt('Weight (kg)'); ?></th>
                    <th><?php echo xlt('Body Fat (%)'); ?></th>
                    <th><?php echo xlt('BMI'); ?></th>
                    <th><?php echo xlt('Circumferences (cm)'); ?></th>
                    <th><?php echo xlt('Notes'); ?></th>
                    <th><?php echo xlt('Media'); ?></th>
                    <?php if ($canEdit) : ?>
                        <th></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_reverse($records) as $record) : ?>
                    <tr>
                        <td><?php echo text(oeFormatShortDate($record['recorded_date'])); ?></td>
                        <td><?php echo text(oeFormatNumber($record['weight_kg'] ?? '')); ?></td>
                        <td><?php echo text(oeFormatNumber($record['body_fat_percent'] ?? '')); ?></td>
                        <td><?php echo text(oeFormatNumber($record['bmi'] ?? '')); ?></td>
                        <td>
                            <?php
                            $values = [];
                            if (!empty($record['abdomen_cm'])) {
                                $values[] = xlt('Abdomen') . ': ' . oeFormatNumber($record['abdomen_cm']);
                            }
                            if (!empty($record['hip_cm'])) {
                                $values[] = xlt('Hip') . ': ' . oeFormatNumber($record['hip_cm']);
                            }
                            if (!empty($record['arm_cm'])) {
                                $values[] = xlt('Arm') . ': ' . oeFormatNumber($record['arm_cm']);
                            }
                            if (!empty($record['thigh_cm'])) {
                                $values[] = xlt('Thigh') . ': ' . oeFormatNumber($record['thigh_cm']);
                            }
                            echo text(implode('\n', $values));
                            ?>
                        </td>
                        <td><?php echo nl2br(text($record['notes'] ?? '')); ?></td>
                        <td>
                            <?php if (!empty($record['media'])) : ?>
                                <div class="body-composition-gallery d-flex flex-wrap">
                                    <?php foreach ($record['media'] as $media) : ?>
                                        <a href="<?php echo attr($media['url']); ?>" target="_blank" class="me-2 mb-2">
                                            <img src="<?php echo attr($media['url']); ?>" alt="<?php echo attr($media['label'] ?: $media['name']); ?>" />
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <span class="text-muted"><?php echo xlt('No photos'); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit) : ?>
                            <td>
                                <button type="button" class="btn btn-link btn-sm" onclick="openBodyCompositionForm(<?php echo attr_js($record['id']); ?>);">
                                    <?php echo xlt('Edit'); ?>
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    function openBodyCompositionForm(id = '') {
        const url = top.webroot_url + '/interface/patient_file/summary/body_composition_edit.php?pid=<?php echo attr_js($pid); ?>&id=' + encodeURIComponent(id);
        dlgopen(url, '_blank', 900, 700, '', '', {
            allowResize: true,
            allowMaximize: true
        });
    }

    (function initChart() {
        const ctx = document.getElementById('bodyCompositionChart');
        if (!ctx || !window.Chart) {
            return;
        }
        const chartData = {
            labels: <?php echo json_encode($chartDates); ?>,
            datasets: [
                {
                    label: '<?php echo xlt('Weight (kg)'); ?>',
                    data: <?php echo json_encode($chartWeight); ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.15)',
                    yAxisID: 'weightScale'
                },
                {
                    label: '<?php echo xlt('Body Fat (%)'); ?>',
                    data: <?php echo json_encode($chartBodyFat); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.15)',
                    yAxisID: 'bodyFatScale'
                }
            ]
        };
        new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    weightScale: {
                        type: 'linear',
                        position: 'left',
                        title: { display: true, text: '<?php echo xlt('Weight (kg)'); ?>' }
                    },
                    bodyFatScale: {
                        type: 'linear',
                        position: 'right',
                        title: { display: true, text: '<?php echo xlt('Body Fat (%)'); ?>' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    })();
</script>
<?php if (!$embedded) : ?>
</body>
</html>
<?php endif; ?>
