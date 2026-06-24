<?php

declare(strict_types=1);

namespace Mittwald\Web2pdf\View;

use Mittwald\Web2pdf\Options\ModuleOptions;
use Mittwald\Web2pdf\Utility\PdfLinkUtility;

abstract class AbstractPdfView
{
    public const PREG_REPLACEMENT_KEY = 'pregReplacements';
    public const STR_REPLACEMENT_KEY = 'strReplacements';

    protected ModuleOptions $options;

    protected PdfLinkUtility $pdfLinkUtility;

    public function __construct(
        ModuleOptions $options,
        PdfLinkUtility $pdfLinkUtility,
    ) {
        $this->options = $options;
        $this->pdfLinkUtility = $pdfLinkUtility;
    }

    /**
     * Replacements of configured strings
     *
     * @param string $content
     * @return string
     */
    protected function replaceStrings(string $content): string
    {
        if (is_array($this->options->getStrReplacements())) {
            foreach ($this->options->getStrReplacements() as $searchString => $replacement) {
                $content = str_replace($searchString, $replacement, $content);
            }
        }

        if (is_array($this->options->getPregReplacements())) {
            foreach ($this->options->getPregReplacements() as $pattern => $patternReplacement) {
                $content = preg_replace($pattern, $patternReplacement, $content);
            }
        }

        return $this->pdfLinkUtility->replace($content);
    }
}
