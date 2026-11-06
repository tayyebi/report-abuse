<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

/**
 * Passwordless admin sign-in: e-mail address → one-time code → session.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth = new AuthService())
    {
    }

    public function showLogin(Request $request): Response
    {
        if (Session::adminId() !== null) {
            return $this->redirect('/admin');
        }

        return $this->render('auth/login', [
            'title' => 'Sign in',
            'email' => (string) Session::get('pending_admin_email', ''),
            'errors' => $this->oldErrors(),
            'old' => $this->oldInput(),
            'next' => (string) $request->query('next', ''),
        ]);
    }

    public function requestCode(Request $request): Response
    {
        $email = $request->string('email');
        if ($email === '') {
            return $this->validationFailed(['email' => 'Please enter your e-mail address.'], '/admin/login');
        }

        $next = $request->string('next');
        Session::put('login_next', str_starts_with($next, '/admin') ? $next : '');

        $result = $this->auth->requestCode($email, $request->ip());

        if ($result['ok'] === false) {
            Session::put('_old_errors', ['email' => (string) $result['error']]);
            Session::put('_old_input', ['email' => $email]);

            return $this->redirect('/admin/login');
        }

        Session::flash('success', 'If that address belongs to an administrator, a sign-in code is on its way. It expires in '
            . max(1, (int) round(((int) Config::get('app.auth.code_ttl', 600)) / 60)) . ' minutes.');
        if ($result['debug_code'] !== null) {
            Session::flash('info', 'Development hint: the code is ' . $result['debug_code']);
        }

        return $this->redirect('/admin/login/verify');
    }

    public function showVerify(Request $request): Response
    {
        if (Session::adminId() !== null) {
            return $this->redirect('/admin');
        }

        $email = (string) Session::get('pending_admin_email', '');
        if ($email === '') {
            Session::flash('warning', 'Request a sign-in code first.');

            return $this->redirect('/admin/login');
        }

        return $this->render('auth/verify', [
            'title' => 'Enter your code',
            'email' => $email,
            'errors' => $this->oldErrors(),
            'next' => (string) Session::get('login_next', ''),
        ]);
    }

    public function verifyCode(Request $request): Response
    {
        $email = (string) Session::get('pending_admin_email', '');
        if ($email === '') {
            return $this->redirect('/admin/login');
        }

        $code = preg_replace('/\D+/', '', $request->string('code')) ?? '';
        if (strlen($code) < 4) {
            Session::flash('error', 'Please enter the code from the e-mail.');

            return $this->redirect('/admin/login/verify');
        }

        $admin = $this->auth->verifyCode($email, $code);
        if ($admin === null) {
            Session::flash('error', 'That code is not valid or has expired. Request a new one if needed.');

            return $this->redirect('/admin/login/verify');
        }

        Session::flash('success', 'Welcome back, ' . $admin->email() . '.');

        $next = (string) ($request->input('next') ?: Session::get('login_next', ''));
        Session::forget('login_next');
        if ($next !== '' && str_starts_with($next, '/admin')) {
            return $this->redirect($next);
        }

        return $this->redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        Session::flash('success', 'You have been signed out.');

        return $this->redirect('/admin/login');
    }
}
