<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

/**
 * Base controller: rendering, redirects and validation helpers.
 */
abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], string $layout = 'layout'): Response
    {
        $data['isAdmin'] = Session::adminId() !== null;
        $data['adminEmail'] = Session::adminEmail();
        $data['csrf'] = Session::csrfToken();
        $data['appName'] = (string) Config::get('app.name', 'Abuse Report Desk');
        $data['appUrl'] = (string) Config::get('app.url', '');
        $data['currentPath'] = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return (new Response())->html(View::render($template, $data, $layout));
    }

    protected function redirect(string $path, int $status = 302): Response
    {
        return (new Response())->redirect($path, $status);
    }

    protected function back(string $fallback = '/'): Response
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($referer !== '' && ($host === '' || str_contains($referer, $host))) {
            return $this->redirect($referer);
        }

        return $this->redirect($fallback);
    }

    protected function notFound(string $message = 'Not found'): Response
    {
        return (new Response())->status(404)->html(View::render('errors/404', [
            'title' => $message,
            'isAdmin' => Session::adminId() !== null,
        ]));
    }

    /**
     * @param array<string, string> $errors field => message
     */
    protected function validationFailed(array $errors, string $fallback): Response
    {
        Session::put('_old_errors', $errors);
        Session::put('_old_input', $_POST);
        Session::flash('error', 'Please fix the highlighted fields and try again.');

        return $this->redirect($fallback);
    }

    /**
     * @return array<string, string>
     */
    protected function oldErrors(): array
    {
        $errors = Session::get('_old_errors', []);
        Session::forget('_old_errors');

        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function oldInput(): array
    {
        $input = Session::get('_old_input', []);
        Session::forget('_old_input');

        return is_array($input) ? $input : [];
    }

    protected function requireInt(string $value, string $field = 'id'): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('Invalid ' . $field);
        }

        return (int) $value;
    }
}
