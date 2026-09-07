<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Services\CanonicalJson;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * PRESCRIPTION.md §7.1 / ARCHITECTURE §8.3 — the one render entry point for print, PDF and /rx/{code}.
 *
 * It receives the frozen `PrescriptionSnapshot` DTO and a `RenderOptions` value object and NOTHING else: no model,
 * no repository, no `catalog` connection (invariant I6, BRIEF §8 "no prescription rendering path joins live to the
 * catalog database"). That is what makes a 2026 prescription still printable, byte for byte, in 2036 after the
 * brand it names has been renamed, re-priced or deactivated in the shared catalog.
 */
final class PrescriptionRenderer
{
    public const TEMPLATE_VERSION = 'print.prescription.v1';

    public function __construct(private readonly ViewFactory $views, private readonly DrawingSvgRenderer $drawings, private readonly PrintFonts $fonts, private readonly PrintImageInliner $images) {}

    public function render(PrescriptionSnapshot $snapshot, RenderOptions $options): string
    {
        return $this->views->make($options->isPharmacy() ? 'print.prescription.pharmacy' : 'print.prescription.sheet', $this->data($snapshot, $options))->render();
    }

    /**
     * The verification page wraps the same sheet in its own chrome (§7.4), so it needs the same view data.
     *
     * @return array<string, mixed>
     */
    public function data(PrescriptionSnapshot $snapshot, RenderOptions $options): array
    {
        $drawingJson = (array) ($snapshot->get('drawing_json') ?? []);
        $document = $snapshot->toArray();

        return [
            'snapshot' => $snapshot,
            'o' => $options,
            'pad' => new PadGeometry($snapshot->pad(), $options),
            'labels' => new PrintLabels($options->language),
            'rx' => $snapshot->prescription(),
            'clinic' => $snapshot->clinic(),
            'doctor' => $snapshot->doctor(),
            'patient' => $snapshot->patient(),
            'visit' => $snapshot->visit(),
            'items' => $snapshot->items(),
            'investigations' => $snapshot->investigations(),
            'advice' => $snapshot->advice(),
            'referrals' => $snapshot->referrals(),
            'followUp' => $snapshot->followUp(),
            'qr' => $snapshot->qr(),
            'handwritingPages' => $this->images->dataUris($snapshot->handwritingPages()),
            'drawingSvg' => $drawingJson === [] ? null : $this->drawings->render($drawingJson),
            'drawingImage' => $drawingJson === [] ? $this->images->dataUri(is_string($snapshot->get('drawing_image_path')) ? $snapshot->get('drawing_image_path') : null) : null,
            // Recomputed from the document itself rather than read off the row: the renderer has no model, and a
            // hash printed next to the QR is only worth anything if it hashes what is actually on the paper.
            'sha256' => $document === [] ? '' : CanonicalJson::sha256($document),
            'fontCss' => $this->fonts->css(inline: $options->purpose === 'pdf'),
        ];
    }
}
