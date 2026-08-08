<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class OperatorSessionController extends Controller
{
    public function create(): View|RedirectResponse
    {
        return Auth::check() ? redirect()->route('operator.calendar') : view('operator.login');
    }

    public function store(Request $r): RedirectResponse
    {
        $c = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($c, false) || ! DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->exists()) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => ['The provided operator credentials are invalid.']]);
        }$r->session()->regenerate();

        return redirect()->intended(route('operator.calendar'));
    }

    public function destroy(Request $r): RedirectResponse
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('operator.login');
    }
}
