<?php

namespace App\Livewire\Seller;

use App\Jobs\ProcessBulkProductUpload;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class BulkUpload extends Component
{
    use WithFileUploads;

    public $csvFile;

    public bool $processing = false;

    public function upload(): void
    {
        $this->validate(['csvFile' => 'required|mimes:csv,txt|max:10240']);

        $path = $this->csvFile->store('bulk-uploads/'.auth()->id());

        ProcessBulkProductUpload::dispatch(auth()->id(), $path);

        $this->processing = true;
        session()->flash('success', 'File uploaded — processing in the background. You\'ll get a notification when it\'s done.');
    }

    public function render()
    {
        return view('livewire.seller.bulk-upload');
    }
}
