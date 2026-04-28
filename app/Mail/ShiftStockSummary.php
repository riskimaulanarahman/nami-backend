<?php

namespace App\Mail;

use App\Models\CashierShift;
use App\Models\Ingredient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ShiftStockSummary extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CashierShift $shift,
    ) {}

    public function envelope(): Envelope
    {
        $businessName = $this->shift->tenant->name ?? config('app.name');
        $date         = $this->shift->closed_at?->setTimezone('Asia/Jakarta')->format('d M Y') ?? now()->format('d M Y');

        return new Envelope(
            subject: "[{$businessName}] Laporan Stok Inventory — {$date}",
        );
    }

    public function content(): Content
    {
        $shift    = $this->shift;
        $tenantId = $shift->tenant_id;

        $lowStockItems = Ingredient::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('name')
            ->get();

        $allItems = Ingredient::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $closedAt     = $shift->closed_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i') ?? '-';
        $businessName = $shift->tenant->name ?? config('app.name');

        return new Content(
            htmlString: $this->buildHtml($businessName, $closedAt, $lowStockItems, $allItems),
        );
    }

    private function buildHtml(
        string $businessName,
        string $closedAt,
        Collection $lowStockItems,
        Collection $allItems,
    ): string {
        $businessName = e($businessName);

        $lowStockSection = $this->buildLowStockSection($lowStockItems);
        $allStockSection = $this->buildAllStockSection($allItems);

        $lowStockCount = $lowStockItems->count();
        $badgeColor    = $lowStockCount > 0 ? '#dc2626' : '#059669';
        $badgeLabel    = $lowStockCount > 0
            ? "{$lowStockCount} bahan stok menipis"
            : 'Semua stok aman';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Laporan Stok Inventory</title>
        </head>
        <body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

                <!-- Header -->
                <tr><td style="background:#0f172a;padding:28px 32px;border-radius:16px 16px 0 0;">
                  <p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;">{$businessName}</p>
                  <h1 style="margin:6px 0 0;font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Laporan Stok Inventory</h1>
                  <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Per penutupan shift: {$closedAt}</p>
                </td></tr>

                <!-- Body -->
                <tr><td style="background:#ffffff;padding:32px;border-radius:0 0 16px 16px;">

                  <!-- Status Badge -->
                  <div style="display:inline-block;background:{$badgeColor};color:#ffffff;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;margin-bottom:24px;">{$badgeLabel}</div>

                  {$lowStockSection}
                  {$allStockSection}

                </td></tr>

                <!-- Footer -->
                <tr><td style="padding:20px 0;text-align:center;">
                  <p style="margin:0;font-size:12px;color:#94a3b8;">Dikirim otomatis oleh sistem Nami POS</p>
                </td></tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private function buildLowStockSection(Collection $items): string
    {
        if ($items->isEmpty()) {
            return <<<HTML
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px 20px;margin-bottom:24px;">
              <p style="margin:0;font-size:14px;font-weight:600;color:#15803d;">Semua bahan dalam kondisi stok aman.</p>
            </div>
            HTML;
        }

        $rows = '';
        foreach ($items as $item) {
            $unit     = $item->unit->value;
            $stock    = number_format($item->stock, 2, ',', '.') . ' ' . $unit;
            $minStock = number_format($item->min_stock, 2, ',', '.') . ' ' . $unit;
            $name     = e($item->name);

            $rows .= <<<HTML
            <tr>
              <td style="padding:8px 12px;border-bottom:1px solid #fecaca;font-size:13px;color:#0f172a;font-weight:600;">{$name}</td>
              <td style="padding:8px 12px;border-bottom:1px solid #fecaca;font-size:13px;color:#dc2626;font-weight:700;text-align:center;">{$stock}</td>
              <td style="padding:8px 12px;border-bottom:1px solid #fecaca;font-size:13px;color:#64748b;text-align:center;">{$minStock}</td>
            </tr>
            HTML;
        }

        return <<<HTML
        <p style="margin:0 0 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:#dc2626;">Stok Menipis</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;border:1px solid #fecaca;border-collapse:collapse;overflow:hidden;margin-bottom:28px;">
          <thead>
            <tr style="background:#fef2f2;">
              <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:left;color:#dc2626;text-transform:uppercase;letter-spacing:0.08em;">Bahan</th>
              <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:center;color:#dc2626;text-transform:uppercase;letter-spacing:0.08em;">Stok Saat Ini</th>
              <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:center;color:#dc2626;text-transform:uppercase;letter-spacing:0.08em;">Batas Min.</th>
            </tr>
          </thead>
          <tbody>
            {$rows}
          </tbody>
        </table>
        HTML;
    }

    private function buildAllStockSection(Collection $items): string
    {
        if ($items->isEmpty()) {
            return '';
        }

        $rows = '';
        foreach ($items as $item) {
            $isLow    = $item->stock <= $item->min_stock;
            $unit     = $item->unit->value;
            $stock    = number_format($item->stock, 2, ',', '.') . ' ' . $unit;
            $name     = e($item->name);
            $rowBg    = $isLow ? 'background:#fff7f7;' : '';
            $stockColor = $isLow ? 'color:#dc2626;font-weight:700;' : 'color:#059669;font-weight:600;';
            $badge    = $isLow ? ' <span style="font-size:10px;background:#fecaca;color:#dc2626;padding:1px 6px;border-radius:4px;font-weight:700;margin-left:4px;">MENIPIS</span>' : '';

            $rows .= <<<HTML
            <tr style="{$rowBg}">
              <td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#0f172a;">{$name}{$badge}</td>
              <td style="padding:8px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;{$stockColor}text-align:right;">{$stock}</td>
            </tr>
            HTML;
        }

        return <<<HTML
        <p style="margin:0 0 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:#94a3b8;">Semua Bahan Aktif</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;border:1px solid #e2e8f0;border-collapse:collapse;overflow:hidden;">
          <thead>
            <tr style="background:#f8fafc;">
              <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:left;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Nama Bahan</th>
              <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:right;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Stok</th>
            </tr>
          </thead>
          <tbody>
            {$rows}
          </tbody>
        </table>
        HTML;
    }
}
