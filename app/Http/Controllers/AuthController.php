<?php

namespace App\Http\Controllers;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    public function showRegistration()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
    'email' => [
        'required',
        'string',
        'email',
        'max:255',
        'unique:users,email',
        'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/',
    ],
    'password' => 'required|string|min:8|confirmed',
], [
    'email.regex' => 'Please use a valid Gmail address (must end with @gmail.com).',
]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except('password'));
        }

        /*
        |--------------------------------------------------------------------------
        | Create User
        |--------------------------------------------------------------------------
        |
        | Password is automatically hashed by User model:
        |
        | 'password' => 'hashed'
        |
        */

        /*
         * Explicitly set role=citizen and verification_status=pending so the
         * admin queue picks the application up the moment the citizen submits
         * the verification form (the admin index filters on pending + non-null
         * verification_requested_at). Relying on the DB default hides intent
         * and breaks when the column default drifts.
         */
        $user = User::create([
            'name' => 'User Name',
            'email' => $request->email,
            'role' => 'citizen',
            'verification_status' => 'pending',
            'password' => $request->password,
        ]);

        Auth::login($user);

        /*
        |--------------------------------------------------------------------------
        | Send Verification Link + OTP
        |--------------------------------------------------------------------------
        */

        $this->sendVerificationOtpAndEmail($user);

        return redirect()
            ->route('verification.notice')
            ->with(
                'success',
                'A verification link and OTP have been sent to your email address.'
            );
    }


    /*
    |--------------------------------------------------------------------------
    | Login
    |--------------------------------------------------------------------------
    */

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except('password'));
        }

        $credentials = [
            'email' => $request->email,
            'password' => $request->password,
        ];

        if (!Auth::attempt(
            $credentials,
            $request->boolean('remember')
        )) {
            return back()
                ->withErrors([
                    'email' => 'The provided credentials do not match our records.',
                ])
                ->withInput($request->except('password'));
        }

        $request->session()->regenerate();

        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        if ($user->isAdmin()) {
            return redirect()->intended(
                route('admin.dashboard')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Email Verification
        |--------------------------------------------------------------------------
        */

        if (
            $user->isCitizen() &&
            !$user->hasVerifiedEmail()
        ) {
            return redirect()
                ->route('verification.notice')
                ->with(
                    'warning',
                    'Please verify your email address before continuing.'
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Citizen Dashboard
        |--------------------------------------------------------------------------
        */

        return redirect()->intended(
            route('citizen.dashboard')
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }


    /*
    |--------------------------------------------------------------------------
    | EMAIL VERIFICATION
    |--------------------------------------------------------------------------
    */

    /**
     * Generate OTP and send verification email.
     */
    private function sendVerificationOtpAndEmail(User $user): void
    {
        /*
        |--------------------------------------------------------------------------
        | Delete Previous Active OTPs
        |--------------------------------------------------------------------------
        */

        EmailVerificationOtp::where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();


        /*
        |--------------------------------------------------------------------------
        | Generate 6 Digit OTP
        |--------------------------------------------------------------------------
        */

        $otp = (string) random_int(
            100000,
            999999
        );


        /*
        |--------------------------------------------------------------------------
        | Store Hashed OTP
        |--------------------------------------------------------------------------
        */

        EmailVerificationOtp::create([
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);


        /*
        |--------------------------------------------------------------------------
        | Create Signed Verification URL
        |--------------------------------------------------------------------------
        */

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1(
                    $user->getEmailForVerification()
                ),
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Send Email
        |--------------------------------------------------------------------------
        */

        Mail::to($user->email)->send(
            new EmailVerificationMail(
                $user,
                $verificationUrl,
                $otp
            )
        );
    }


    /**
     * Resend verification email and OTP.
     */
    public function resendVerificationEmail(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('login');
        }

        /*
        |--------------------------------------------------------------------------
        | Already Verified
        |--------------------------------------------------------------------------
        */

        if ($user->hasVerifiedEmail()) {
            return redirect()
                ->route('citizen.dashboard')
                ->with(
                    'success',
                    'Your email is already verified.'
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate New OTP + Verification Link
        |--------------------------------------------------------------------------
        */

        $this->sendVerificationOtpAndEmail($user);

        return back()->with(
            'success',
            'A new verification link and OTP have been sent to your email address.'
        );
    }


    /**
     * Verify email using OTP.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => [
                'required',
                'digits:6',
            ],
        ]);

        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | User Must Be Logged In
        |--------------------------------------------------------------------------
        */

        if (!$user) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Please login first.',
                ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Already Verified
        |--------------------------------------------------------------------------
        */

        if ($user->hasVerifiedEmail()) {
            return redirect()
                ->route('citizen.dashboard')
                ->with(
                    'success',
                    'Your email is already verified.'
                );
        }


        /*
        |--------------------------------------------------------------------------
        | Get Latest Active OTP
        |--------------------------------------------------------------------------
        */

        $verificationOtp = EmailVerificationOtp::where(
            'user_id',
            $user->id
        )
            ->whereNull('used_at')
            ->latest('created_at')
            ->first();


        if (!$verificationOtp) {
            return back()->withErrors([
                'otp' =>
                    'No active OTP was found. Please request a new verification code.',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Check Expiration
        |--------------------------------------------------------------------------
        */

        if ($verificationOtp->expires_at->isPast()) {
            return back()->withErrors([
                'otp' =>
                    'This OTP has expired. Please request a new verification code.',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Maximum Attempts
        |--------------------------------------------------------------------------
        */

        if ($verificationOtp->attempts >= 5) {
            return back()->withErrors([
                'otp' =>
                    'Too many incorrect attempts. Please request a new OTP.',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Check OTP Hash
        |--------------------------------------------------------------------------
        */

        if (!Hash::check(
            $request->otp,
            $verificationOtp->otp_hash
        )) {

            $verificationOtp->increment('attempts');

            return back()->withErrors([
                'otp' => 'Invalid verification code.',
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | OTP Correct
        |--------------------------------------------------------------------------
        */

        $verificationOtp->update([
            'used_at' => now(),
        ]);


        /*
        |--------------------------------------------------------------------------
        | Mark Email As Verified
        |--------------------------------------------------------------------------
        */

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }


        /*
        |--------------------------------------------------------------------------
        | Remove Other Active OTPs
        |--------------------------------------------------------------------------
        */

        EmailVerificationOtp::where(
            'user_id',
            $user->id
        )
            ->whereNull('used_at')
            ->where(
                'id',
                '!=',
                $verificationOtp->id
            )
            ->delete();


        /*
        |--------------------------------------------------------------------------
        | Redirect
        |--------------------------------------------------------------------------
        */

        return redirect()
            ->route('citizen.dashboard')
            ->with(
                'success',
                'Your email has been successfully verified.'
            );
    }


    /*
    |--------------------------------------------------------------------------
    | PASSWORD RESET
    |--------------------------------------------------------------------------
    */

    /**
     * Show Forgot Password page.
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }


    /**
     * Send password reset email.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with(
                'success',
                'Password reset link has been sent to your email address.'
            );
        }

        return back()
            ->withInput(
                $request->only('email')
            )
            ->withErrors([
                'email' => __($status),
            ]);
    }


    /**
     * Show Reset Password page.
     */
    public function showResetPassword(string $token)
    {
        return view(
            'auth.reset-password',
            [
                'token' => $token,
                'email' => request('email'),
            ]
        );
    }


    /**
     * Reset password.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => [
                'required',
            ],

            'email' => [
                'required',
                'email',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);


        $status = Password::reset(
            $request->only(
                'email',
                'password',
                'password_confirmation',
                'token'
            ),

            function (
                User $user,
                string $password
            ) {

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );


        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with(
                    'success',
                    'Your password has been reset successfully. You can now log in.'
                );
        }


        return back()
            ->withInput(
                $request->only('email')
            )
            ->withErrors([
                'email' => __($status),
            ]);
    }
}