<?php

declare(strict_types=1);

/**
 * English validation (skeleton stub - Laravel default).
 * For full translation, rely on `vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php`.
 */
return [

    'required' => 'The :attribute field is required.',
    'email' => 'The :attribute must be a valid email address.',
    'unique' => 'The :attribute has already been taken.',
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must be at least :min characters.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'string' => 'The :attribute may not be greater than :max characters.',
        'array' => 'The :attribute may not have more than :max items.',
    ],
    'numeric' => 'The :attribute must be a number.',
    'alpha_dash' => 'The :attribute may only contain letters, numbers, dashes and underscores.',
    'in' => 'The selected :attribute is invalid.',

    'custom' => [
        'tenant_id' => [
            'required' => 'The tenant id is required.',
        ],
        'role' => [
            'in' => 'Invalid role.',
        ],
    ],

    'attributes' => [
        'name' => 'name',
        'email' => 'email',
        'password' => 'password',
        'symbol' => 'symbol',
        'interval' => 'interval',
        'status' => 'status',
        'plan_code' => 'plan',
        'tenant_id' => 'tenant',
        'role' => 'role',
    ],

];
