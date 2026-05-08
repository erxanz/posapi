<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'cancel_qty' => 'required|integer|min:1',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
