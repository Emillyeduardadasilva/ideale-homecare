<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'string', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
            'role' => 'family',
        ]);
        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $user, 'message' => 'Conta criada. Confirme seu e-mail para continuar.'], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', Str::lower(trim($data['email'])))->first();
        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['As credenciais informadas são inválidas.']]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([], 204);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $emailChanged = isset($data['email']) && Str::lower(trim($data['email'])) !== $user->email;
        if (isset($data['email'])) {
            $data['email'] = Str::lower(trim($data['email']));
        }
        if ($emailChanged) {
            $data['email_verified_at'] = null;
        }

        $user->fill(array_diff_key($data, ['email_verified_at' => true]));
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'data' => $user->fresh(),
            'message' => $emailChanged
                ? 'Perfil atualizado. Confirme o novo e-mail para continuar.'
                : 'Perfil atualizado com sucesso.',
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'string', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        $sessionId = $request->session()->getId();

        $user->forceFill(['password' => $data['password']])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $sessionId)->delete();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink(['email' => Str::lower(trim($data['email']))]);

        return response()->json(['message' => 'Se houver uma conta com esse e-mail, enviaremos as instruções de recuperação.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'string', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $data['email'] = Str::lower(trim($data['email']));

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['O link de recuperação é inválido ou expirou. Solicite outro.']]);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Se necessário, enviamos um novo link de confirmação.'], 202);
    }

    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'E-mail confirmado com sucesso.']);
    }
}