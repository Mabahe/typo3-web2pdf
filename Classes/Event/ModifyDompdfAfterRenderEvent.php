<?php

namespace Mittwald\Web2pdf\Event;

use Dompdf\Dompdf;

final class ModifyDompdfAfterRenderEvent
{
    public function __construct(private Dompdf $dompdf) {}

    public function getDompdf(): Dompdf
    {
        return $this->dompdf;
    }

}
