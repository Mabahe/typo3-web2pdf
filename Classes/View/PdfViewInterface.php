<?php

namespace Mittwald\Web2pdf\View;

interface PdfViewInterface
{
    public function renderHtmlOutput(string $content, int $pageId);
}
