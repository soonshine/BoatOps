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
        if (! Auth::check() && config('auth.prelaunch_passwordless', true)) {
            $operatorEmail = config('auth.prelaunch_operator_email');
            $memberships = DB::table('operator_memberships')
                ->join('users', 'users.id', '=', 'operator_memberships.user_id')
                ->where('operator_memberships.status', 'ACTIVE')
                ->when(
                    is_string($operatorEmail) && trim($operatorEmail) !== '',
                    fn ($query) => $query->whereRaw('LOWER(users.email) = ?', [strtolower(trim($operatorEmail))]),
                )
                ->select('operator_memberships.*')
                ->get();

            // Refuse to guess when the prelaunch selector is ambiguous.
            if ($memberships->count() === 1) {
                $membership = $memberships->first();
                Auth::loginUsingId((int) $membership->user_id);
                $r->session()->regenerate();
                $route = $this->firstGrantedRoute($membership);

                if ($route !== null) {
                    return redirect()->route($route);
                }

                $this->clearSession($r);
            }
        }

        if (! Auth::check()) {
            return view('operator.login');
        }

        $membership = DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->first();
        $route = $this->firstGrantedRoute($membership);
        if ($route !== null) {
            return redirect()->route($route);
        }

        $this->clearSession($r);

        return view('operator.login');
    }

    public function store(Request $r): RedirectResponse
    {
        $c = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($c, false)) {
            $this->clearSession($r);
            throw ValidationException::withMessages(['email' => ['The provided operator credentials are invalid.']]);
        }
        $membership = DB::table('operator_memberships')->where('user_id', Auth::id())->where('status', 'ACTIVE')->first();
        $route = $this->firstGrantedRoute($membership);
        if ($route === null) {
            $this->clearSession($r);
            throw ValidationException::withMessages(['email' => ['The provided operator credentials are invalid.']]);
        }
        $r->session()->regenerate();

        return redirect()->route($route);
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

    private function firstGrantedRoute(?object $membership): ?string
    {
        return match (true) {
            (bool) ($membership?->can_calendar_read ?? false) => 'operator.calendar',
            (bool) ($membership?->can_booking_workflow ?? false) => 'operator.inquiries.index',
            (bool) ($membership?->can_block ?? false) => 'operator.blocks.index',
            default => null,
        };
    }
}
