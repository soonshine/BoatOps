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
    public function create(Request $r): View|RedirectResponse
    {
        if (! Auth::check()) {
            return view('operator.login');
        }

        if (DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->exists()) {
            return redirect()->route('operator.calendar');
        }

        $this->clearSession($r);

        return view('operator.login');
    }

    public function store(Request $r): RedirectResponse
    {
        $c = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($c, false) || ! DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->exists()) {
            $this->clearSession($r);
            throw ValidationException::withMessages(['email' => ['The provided operator credentials are invalid.']]);
        }$r->session()->regenerate();

        return redirect()->intended(route('operator.calendar'));
    }

    public function destroy(Request $r): RedirectResponse
    {
        $this->clearSession($r);

        return redirect()->route('operator.login');
    }

    private function clearSession(Request $r): void
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
    }
}
