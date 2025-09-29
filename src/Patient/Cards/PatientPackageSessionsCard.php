<?php

/**
 * Patient package summary card for Saúde & Estética workflows.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    OpenAI Assistant
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Patient\Cards;

use OpenEMR\Events\Patient\Summary\Card\CardModel;
use OpenEMR\Events\Patient\Summary\Card\RenderEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Services\Aesthetic\PackageService;

class PatientPackageSessionsCard extends CardModel
{
    private const TEMPLATE_FILE = 'patient/summary/patient_package_sessions_card.html.twig';
    private const CARD_ID = 'patient_package_sessions';

    private int $pid;
    private PackageService $packageService;

    public function __construct(int $pid, ?PackageService $packageService = null)
    {
        $this->pid = $pid;
        $this->packageService = $packageService ?? new PackageService();
        $this->packageService->ensureSchema();
        parent::__construct($this->buildOptions());
        $this->attachToSection();
    }

    private function buildOptions(): array
    {
        $summary = $this->packageService->getPatientSummary($this->pid);
        $detailUrl = $GLOBALS['webroot'] . '/interface/patient_file/saude_estetica/package_sessions.php?pid=' . urlencode($this->pid);

        return [
            'acl' => ['patients', 'med'],
            'initiallyCollapsed' => false,
            'add' => false,
            'edit' => false,
            'collapse' => true,
            'templateFile' => self::TEMPLATE_FILE,
            'identifier' => self::CARD_ID,
            'title' => xl('Treatment Packages'),
            'templateVariables' => [
                'summary' => $summary,
                'detailUrl' => $detailUrl,
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
