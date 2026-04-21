<?php

namespace App\Http\Requests\Table;

use App\Enums\BillingMode;
use App\Enums\SessionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'session_type' => ['required', Rule::in(SessionType::cases())],
            'billing_mode' => ['nullable', Rule::in(BillingMode::cases())],
            'package_id' => ['nullable', Rule::exists('billiard_packages', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
