<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

/** PRESCRIPTION.md §7.4: called once at issue; the data URI is frozen into snapshot.qr.svg_data_uri. */
final class QrCodeRenderer
{
    public static function svgDataUri(string $url): string
    {
        $result = (new Builder(
            writer: new SvgWriter,
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();

        return $result->getDataUri();
    }
}
