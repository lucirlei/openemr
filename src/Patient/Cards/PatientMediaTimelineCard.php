<?php

/**
 * Patient media timeline card.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @copyright Copyright (c) 2024 OpenAI
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Patient\Cards;

use OpenEMR\Events\Patient\Summary\Card\CardModel;
use OpenEMR\Events\Patient\Summary\Card\RenderEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Services\Aesthetic\PatientMediaService;
use OpenEMR\Services\DocumentService;

class PatientMediaTimelineCard extends CardModel
{
    private const TEMPLATE_FILE = 'patient/summary/patient_media_card.html.twig';
    private const CARD_ID = 'patient_media_timeline';

    private int $pid;

    public function __construct(int $pid)
    {
        $this->pid = $pid;
        parent::__construct($this->buildOptions());
        $this->attachToSection();
    }

    private function buildOptions(): array
    {
        $service = new PatientMediaService();
        $documentService = new DocumentService();
        $timeline = array_slice($service->getTimeline($this->pid), 0, 3);
        foreach ($timeline as &$entry) {
            if (!empty($entry['document_id'])) {
                $entry['preview_url'] = $documentService->getDownloadLink($entry['document_id'], $this->pid);
            }
        }
        unset($entry);

        $galleryUrl = $GLOBALS['webroot'] . '/interface/patient_file/saude_estetica/media_gallery.php?pid=' . urlencode($this->pid);

        return [
            'acl' => ['patients', 'docs'],
            'initiallyCollapsed' => false,
            'add' => false,
            'edit' => false,
            'collapse' => true,
            'templateFile' => self::TEMPLATE_FILE,
            'identifier' => self::CARD_ID,
            'title' => xl('Aesthetic Timeline'),
            'templateVariables' => [
                'timeline' => $timeline,
                'galleryUrl' => $galleryUrl,
            ],
        ];
    }

    private function attachToSection(): void
    {
        $dispatcher = $this->getEventDispatcher();
        $dispatcher->addListener(SectionEvent::EVENT_HANDLE, [$this, 'injectCard']);
    }

    public function injectCard(SectionEvent $event): SectionEvent
    {
        if ($event->getSection('secondary')) {
            $dispatchResult = $this->getEventDispatcher()->dispatch(new RenderEvent(self::CARD_ID), RenderEvent::EVENT_HANDLE);
            $templateVars = $this->getTemplateVariables();
            $templateVars['prependedInjection'] = $dispatchResult->getPrependedInjection();
            $templateVars['appendedInjection'] = $dispatchResult->getAppendedInjection();
            $this->setTemplateVariables($templateVars);
            $event->addCard($this);
        }
        return $event;
    }
}
