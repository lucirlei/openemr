<?php

/**
 * Patient media REST controller.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @copyright Copyright (c) 2024 OpenAI
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\Aesthetic\PatientMediaService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PatientMediaRestController
{
    private PatientMediaService $service;

    public function __construct(?PatientMediaService $service = null)
    {
        $this->service = $service ?? new PatientMediaService();
    }

    public function listAlbums(int $patientId)
    {
        $albums = $this->service->listAlbums($patientId);
        return RestControllerHelper::responseHandler(['albums' => $albums]);
    }

    public function createAlbum(int $patientId, HttpRestRequest $request)
    {
        $payload = $this->decodeRequestBody($request);
        $result = $this->service->createAlbum($patientId, $payload ?? []);
        return RestControllerHelper::getResponseForProcessingResult($result);
    }

    public function uploadAssets(int $patientId, int $albumId, HttpRestRequest $request)
    {
        $files = $this->normalizeFiles($request->files->all());
        $metadataRaw = $request->request->get('metadata');
        if (is_string($metadataRaw)) {
            $decoded = json_decode($metadataRaw, true);
            $metadata = is_array($decoded) ? $decoded : [];
        } elseif (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } else {
            $metadata = [];
        }

        $options = [];
        if ($request->request->has('consent_status')) {
            $options['consent_status'] = $request->request->get('consent_status');
        }
        if ($request->request->has('watermark')) {
            $watermarkRaw = $request->request->get('watermark');
            if (is_string($watermarkRaw)) {
                $decoded = json_decode($watermarkRaw, true);
                $options['watermark'] = $decoded ?? $watermarkRaw;
            } else {
                $options['watermark'] = $watermarkRaw;
            }
        }

        $result = $this->service->addAssets($patientId, $albumId, $files, $metadata, $options);
        return RestControllerHelper::getResponseForProcessingResult($result);
    }

    public function getTimeline(int $patientId)
    {
        $timeline = $this->service->getTimeline($patientId);
        return RestControllerHelper::responseHandler(['timeline' => $timeline]);
    }

    private function decodeRequestBody(HttpRestRequest $request): ?array
    {
        $content = $request->getContent();
        if (empty($content)) {
            return $request->request->all();
        }

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $request->request->all();
    }

    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        $iterator = function ($item) use (&$normalized, &$iterator) {
            if ($item instanceof UploadedFile) {
                $normalized[] = [
                    'name' => $item->getClientOriginalName(),
                    'tmp_name' => $item->getPathname(),
                    'type' => $item->getClientMimeType(),
                    'size' => $item->getSize(),
                    'error' => $item->getError(),
                ];
                return;
            }
            if (is_array($item)) {
                foreach ($item as $value) {
                    $iterator($value);
                }
            }
        };

        foreach ($files as $file) {
            $iterator($file);
        }

        return $normalized;
    }
}
