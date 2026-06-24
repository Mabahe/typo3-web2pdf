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

namespace Mittwald\Web2pdf\Middleware;

use Mittwald\Web2pdf\Options\ModuleOptions;
use Mittwald\Web2pdf\Utility\FilenameUtility;
use Mittwald\Web2pdf\View\DompdfView;
use Mittwald\Web2pdf\View\MPdfView;
use Mittwald\Web2pdf\View\PdfViewInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class PdfHandler implements MiddlewareInterface
{
    private PdfViewInterface $pdfView;
    private ModuleOptions $moduleOptions;

    protected FilenameUtility $fileNameUtility;

    public function __construct(ModuleOptions $moduleOptions, FilenameUtility $fileNameUtility)
    {
        $this->pdfView = $this->initPdfView();
        $this->moduleOptions = $moduleOptions;
        $this->fileNameUtility = $fileNameUtility;
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pluginParams = $request->getQueryParams()['tx_web2pdf_pi1'] ?? null;

        if ($pluginParams === null) {
            return $handler->handle($request);
        }

        if (($pluginParams['argument'] !== ModuleOptions::QUERY_PARAMETER)) {
            return $handler->handle($request);
        }

        /** @var TypoScriptFrontendController $frontendController */
        $frontendController = $request->getAttribute('frontend.controller');
        if (!isset($frontendController->cObj)) {
            $frontendController->newCObj($request);
        }
        $hash = $frontendController->newHash . '_web2pdf';

        $response = new Response();

        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cache = $cacheManager->getCache('web2pdf_pdf');
        $cachedData = $cache->get($hash);
        $context = GeneralUtility::makeInstance(Context::class);
        $isBackendUserLoggedIn = $context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
        if (is_array($cachedData) && !$isBackendUserLoggedIn) {
            $content = $cachedData['content'];
            $fileName = $cachedData['filename'];
            $cacheExpires = $cachedData['expires'];
        } else {
            $output = $handler->handle($request);
            ob_clean();
            $pageTitle = $frontendController->generatePageTitle();
            $fileName = $this->fileNameUtility->convert($pageTitle) . '.pdf';
            $file = $this->pdfView->renderHtmlOutput($output->getBody(), $frontendController->id);
            $content = file_get_contents($file);

            $cacheTimeout = $frontendController->get_cache_timeout();
            $cacheExpires = $cacheTimeout + $GLOBALS['EXEC_TIME'];
            $cacheData = [
                'content' => $content,
                'filename' =>  $fileName,
                'expires' => $cacheExpires,
            ];
            $pageCacheTags = [];
            $cache->set($hash, $cacheData, $pageCacheTags, $cacheTimeout);
        }

        $destination = $this->moduleOptions->getPdfDestination() ?? 'attachment';

        $response = $response->withHeader('Content-Transfer-Encoding', 'binary');
        $response->getBody()->write($content);
        $headers = $this->getCacheHeaders($frontendController, $context, $content, $cacheExpires);
        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }
        $response = $response->withHeader('Content-Type', 'application/pdf');
        return $response->withHeader('Content-Disposition', $destination . '; filename="' . $fileName . '"');
    }

    protected function initPdfView(): PdfViewInterface
    {
        $pdfLibrary = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('web2pdf')['pdfLibrary'] ?? '';
        switch ($pdfLibrary) {
            case 'mpdf':
                if (!class_exists('Mpdf\Mpdf')) {
                    throw new \RuntimeException('Package mpdf/mpdf is not installed. Please run composer require mpdf/mpdf');
                }
                $pdfView = GeneralUtility::makeInstance(MPdfView::class);
                break;
            case 'dompdf':
                if (!class_exists('Dompdf\Dompdf')) {
                    throw new \RuntimeException('Package dompdf/dompdf is not installed. Please run composer require dompdf/dompdf');
                }
                $pdfView = GeneralUtility::makeInstance(DompdfView::class);
                break;
            default:
                throw new \RuntimeException('PDF library not supported');
        }
        return $pdfView;
    }

    protected function getCacheHeaders(TypoScriptFrontendController $frontendController, $context, $content, $cacheExpires): array
    {
        $headers = [];
        // Getting status whether we can send cache control headers for proxy caching:
        $doCache = !$frontendController->no_cache && !$context->getAspect('frontend.user')->isUserOrGroupSet();
        $isBackendUserLoggedIn = $context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
        $isInWorkspace = $context->getPropertyFromAspect('workspace', 'isOffline', false);
        // Finally, when backend users are logged in, do not send cache headers at all (Admin Panel might be displayed for instance).
        $isClientCachable = $doCache && !$isBackendUserLoggedIn && !$isInWorkspace;
        if ($isClientCachable) {
            // Only send the headers to the client that they are allowed to cache if explicitly activated.
            if (!empty($frontendController->config['config']['sendCacheHeaders'])) {
                $headers = [
                    'Expires' => gmdate('D, d M Y H:i:s T', $cacheExpires),
                    'ETag' => '"' . md5($content) . '"',
                    'Cache-Control' => 'max-age=' . ($cacheExpires - $GLOBALS['EXEC_TIME']),
                    // no-cache
                    'Pragma' => 'public',
                ];
            }
        } else {
            // "no-store" is used to ensure that the client HAS to ask the server every time, and is not allowed to store anything at all
            $headers = [
                'Cache-Control' => 'private, no-store',
            ];
        }
        return $headers;
    }
}
