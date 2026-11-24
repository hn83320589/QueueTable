<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 展示用帳號（密碼皆為 password）與兩台帶位平板。
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        User::create(['name' => '王店長', 'email' => 'manager@queuetable.test', 'role' => 'manager', 'password' => $password]);
        User::create(['name' => '帶位小陳', 'email' => 'host1@queuetable.test', 'role' => 'host', 'password' => $password]);
        User::create(['name' => '帶位小林', 'email' => 'host2@queuetable.test', 'role' => 'host', 'password' => $password]);

        Device::create(['name' => '平板 A']);
        Device::create(['name' => '平板 B']);
    }
}
