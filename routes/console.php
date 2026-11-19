<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:create-admin', function () {
    $name = \Laravel\Prompts\text('Administrator name', required: true);
    $email = Str::lower(\Laravel\Prompts\text('Email', required: true, validate: ['email', 'unique:users,email']));
    $password = \Laravel\Prompts\password('Password (at least 12 characters)', required: true, validate: ['min:12']);
    User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true, 'email_verified_at' => now()]);
    $this->info('Administrator created.');
})->purpose('Create an administrator interactively without storing a plaintext password');
