<?php

namespace Mittwald\Web2pdf\Event;

use Mpdf\Mpdf;

final class ModifyMpdfAfterWriteHtmlEvent
{
    public function __construct(private Mpdf $mpdf) {}

    public function getMpdf(): Mpdf
    {
        return $this->mpdf;
    }

}
