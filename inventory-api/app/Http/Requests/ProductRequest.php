<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->sku)) {
            $this->merge(['sku' => strtoupper(trim($this->sku))]);
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'required', 'string', 'max:255'],
            'sku' => [$required, 'required', 'string', 'max:100', Rule::unique('products')->ignore($this->route('product'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => [$required, 'required', 'integer', 'exists:categories,id'],
            'price' => [$required, 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'stock' => [$required, 'required', 'integer', 'min:0', 'max:4294967295'],
            'supplier_ids' => ['sometimes', 'array', 'max:100'],
            'supplier_ids.*' => ['required', 'integer', 'distinct', 'exists:suppliers,id'],
        ];
    }
}
