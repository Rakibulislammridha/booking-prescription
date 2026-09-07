<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Models\Tenant\Branch;
use App\Tenancy\Facades\Tenancy;

/**
 * Token slip templates (OFFLINE §10): 58 mm / 80 mm thermal and A5, HTML + CSS with `{{placeholder}}` slots the desk
 * fills locally (offline too). Bangla renders through the precached Noto Sans Bengali. Versioned so the PWA's
 * StaleWhileRevalidate cache knows when to refresh.
 */
final class PrintTemplates
{
    public const VERSION = 3;

    /** @return array<int, array{id: string, version: int, html: string, css: string}> */
    public function all(Branch $branch): array
    {
        return array_map(fn (string $id) => ['id' => $id, 'version' => self::VERSION, 'html' => $this->html($branch), 'css' => $this->css($id)], ['58', '80', 'a5']);
    }

    /** The branch's configured slip width (branches.settings.token_slip_width_mm: 58 | 80 | 148 = A5). */
    public function defaultFormat(Branch $branch): string
    {
        $width = (int) (($branch->settings ?? [])['token_slip_width_mm'] ?? 58);

        return match (true) {
            $width >= 140 => 'a5',
            $width >= 80 => '80',
            default => '58',
        };
    }

    private function html(Branch $branch): string
    {
        $clinic = e((string) (Tenancy::current()->name ?? ''));
        $branchName = e($branch->name);
        $phone = e((string) ($branch->phone ?? ''));

        return <<<HTML
<div class="slip" lang="{{lang}}">
  <div class="head"><div class="clinic">{$clinic}</div><div class="branch">{$branchName}</div><div class="phone">{$phone}</div></div>
  <div class="doctor">{{doctor}}</div>
  <div class="session">{{session_label}} · {{date}}</div>
  <div class="code">{{code}}</div>
  <div class="patient">{{patient}}</div>
  <div class="meta"><span>{{ahead_label}}</span><span>{{eta_label}}</span></div>
  <div class="fee">{{fee_label}}</div>
  <div class="receipt">{{receipt_label}}</div>
  <div class="qr">{{qr}}</div>
  <div class="url">{{queue_url}}</div>
  <div class="offline">{{offline_marker}}</div>
  <div class="foot">{{footer}}</div>
</div>
HTML;
    }

    private function css(string $id): string
    {
        $page = match ($id) {
            '80' => "@page { size: 80mm auto; margin: 3mm } body { width: 74mm; font: 12pt/1.3 'Noto Sans Bengali','Inter',sans-serif }",
            'a5' => "@page { size: A5; margin: 10mm } body { font: 12pt/1.4 'Noto Sans Bengali','Inter',sans-serif }",
            default => "@page { size: 58mm auto; margin: 2mm } body { width: 54mm; font: 11pt/1.25 'Noto Sans Bengali','Inter',sans-serif }",
        };

        return $page.' '.<<<'CSS'
body { margin: 0; color: #000; background: #fff }
.slip { text-align: center }
.head .clinic { font-weight: 700; font-size: 1.1em }
.head .branch, .head .phone, .session, .meta, .url, .foot { font-size: 0.85em }
.doctor { margin-top: 2mm; font-weight: 600 }
.code { font-size: 34pt; font-weight: 700; letter-spacing: .04em; text-align: center; margin: 2mm 0 }
.patient { font-weight: 600 }
.meta { display: flex; justify-content: space-between; margin-top: 1mm }
.fee, .receipt { margin-top: 1mm }
.qr { width: 28mm; margin: 2mm auto }
.qr svg, .qr img { width: 28mm; height: 28mm }
.offline { font-size: 0.8em; font-weight: 600; margin-top: 1mm }
.foot { margin-top: 2mm; border-top: 1px dashed #000; padding-top: 1mm }
CSS;
    }
}
