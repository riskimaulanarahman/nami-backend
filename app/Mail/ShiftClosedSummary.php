<?php

namespace App\Mail;

use App\Models\CashierShift;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShiftClosedSummary extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CashierShift $shift,
    ) {}

    public function envelope(): Envelope
    {
        $businessName = $this->shift->tenant->name ?? config('app.name');
        $date         = $this->shift->closed_at?->setTimezone('Asia/Jakarta')->format('d M Y') ?? now()->format('d M Y');
        $staffName    = $this->shift->staff_name;

        return new Envelope(
            subject: "[{$businessName}] Ringkasan Shift — {$date}, {$staffName}",
        );
    }

    public function content(): Content
    {
        $shift    = $this->shift;
        $expenses = $shift->expenses()->orderBy('created_at')->get();

        $openedAt  = $shift->opened_at->setTimezone('Asia/Jakarta')->format('d M Y, H:i');
        $closedAt  = $shift->closed_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i') ?? '-';
        $variance  = $shift->variance_cash ?? 0;

        return new Content(
            htmlString: $this->buildHtml($shift, $expenses, $openedAt, $closedAt, $variance),
        );
    }

    private function buildHtml(CashierShift $shift, $expenses, string $openedAt, string $closedAt, int $variance): string
    {
        $businessName    = e($shift->tenant->name ?? config('app.name'));
        $staffName       = e($shift->staff_name);
        $varianceColor   = $variance >= 0 ? '#059669' : '#dc2626';
        $varianceLabel   = $variance >= 0 ? 'Lebih' : 'Kurang';
        $varianceAbs     = abs($variance);

        $expenseRows = '';
        foreach ($expenses as $exp) {
            $categoryLabel = $this->categoryLabel($exp->category);
            $expenseRows .= sprintf(
                '<tr><td style="padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#475569;">%s</td><td style="padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#64748b;">%s</td><td style="padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#475569;text-align:right;">%s</td></tr>',
                e($exp->description),
                e($categoryLabel),
                $this->formatRupiah($exp->amount),
            );
        }

        $expenseSection = '';
        if ($expenses->count() > 0) {
            $expenseSection = <<<HTML
            <tr><td colspan="2" style="padding:16px 0 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:#94a3b8;">Detail Pengeluaran</td></tr>
            <tr><td colspan="2" style="padding:0;">
              <table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;border:1px solid #e2e8f0;border-collapse:collapse;overflow:hidden;">
                <thead>
                  <tr style="background:#f8fafc;">
                    <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:left;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Keterangan</th>
                    <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:left;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Kategori</th>
                    <th style="padding:8px 12px;font-size:11px;font-weight:700;text-align:right;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;">Jumlah</th>
                  </tr>
                </thead>
                <tbody>
                  {$expenseRows}
                </tbody>
              </table>
            </td></tr>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Ringkasan Shift</title>
        </head>
        <body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px;">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

                <!-- Header -->
                <tr><td style="background:#0f172a;padding:28px 32px;border-radius:16px 16px 0 0;">
                  <p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.2em;color:#94a3b8;">{$businessName}</p>
                  <h1 style="margin:6px 0 0;font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Ringkasan Shift Kasir</h1>
                </td></tr>

                <!-- Body -->
                <tr><td style="background:#ffffff;padding:32px;border-radius:0 0 16px 16px;">

                  <!-- Info Shift -->
                  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;width:50%;">Kasir</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$staffName}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Waktu Buka</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$openedAt}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Waktu Tutup</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$closedAt}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Jumlah Transaksi</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$shift->transaction_count} transaksi</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Jumlah Refund</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$shift->refund_count} refund</td>
                    </tr>
                  </table>

                  <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 24px;">

                  <!-- Ringkasan Kas -->
                  <p style="margin:0 0 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:#94a3b8;">Ringkasan Kas</p>
                  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Modal Awal</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$this->formatRupiah($shift->opening_cash)}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Penjualan Tunai</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#059669;text-align:right;">+ {$this->formatRupiah($shift->cash_sales)}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Penjualan Non-Tunai</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$this->formatRupiah($shift->non_cash_sales)}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Refund Tunai</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#dc2626;text-align:right;">− {$this->formatRupiah($shift->cash_refunds)}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 0;font-size:13px;color:#64748b;">Total Pengeluaran</td>
                      <td style="padding:6px 0;font-size:13px;font-weight:600;color:#dc2626;text-align:right;">− {$this->formatRupiah($shift->total_expenses)}</td>
                    </tr>
                    <tr style="background:#f8fafc;border-radius:8px;">
                      <td style="padding:10px 8px;font-size:14px;font-weight:700;color:#0f172a;border-top:2px solid #e2e8f0;">Ekspektasi Kas</td>
                      <td style="padding:10px 8px;font-size:14px;font-weight:700;color:#0f172a;text-align:right;border-top:2px solid #e2e8f0;">{$this->formatRupiah($shift->expected_cash)}</td>
                    </tr>
                    <tr>
                      <td style="padding:6px 8px;font-size:13px;color:#64748b;">Kas Fisik (dihitung)</td>
                      <td style="padding:6px 8px;font-size:13px;font-weight:600;color:#0f172a;text-align:right;">{$this->formatRupiah($shift->closing_cash ?? 0)}</td>
                    </tr>
                    <tr>
                      <td style="padding:10px 8px;font-size:15px;font-weight:800;color:{$varianceColor};">Selisih ({$varianceLabel})</td>
                      <td style="padding:10px 8px;font-size:15px;font-weight:800;color:{$varianceColor};text-align:right;">{$this->formatRupiah($varianceAbs)}</td>
                    </tr>
                  </table>

                  {$expenseSection}

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

    private function formatRupiah(int $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'operational' => 'Operasional',
            'supplies'    => 'Bahan & Perlengkapan',
            'utilities'   => 'Listrik / Air / Internet',
            'transport'   => 'Transportasi',
            'food_staff'  => 'Konsumsi Karyawan',
            default       => 'Lainnya',
        };
    }
}
