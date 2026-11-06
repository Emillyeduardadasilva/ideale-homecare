<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(RegisterRequest $r)
    {
        $user = User::create([...$r->safe()->only(['name', 'email', 'password']), 'role' => 'family']);
        event(new Registered($user));
        Auth::login($user);
        $r->session()->regenerate();

        return response()->json(['data' => $user], 201);
    }

    public function login(LoginRequest $r)
    {
        $d = $r->validated();
        abort_unless(Auth::attempt(['email' => strtolower(trim($d['email'])), 'password' => $d['password'], 'active' => true]), 422, 'Credenciais inválidas.');
        $r->session()->regenerate();

        return ['data' => $r->user()];
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgot(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['email' => 'required|email']);
        Password::sendResetLink($d);

        return ['message' => 'Se o e-mail estiver cadastrado, você receberá as instruções.'];
    }

    public function reset(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($d, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });
        abort_unless($status === Password::PasswordReset, 422, 'Não foi possível redefinir a senha.');

        return ['message' => 'Senha atualizada.'];
    }

    public function verify(EmailVerificationRequest $r)
    {
        $r->fulfill();

        return ['message' => 'E-mail confirmado.'];
    }

    public function resend(Request $r)
    {
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return ['message' => 'Verifique seu e-mail.'];
    }
}
