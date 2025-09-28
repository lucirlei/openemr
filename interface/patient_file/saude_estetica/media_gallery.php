<?php

/**
 * Patient aesthetic media gallery UI.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @copyright Copyright (c) 2024 OpenAI
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Services\Aesthetic\PatientMediaService;
use OpenEMR\Services\DocumentService;

$pid = $_GET['pid'] ?? ($_SESSION['pid'] ?? null);
if (empty($pid)) {
    die(xlt('A patient context is required to access the media gallery.'));
}

$mediaService = new PatientMediaService();
$documentService = new DocumentService();

$albums = $mediaService->listAlbums((int)$pid);
foreach ($albums as &$album) {
    if (!empty($album['cover_document_id'])) {
        $album['cover_url'] = $documentService->getDownloadLink($album['cover_document_id'], $pid);
    }
}
unset($album);

$timelineEntries = $mediaService->getTimeline((int)$pid);
foreach ($timelineEntries as &$entry) {
    if (!empty($entry['document_id'])) {
        $entry['download_url'] = $documentService->getDownloadLink($entry['document_id'], $pid);
    }
}
unset($entry);

$twig = (new TwigContainer(null, $GLOBALS['kernel']))->getTwig();
Header::setupHeader(['bootstrap', 'datetime-picker']);

echo $twig->render('patient/saude_estetica/media_gallery.html.twig', [
    'csrfToken' => CsrfUtils::collectCsrfToken(),
    'pid' => $pid,
    'albums' => $albums,
    'timeline' => $timelineEntries,
    'albumEndpoint' => $GLOBALS['webroot'] . "/apis/api/patient/{$pid}/media/albums",
    'assetEndpointTemplate' => $GLOBALS['webroot'] . "/apis/api/patient/{$pid}/media/albums/%s/assets",
    'timelineEndpoint' => $GLOBALS['webroot'] . "/apis/api/patient/{$pid}/media/timeline",
]);
