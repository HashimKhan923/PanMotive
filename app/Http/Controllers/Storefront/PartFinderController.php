<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\VehicleMake;

class PartFinderController extends Controller
{
    public function index()
    {
        return view('storefront.find-parts', [
            'categories' => Category::shopRoots()->take(12),
            'makes' => VehicleMake::orderBy('name')->take(24)->get()
                ->filter(fn ($make) => Product::listed()->forVehicleMake($make)->exists())->values(),
        ]);
    }
}
