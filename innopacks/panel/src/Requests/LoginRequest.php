<?php
/**
 * Copyright (c) Since 2024 InnoShop - All Rights Reserved
 *
 * @link       https://www.innoshop.com
 * @author     InnoShop <team@innoshop.com>
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace InnoShop\Panel\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Keep the legacy "email" field working for API/automation clients.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('account') && $this->filled('email')) {
            $this->merge(['account' => $this->input('email')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'account'  => 'required|string|max:64',
            'password' => 'required',
        ];
    }

    /**
     * @return array
     */
    public function attributes(): array
    {
        return [
            'account'  => panel_trans('login.account'),
            'password' => panel_trans('login.password'),
        ];
    }

    /**
     * Admin guard credentials: match by email or username depending on input shape.
     *
     * @return array
     */
    public function credentials(): array
    {
        $account = trim((string) $this->input('account'));
        $field   = filter_var($account, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [$field => $account, 'password' => $this->input('password')];
    }
}
