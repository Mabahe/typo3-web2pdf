<?php

/****************************************************************
 *  Copyright notice
 *
 *  (C) Mittwald CM Service GmbH & Co. KG <opensource@mittwald.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Mittwald\Web2pdf\View;

use Dompdf\Dompdf;
use Dompdf\Options;
use Mittwald\Web2pdf\Event\ModifyDompdfAfterRenderEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DompdfView extends AbstractPdfView implements PdfViewInterface
{
    public function renderHtmlOutput(string $content, int $pageId): string
    {
        $fileName = $pageId . '_' . sha1($content) . '.pdf';
        $filePath = Environment::getVarPath() . '/web2pdf/' . $fileName;

        $content = $this->replaceStrings($content);

        $dompdf = $this->getPdfObject();
        $dompdf->loadHtml($content, 'UTF-8');
        $dompdf->render();

        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(
            GeneralUtility::makeInstance(ModifyDompdfAfterRenderEvent::class, $dompdf)
        );

        // In Datei speichern
        $varDir = Environment::getVarPath() . '/web2pdf/';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0775, true);
        }
        file_put_contents($filePath, $dompdf->output());

        return $filePath;
    }

    protected function getPdfObject(): Dompdf
    {
        $pageFormat      = $this->options->getPdfPageFormat()      ?? 'A4';
        $pageOrientation = $this->options->getPdfPageOrientation() ?? 'L';
        $styleSheet      = $this->options->getPdfStyleSheet()      ?? 'print';

        $tempDirectory = Environment::getVarPath() . '/web2pdf';

        $dompdfOptions = new Options();
        $dompdfOptions->setIsRemoteEnabled(true);
        $dompdfOptions->setDefaultMediaType($styleSheet);
        $dompdfOptions->setTempDir($tempDirectory);
        $dompdfOptions->setFontDir($tempDirectory);
        $dompdfOptions->setChroot(Environment::getPublicPath());

        if (!file_exists($tempDirectory)) {
            GeneralUtility::mkdir($tempDirectory);
        }

        $pdf = new Dompdf($dompdfOptions);

        $context = stream_context_create([
            'http' => [
                'follow_location' => false,
                'user_agent' => 'Dompdf$ver https://github.com/dompdf/dompdf',
            ],
            'ssl' => [
                'verify_peer' => $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'],
                'verify_peer_name' => $GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'],
                'allow_self_signed' => !$GLOBALS['TYPO3_CONF_VARS']['HTTP']['verify'],
            ],
        ]);

        $pdf->setHttpContext($context);

        $orientation = strtolower($pageOrientation) === 'l' ? 'landscape' : 'portrait';
        $pdf->setPaper(strtolower($pageFormat), $orientation);

        return $pdf;
    }
}
