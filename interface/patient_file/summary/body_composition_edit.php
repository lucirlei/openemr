<?php
/**
 * Body composition create/edit form
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\DocumentService;

$pid = (int)($_GET['pid'] ?? $_SESSION['pid'] ?? 0);
if (empty($pid)) {
    die(xlt('Patient not selected.'));
}

$id = (int)($_GET['id'] ?? 0);
$record = null;
$media = [];
if (!empty($id)) {
    $record = sqlQuery("SELECT * FROM body_composition WHERE id = ? AND pid = ?", [$id, $pid]);
    if (!empty($record)) {
        $mediaSql = "SELECT bcm.id, bcm.document_id, bcm.label, d.name, d.docdate "
            . "FROM body_composition_media AS bcm "
            . "JOIN documents AS d ON d.id = bcm.document_id "
            . "WHERE bcm.body_composition_id = ? ORDER BY d.docdate DESC, bcm.id DESC";
        $mediaRes = sqlStatement($mediaSql, [$record['id']]);
        $documentService = new DocumentService();
        while ($row = sqlFetchArray($mediaRes)) {
            $row['url'] = $documentService->getDownloadLink($row['document_id'], $pid);
            $media[] = $row;
        }
    }
}

$token = CsrfUtils::collectCsrfToken('body_composition_form');
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Body Composition Form'); ?></title>
    <?php Header::setupHeader(['datetime-picker']); ?>
    <style>
        .existing-media img {
            max-width: 100px;
            border-radius: 4px;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <form id="body-composition-form" class="mt-3" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($token); ?>" />
        <input type="hidden" name="pid" value="<?php echo attr($pid); ?>" />
        <input type="hidden" name="id" value="<?php echo attr($id); ?>" />
        <input type="hidden" name="remove_photo_ids" id="remove_photo_ids" value="" />
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label for="recorded_date" class="form-label"><?php echo xlt('Measurement Date'); ?></label>
                    <input type="date" class="form-control" id="recorded_date" name="recorded_date" value="<?php echo attr($record['recorded_date'] ?? date('Y-m-d')); ?>" required />
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="weight_kg" class="form-label"><?php echo xlt('Weight (kg)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="weight_kg" name="weight_kg" value="<?php echo attr($record['weight_kg'] ?? ''); ?>" />
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="height_m" class="form-label"><?php echo xlt('Height (m)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="height_m" name="height_m" value="<?php echo attr($record['height_m'] ?? ''); ?>" />
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label for="bmi" class="form-label"><?php echo xlt('BMI'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="bmi" name="bmi" value="<?php echo attr($record['bmi'] ?? ''); ?>" readonly />
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="body_fat_percent" class="form-label"><?php echo xlt('Body Fat (%)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="body_fat_percent" name="body_fat_percent" value="<?php echo attr($record['body_fat_percent'] ?? ''); ?>" />
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label for="muscle_mass_kg" class="form-label"><?php echo xlt('Muscle Mass (kg)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="muscle_mass_kg" name="muscle_mass_kg" value="<?php echo attr($record['muscle_mass_kg'] ?? ''); ?>" />
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3">
                <div class="form-group">
                    <label for="abdomen_cm" class="form-label"><?php echo xlt('Abdomen (cm)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="abdomen_cm" name="abdomen_cm" value="<?php echo attr($record['abdomen_cm'] ?? ''); ?>" />
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="hip_cm" class="form-label"><?php echo xlt('Hip (cm)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="hip_cm" name="hip_cm" value="<?php echo attr($record['hip_cm'] ?? ''); ?>" />
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="arm_cm" class="form-label"><?php echo xlt('Arm (cm)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="arm_cm" name="arm_cm" value="<?php echo attr($record['arm_cm'] ?? ''); ?>" />
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label for="thigh_cm" class="form-label"><?php echo xlt('Thigh (cm)'); ?></label>
                    <input type="number" step="0.01" class="form-control" id="thigh_cm" name="thigh_cm" value="<?php echo attr($record['thigh_cm'] ?? ''); ?>" />
                </div>
            </div>
        </div>
        <div class="form-group">
            <label for="notes" class="form-label"><?php echo xlt('Notes'); ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo text($record['notes'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo xlt('Before/After Photos'); ?></label>
            <input type="file" class="form-control" name="photos[]" id="photos" multiple accept="image/*" />
            <?php if (!empty($media)) : ?>
                <div class="existing-media mt-2">
                    <p class="fw-bold"><?php echo xlt('Existing Photos'); ?></p>
                    <?php foreach ($media as $item) : ?>
                        <div class="form-check form-check-inline align-items-center mb-2">
                            <a href="<?php echo attr($item['url']); ?>" target="_blank">
                                <img src="<?php echo attr($item['url']); ?>" alt="<?php echo attr($item['label'] ?: $item['name']); ?>" />
                            </a>
                            <div class="ms-2">
                                <label class="form-check-label">
                                    <input class="form-check-input" type="checkbox" value="<?php echo attr($item['id']); ?>" data-media-id="<?php echo attr($item['id']); ?>" />
                                    <?php echo xlt('Remove'); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-secondary" onclick="dlgclose();"><?php echo xlt('Cancel'); ?></button>
            <button type="submit" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
        </div>
    </form>
</div>
<script>
    const weightEl = document.getElementById('weight_kg');
    const heightEl = document.getElementById('height_m');
    const bmiEl = document.getElementById('bmi');

    function updateBmi() {
        const weight = parseFloat(weightEl.value);
        const height = parseFloat(heightEl.value);
        if (weight > 0 && height > 0) {
            const bmi = weight / (height * height);
            bmiEl.value = bmi.toFixed(2);
        } else {
            bmiEl.value = '';
        }
    }

    weightEl.addEventListener('input', updateBmi);
    heightEl.addEventListener('input', updateBmi);

    document.getElementById('body-composition-form').addEventListener('submit', function (event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const markedForRemoval = Array.from(form.querySelectorAll('.existing-media input[type="checkbox"]:checked')).map(function (checkbox) {
            return checkbox.value;
        });
        formData.set('remove_photo_ids', markedForRemoval.join(','));
        formData.append('action', 'save');
        fetch(top.webroot_url + '/library/api/body_composition_api.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (payload.success) {
                if (top && typeof top.restoreSession === 'function') {
                    top.restoreSession();
                }
                if (top && top.dlgclose) {
                    top.dlgclose();
                }
                if (top && top.location) {
                    top.location.reload();
                }
            } else {
                alert(payload.message || '<?php echo xlt('Unable to save record'); ?>');
            }
        }).catch(function () {
            alert('<?php echo xlt('An unexpected error occurred while saving.'); ?>');
        });
    });
</script>
</body>
</html>
