<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\InboxController;
use App\Controllers\PublicController;
use Throwable;

/**
 * Front controller: routing, CSRF enforcement, admin auth guard, error pages.
 */
final class App
{
    /** @var list<array{method: string, pattern: string, handler: array{0: class-string, 1: string}, auth?: bool}> */
    private array $routes = [];

    /** @var list<string> */
    private array $routeOrder = [];

    public function __construct()
    {
        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $routes = [
            ['GET', '/', [PublicController::class, 'showReportForm']],
            ['POST', '/report', [PublicController::class, 'submitReport']],
            ['GET', '/report/submitted/{token}', [PublicController::class, 'showSubmitted']],
            ['GET', '/ticket/{token}', [PublicController::class, 'showTicket']],
            ['POST', '/ticket/{token}/evidence', [PublicController::class, 'addEvidence']],

            ['GET', '/admin/login', [AuthController::class, 'showLogin']],
            ['POST', '/admin/login', [AuthController::class, 'requestCode']],
            ['GET', '/admin/login/verify', [AuthController::class, 'showVerify']],
            ['POST', '/admin/login/verify', [AuthController::class, 'verifyCode']],
            ['POST', '/admin/logout', [AuthController::class, 'logout']],

            ['GET', '/admin', [AdminController::class, 'dashboard'], true],
            ['GET', '/admin/tickets', [AdminController::class, 'index'], true],
            ['GET', '/admin/ticket/{id}', [AdminController::class, 'show'], true],
            ['POST', '/admin/ticket/{id}/enrich', [AdminController::class, 'enrich'], true],
            ['POST', '/admin/ticket/{id}/contacts', [AdminController::class, 'addContact'], true],
            ['POST', '/admin/ticket/{id}/contacts/{contactId}/select', [AdminController::class, 'selectContact'], true],
            ['POST', '/admin/ticket/{id}/contacts/{contactId}/delete', [AdminController::class, 'deleteContact'], true],
            ['POST', '/admin/ticket/{id}/approve', [AdminController::class, 'approve'], true],
            ['GET', '/admin/ticket/{id}/preview', [AdminController::class, 'preview'], true],
            ['POST', '/admin/ticket/{id}/send', [AdminController::class, 'send'], true],
            ['POST', '/admin/ticket/{id}/notes', [AdminController::class, 'saveNotes'], true],
            ['POST', '/admin/ticket/{id}/status', [AdminController::class, 'updateStatus'], true],
            ['POST', '/admin/ticket/{id}/evidence', [AdminController::class, 'uploadEvidence'], true],
            ['GET', '/admin/ticket/{id}/evidence/{evidenceId}', [AdminController::class, 'downloadEvidence'], true],
            ['GET', '/admin/ticket/{id}/report/{reportId}', [AdminController::class, 'downloadReport'], true],

            ['GET', '/admin/inbox', [InboxController::class, 'index'], true],
            ['POST', '/admin/inbox/check', [InboxController::class, 'check'], true],
            ['GET', '/admin/inbox/{id}', [InboxController::class, 'show'], true],
            ['POST', '/admin/inbox/{id}/link', [InboxController::class, 'link'], true],
            ['POST', '/admin/inbox/{id}/create-ticket', [InboxController::class, 'createTicket'], true],

            ['GET', '/admin/settings', [AdminController::class, 'settings'], true],
            ['POST', '/admin/settings/admins', [AdminController::class, 'addAdmin'], true],
            ['POST', '/admin/settings/admins/{id}/toggle', [AdminController::class, 'toggleAdmin'], true],
            ['POST', '/admin/settings/mail-test', [AdminController::class, 'sendTestMail'], true],
            ['POST', '/admin/settings/imap-check', [AdminController::class, 'checkMailboxes'], true],
        ];

        foreach ($routes as $route) {
            $this->add($route[0], $route[1], $route[2], (bool) ($route[3] ?? false));
        }
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function add(string $method, string $pattern, array $handler, bool $auth = false): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function handle(Request $request): Response
    {
        Session::start();

        $match = $this->match($request);
        if ($match === null) {
            return $this->notFound($request);
        }

        [$route, $params] = $match;

        // CSRF protection for every state-changing request.
        if ($request->isPost()) {
            $token = (string) ($request->input('_csrf') ?? $request->header('X-CSRF-Token') ?? '');
            if (!Session::validateCsrf($token)) {
                return (new Response())
                    ->status(419)
                    ->html(View::render('errors/419', [
                        'title' => 'Session expired',
                        'isAdmin' => Session::adminId() !== null,
                    ]));
            }
        }

        if (!empty($route['auth']) && !Session::touchAdmin()) {
            $next = $request->path();
            Session::flash('warning', 'Please sign in to continue.');
            return (new Response())->redirect('/admin/login?next=' . urlencode($next));
        }

        [$class, $method] = $route['handler'];
        try {
            $controller = new $class();
            /** @var Response $response */
            $response = $controller->{$method}($request, ...array_values($params));
            return $response;
        } catch (Throwable $e) {
            return $this->serverError($request, $e);
        }
    }

    /**
     * @return array{0: array{method: string, pattern: string, handler: array{0: class-string, 1: string}, auth?: bool}, 1: array<string, string>}|null
     */
    private function match(Request $request): ?array
    {
        $path = $request->path();
        $method = $request->method();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $params = self::matchPattern($route['pattern'], $path);
            if ($params !== null) {
                return [$route, $params];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function matchPattern(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[$key] = urldecode((string) $value);
            }
        }

        return $params;
    }

    private function notFound(Request $request): Response
    {
        return (new Response())
            ->status(404)
            ->html(View::render('errors/404', [
                'title' => 'Not found',
                'isAdmin' => Session::adminId() !== null,
            ]));
    }

    private function serverError(Request $request, Throwable $e): Response
    {
        $debug = (bool) Config::get('app.debug', false);
        $logDir = storage_path('logs');
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents(
                $logDir . '/app.log',
                sprintf("[%s] %s: %s in %s:%d\n%s\n\n", date('c'), $request->method(), $request->path(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()),
                FILE_APPEND
            );
        }

        return (new Response())
            ->status(500)
            ->html(View::render('errors/500', [
                'title' => 'Something went wrong',
                'isAdmin' => Session::adminId() !== null,
                'message' => $debug ? $e->getMessage() : 'An unexpected error occurred. The details were logged.',
                'trace' => $debug ? $e->getTraceAsString() : null,
            ]));
    }
}
