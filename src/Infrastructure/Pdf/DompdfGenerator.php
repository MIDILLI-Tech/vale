<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Pdf;

final class DompdfGenerator implements PdfGeneratorInterface
{
    #[\Override]
    public function generate(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        return $dompdf->output();
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return class_exists(\Dompdf\Dompdf::class) && extension_loaded('dom');
    }
}
