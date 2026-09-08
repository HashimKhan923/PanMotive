<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class ProductFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'sku' => 'nullable|string|max:100',
            'part_number' => 'nullable|string|max:100',
            'oem_number' => 'nullable|string|max:100',
            'condition' => 'required|in:new,used,refurbished',
            'price' => 'required|numeric|min:0.01',
            'sale_price' => 'nullable|numeric|lt:price',
            'quantity' => 'required|integer|min:0',
            'description' => 'nullable|string',
            'fitment_vehicle_ids' => 'nullable|array',
            'fitment_vehicle_ids.*' => 'exists:vehicles,id',
        ];
    }
}
