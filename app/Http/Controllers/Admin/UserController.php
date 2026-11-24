<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ActorContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 帶位人員帳號由店長建立（系統不開放自行註冊）。
 */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::query()->orderBy('role')->orderBy('name')->get(['id', 'name', 'email', 'role']),
        ]);
    }

    public function store(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['host', 'manager'])],
        ], [], ['name' => '姓名', 'email' => 'Email', 'password' => '密碼', 'role' => '角色']);

        $user = User::create($data);
        $logger->record(ActorContext::fromRequest($request), 'user.created', $user, null, null, ['email' => $user->email, 'role' => $user->role]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "已建立 {$user->name} 的帳號"]);

        return back();
    }
}
