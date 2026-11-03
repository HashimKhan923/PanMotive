<?php

namespace App\Livewire\Seller;

use App\Services\Seller\CatalogBulkUpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Update price, stock and status for many listings at once from a spreadsheet. */
#[Layout('layouts.seller')]
class BulkUpdate extends Component
{
    use WithFileUploads;

    public $sheet;

    /** Server-side key for the previewed change set (the changes themselves never leave the server). */
    public ?string $token = null;

    public function download(CatalogBulkUpdateService $service)
    {
        $rows = $service->exportRows(auth()->id());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens it as UTF-8
            fputcsv($out, CatalogBulkUpdateService::HEADERS);
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'catalog-update-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function check(CatalogBulkUpdateService $service): void
    {
        $this->validate(['sheet' => 'required|file|extensions:csv,txt|max:10240'], [
            'sheet.required' => 'Choose the edited CSV file first.',
            'sheet.extensions' => 'Upload a .csv file (in Excel: File → Save As → CSV).',
        ]);

        $result = $service->preview(auth()->id(), $this->sheet->getRealPath());

        $this->token = Str::random(32);
        Cache::put($this->cacheKey(), $result, now()->addMinutes(30));
        $this->reset('sheet');
    }

    public function apply(CatalogBulkUpdateService $service): void
    {
        $result = $this->token ? Cache::get($this->cacheKey()) : null;
        if (! $result || ! $result['changes']) {
            $this->dispatch('toast', message: 'That preview has expired — upload the file again.', kind: 'error');
            $this->token = null;

            return;
        }

        $n = $service->apply(auth()->id(), $result['changes']);
        Cache::forget($this->cacheKey());
        $this->token = null;

        $this->dispatch('toast', message: "Updated {$n} ".Str::plural('listing', $n).'. Stock changes are in the movement log.');
        $this->dispatch('celebrate');
    }

    public function startOver(): void
    {
        if ($this->token) {
            Cache::forget($this->cacheKey());
        }
        $this->reset('token', 'sheet');
    }

    private function cacheKey(): string
    {
        return 'catalog-bulk-update:'.auth()->id().':'.$this->token;
    }

    public function render()
    {
        return view('livewire.seller.bulk-update', [
            'result' => $this->token ? Cache::get($this->cacheKey()) : null,
        ]);
    }
}
