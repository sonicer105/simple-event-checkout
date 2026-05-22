<?php

declare(strict_types=1);

namespace App\Admin;

use App\Auth\AdminAuth;
use App\Http\RequestUtil;
use App\Mail\EmailRenderer;
use App\Mail\Mailer;
use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\AddonProductRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventModifierRepository;
use App\Repositories\EventVariationRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseTicketAddonRepository;
use App\Repositories\PurchaseTicketModifierRepository;
use App\Repositories\PurchaseTicketRepository;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Slim\Views\Twig;

final class AdminRoutes
{
    public static function register(
        App $app,
        Twig $twig,
        array $config,
        AdminAuth $adminAuth,
        AdminRepository $adminRepo,
        AuditLogRepository $auditRepo,
        \App\Repositories\LoginLockoutRepository $lockoutRepo,
        EventRepository $eventRepo,
        EventVariationRepository $variationRepo,
        EventModifierRepository $modifierRepo,
        AddonProductRepository $addonRepo,
        PurchaseRepository $purchaseRepo,
        PurchaseTicketRepository $purchaseTickets,
        PurchaseTicketModifierRepository $purchaseTicketModifiers,
        PurchaseTicketAddonRepository $purchaseTicketAddons,
        Mailer $mailer,
        EmailRenderer $emailRenderer,
    ): void {
        $app->get('/admin/login', function (Request $request, Response $response) {
            return Twig::fromRequest($request)->render($response, 'admin/login.twig', [
                'error' => null,
            ]);
        });

        $app->post('/admin/login', function (Request $request, Response $response) use ($adminAuth, $config) {
            $data = (array) $request->getParsedBody();
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');

            $ip = RequestUtil::clientIp($config, $request);
            $admin = $adminAuth->authenticate($username, $password, $ip);

            if (!$admin) {
                return Twig::fromRequest($request)->render($response, 'admin/login.twig', [
                    'error' => 'Invalid credentials or lockout in effect.',
                ]);
            }

            // 2FA is required every login. If app-based 2FA isn't enabled, email 2FA is used as a fallback.
            if ($adminAuth->requiresTwoFactor($admin)) {
                $_SESSION['admin_2fa_pending'] = (int) $admin['id'];
                return $response->withHeader('Location', '/admin/2fa')->withStatus(302);
            }

            $_SESSION['admin_2fa_email_pending'] = (int) $admin['id'];
            return $response->withHeader('Location', '/admin/2fa-email')->withStatus(302);
        });

        $app->get('/admin/2fa', function (Request $request, Response $response) {
            if (empty($_SESSION['admin_2fa_pending'])) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            return Twig::fromRequest($request)->render($response, 'admin/2fa.twig', [
                'error' => null,
            ]);
        });

        $app->post('/admin/2fa', function (Request $request, Response $response) use ($adminAuth, $adminRepo) {
            $adminId = (int) ($_SESSION['admin_2fa_pending'] ?? 0);
            if ($adminId <= 0) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $data = (array) $request->getParsedBody();
            $code = trim((string) ($data['code'] ?? ''));

            $admin = $adminRepo->findById($adminId);
            if (!$admin) {
                unset($_SESSION['admin_2fa_pending']);
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            if ($code === '') {
                return Twig::fromRequest($request)->render($response, 'admin/2fa.twig', [
                    'error' => 'Enter the code from your authenticator app.',
                ]);
            }

            if (empty($admin['totp_secret'])) {
                return Twig::fromRequest($request)->render($response, 'admin/2fa.twig', [
                    'error' => 'Two-factor is not configured for this account.',
                ]);
            }

            $totp = TOTP::create((string) $admin['totp_secret']);
            if (!$totp->verify($code, null, 1)) {
                return Twig::fromRequest($request)->render($response, 'admin/2fa.twig', [
                    'error' => 'Invalid authentication code.',
                ]);
            }

            unset($_SESSION['admin_2fa_pending']);
            self::rotateSessionSecurity();

            $token = $adminAuth->createSession((int) $admin['id']);
            setcookie($adminAuth->cookieName(), $token, $adminAuth->getSessionCookieParams());

            return $response->withHeader('Location', '/admin')->withStatus(302);
        });

        $app->get('/admin/2fa-email', function (Request $request, Response $response) use ($adminRepo, $mailer, $emailRenderer, $auditRepo, $config) {
            $adminId = (int) ($_SESSION['admin_2fa_email_pending'] ?? 0);
            if ($adminId <= 0) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $admin = $adminRepo->findById($adminId);
            if (!$admin) {
                unset($_SESSION['admin_2fa_email_pending']);
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            if (empty($_SESSION['admin_2fa_email_code_hash']) || empty($_SESSION['admin_2fa_email_expires_at']) || time() > (int) $_SESSION['admin_2fa_email_expires_at']) {
                $ip = RequestUtil::clientIp($config, $request);
                try {
                    self::sendAdminEmail2faCode($config, $admin, $mailer, $emailRenderer);
                    $auditRepo->log((int) $admin['id'], 'admin_2fa_email_sent', 'Email 2FA code sent', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                } catch (\Throwable $error) {
                    $auditRepo->log((int) $admin['id'], 'admin_2fa_email_failure', 'Failed to send email 2FA code', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return Twig::fromRequest($request)->render($response, 'admin/2fa-email.twig', [
                        'email' => (string) $admin['email'],
                        'error' => 'Could not send email code. Check SMTP settings.',
                    ]);
                }
            }

            return Twig::fromRequest($request)->render($response, 'admin/2fa-email.twig', [
                'email' => (string) $admin['email'],
                'error' => null,
            ]);
        });

        $app->post('/admin/2fa-email', function (Request $request, Response $response) use ($adminRepo, $adminAuth, $auditRepo, $config) {
            $adminId = (int) ($_SESSION['admin_2fa_email_pending'] ?? 0);
            if ($adminId <= 0) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $admin = $adminRepo->findById($adminId);
            if (!$admin) {
                unset($_SESSION['admin_2fa_email_pending']);
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $data = (array) $request->getParsedBody();
            $code = trim((string) ($data['code'] ?? ''));
            $ip = RequestUtil::clientIp($config, $request);

            $expiresAt = (int) ($_SESSION['admin_2fa_email_expires_at'] ?? 0);
            $expectedHash = (string) ($_SESSION['admin_2fa_email_code_hash'] ?? '');
            if ($expectedHash === '' || $expiresAt === 0 || time() > $expiresAt) {
                $auditRepo->log((int) $admin['id'], 'admin_2fa_email_failure', 'Email 2FA code expired or missing', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                return Twig::fromRequest($request)->render($response, 'admin/2fa-email.twig', [
                    'email' => (string) $admin['email'],
                    'error' => 'Code expired. Click resend to get a new one.',
                ]);
            }

            $_SESSION['admin_2fa_email_attempts'] = (int) ($_SESSION['admin_2fa_email_attempts'] ?? 0) + 1;
            if ((int) $_SESSION['admin_2fa_email_attempts'] > 5) {
                $auditRepo->log((int) $admin['id'], 'admin_2fa_email_failure', 'Too many email 2FA attempts', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                unset($_SESSION['admin_2fa_email_pending'], $_SESSION['admin_2fa_email_code_hash'], $_SESSION['admin_2fa_email_expires_at'], $_SESSION['admin_2fa_email_attempts']);
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $codeHash = hash('sha256', $code);
            if (!hash_equals($expectedHash, $codeHash)) {
                $auditRepo->log((int) $admin['id'], 'admin_2fa_email_failure', 'Invalid email 2FA code', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                return Twig::fromRequest($request)->render($response, 'admin/2fa-email.twig', [
                    'email' => (string) $admin['email'],
                    'error' => 'Invalid code.',
                ]);
            }

            $auditRepo->log((int) $admin['id'], 'admin_2fa_email_success', 'Email 2FA verified', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
            unset($_SESSION['admin_2fa_email_pending'], $_SESSION['admin_2fa_email_code_hash'], $_SESSION['admin_2fa_email_expires_at'], $_SESSION['admin_2fa_email_attempts']);

            self::rotateSessionSecurity();

            $token = $adminAuth->createSession((int) $admin['id']);
            setcookie($adminAuth->cookieName(), $token, $adminAuth->getSessionCookieParams());

            return $response->withHeader('Location', '/admin')->withStatus(302);
        });

        $app->post('/admin/2fa-email/resend', function (Request $request, Response $response) use ($adminRepo, $mailer, $emailRenderer, $auditRepo, $config) {
            $adminId = (int) ($_SESSION['admin_2fa_email_pending'] ?? 0);
            if ($adminId <= 0) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $admin = $adminRepo->findById($adminId);
            if (!$admin) {
                unset($_SESSION['admin_2fa_email_pending']);
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $ip = RequestUtil::clientIp($config, $request);
            try {
                self::sendAdminEmail2faCode($config, $admin, $mailer, $emailRenderer);
                $auditRepo->log((int) $admin['id'], 'admin_2fa_email_sent', 'Email 2FA code resent', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
            } catch (\Throwable $error) {
                $auditRepo->log((int) $admin['id'], 'admin_2fa_email_failure', 'Failed to resend email 2FA code', $ip, $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
            }

            return $response->withHeader('Location', '/admin/2fa-email')->withStatus(302);
        });

        // Protected admin pages.
        $app->group('/admin', function (Group $group) use ($adminRepo, $auditRepo, $lockoutRepo, $eventRepo, $variationRepo, $modifierRepo, $addonRepo, $purchaseRepo, $purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $mailer, $emailRenderer, $config) {
            $group->get('', function (Request $request, Response $response) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');

                $allEvents = $eventRepo->listAll();

                if (count($allEvents) === 0) {
                    return Twig::fromRequest($request)->render($response, 'admin/dashboard.twig', [
                        'admin' => $admin,
                        'no_events' => true,
                        'top_events' => [],
                        'bar_config' => null,
                        'line_config' => null,
                    ]);
                }

                $topEvents = $eventRepo->listTopByTickets(10);
                $barLabels = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $topEvents);
                $barSold = array_map(static fn (array $row): int => (int) ($row['ticket_count'] ?? 0), $topEvents);
                $barPickedUp = array_map(static fn (array $row): int => (int) ($row['picked_up_count'] ?? 0), $topEvents);

                $barConfig = [
                    'data' => [
                        'labels' => $barLabels,
                        'datasets' => [
                            [
                                'label' => 'Tickets Sold',
                                'data' => $barSold,
                                'backgroundColor' => '#2563eb',
                            ],
                            [
                                'label' => 'Tickets Picked Up',
                                'data' => $barPickedUp,
                                'backgroundColor' => '#16a34a',
                            ],
                        ],
                    ],
                    'options' => [
                        'plugins' => [
                            'legend' => ['display' => true],
                        ],
                    ],
                ];

                $lineConfig = null;
                $labels = [];
                for ($i = 52; $i >= 0; $i--) {
                    $labels[] = '-' . (string) $i;
                }

                $datasets = [];
                $nowWeekStart = (new \DateTimeImmutable('now'))->modify('monday this week');

                foreach ($allEvents as $e) {
                    $eventId = (int) ($e['id'] ?? 0);
                    if ($eventId <= 0) {
                        continue;
                    }

                    $startAt = null;
                    if (is_string($e['start_time'] ?? null) && (string) $e['start_time'] !== '') {
                        $startAt = new \DateTimeImmutable((string) $e['start_time']);
                    } else {
                        // This chart is keyed off the event start time; skip events without one.
                        continue;
                    }

                    $eventWeekStart = $startAt->modify('monday this week');
                    $capAt = $nowWeekStart < $eventWeekStart ? $nowWeekStart : $eventWeekStart;

                    $from = $capAt->sub(new \DateInterval('P364D'));
                    $rows = $eventRepo->listWeeklyTicketCountsForEvent($eventId, $from, $capAt);
                    $byWeek = [];
                    foreach ($rows as $r) {
                        if (!is_string($r['week_start'] ?? null)) {
                            continue;
                        }
                        $byWeek[(string) $r['week_start']] = (int) ($r['ticket_count'] ?? 0);
                    }

                    $minWeeks = 0;
                    if ($nowWeekStart < $eventWeekStart) {
                        $diffSeconds = $eventWeekStart->getTimestamp() - $nowWeekStart->getTimestamp();
                        $minWeeks = (int) floor($diffSeconds / (7 * 86400));
                    }

                    $data = [];
                    $running = 0;
                    for ($i = 52; $i >= 0; $i--) {
                        if ($i < $minWeeks) {
                            $data[] = null;
                            continue;
                        }

                        $weekStart = $startAt->sub(new \DateInterval('P' . ($i * 7) . 'D'));
                        $weekKey = $weekStart->modify('monday this week')->format('Y-m-d');
                        $running += (int) ($byWeek[$weekKey] ?? 0);
                        $data[] = $running;
                    }

                    if ($running <= 0) {
                        continue;
                    }

                    $datasets[] = [
                        'label' => (string) ($e['name'] ?? ('Event #' . $eventId)),
                        'data' => $data,
                        'fill' => false,
                    ];
                }

                if (count($datasets) > 0) {
                    $lineConfig = [
                        'data' => [
                            'labels' => $labels,
                            'datasets' => $datasets,
                        ],
                        'options' => [
                            'plugins' => [
                                'legend' => ['display' => true],
                            ],
                            'scales' => [
                                'y' => [
                                    'min' => 0,
                                ],
                                'x' => [
                                    'title' => [
                                        'display' => true,
                                        'text' => 'Weeks Before Event Start',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                return Twig::fromRequest($request)->render($response, 'admin/dashboard.twig', [
                    'admin' => $admin,
                    'no_events' => false,
                    'bar_config' => $barConfig,
                    'top_events' => $topEvents,
                    'line_config' => $lineConfig,
                ]);
            });

            $group->get('/admins', function (Request $request, Response $response) use ($adminRepo) {
                $admin = (array) $request->getAttribute('admin');
                $admins = $adminRepo->listAll();

                return Twig::fromRequest($request)->render($response, 'admin/admins.twig', [
                    'admin' => $admin,
                    'admins' => $admins,
                ]);
            });

            $group->get('/admins/new', function (Request $request, Response $response) {
                $admin = (array) $request->getAttribute('admin');

                return Twig::fromRequest($request)->render($response, 'admin/admin-edit.twig', [
                    'admin' => $admin,
                    'is_new' => true,
                    'error' => null,
                    'form' => [
                        'username' => '',
                        'email' => '',
                    ],
                    'target_admin' => null,
                ]);
            });

            $group->post('/admins/new', function (Request $request, Response $response) use ($adminRepo, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $data = (array) $request->getParsedBody();
                $username = trim((string) ($data['username'] ?? ''));
                $email = trim((string) ($data['email'] ?? ''));
                $password = (string) ($data['password'] ?? '');

                $renderError = function (string $error) use ($request, $response, $admin, $username, $email): Response {
                    return Twig::fromRequest($request)->render($response, 'admin/admin-edit.twig', [
                        'admin' => $admin,
                        'is_new' => true,
                        'error' => $error,
                        'form' => [
                            'username' => $username,
                            'email' => $email,
                        ],
                        'target_admin' => null,
                    ]);
                };

                if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $username)) {
                    return $renderError('Username must be 3-50 characters (letters, numbers, underscore, dash, dot).');
                }

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $renderError('Enter a valid email address.');
                }

                if (strlen($password) < 10) {
                    return $renderError('Password must be at least 10 characters.');
                }

                try {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $newId = $adminRepo->create($username, $email, $passwordHash);
                    $auditRepo->log((int) $admin['id'], 'admin_created', 'Created admin #' . $newId . ' (' . $username . ')', RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                } catch (\Throwable) {
                    return $renderError('Failed to create admin. Username may already exist.');
                }
            });

            $group->get('/admins/{id}', function (Request $request, Response $response, array $args) use ($adminRepo) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                $target = $adminRepo->findById($id);
                if (!$target) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/admin-edit.twig', [
                    'admin' => $admin,
                    'is_new' => false,
                    'error' => null,
                    'form' => [
                        'username' => (string) ($target['username'] ?? ''),
                        'email' => (string) ($target['email'] ?? ''),
                    ],
                    'target_admin' => $target,
                ]);
            });

            $group->post('/admins/{id}', function (Request $request, Response $response, array $args) use ($adminRepo, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                $target = $adminRepo->findById($id);
                if (!$target) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $email = trim((string) ($data['email'] ?? ''));
                $password = (string) ($data['password'] ?? '');

                $renderError = function (string $error) use ($request, $response, $admin, $target, $email): Response {
                    return Twig::fromRequest($request)->render($response, 'admin/admin-edit.twig', [
                        'admin' => $admin,
                        'is_new' => false,
                        'error' => $error,
                        'form' => [
                            'username' => (string) ($target['username'] ?? ''),
                            'email' => $email,
                        ],
                        'target_admin' => $target,
                    ]);
                };

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $renderError('Enter a valid email address.');
                }

                $updates = [
                    'email' => $email,
                ];

                if ($password !== '') {
                    if (strlen($password) < 10) {
                        return $renderError('Password must be at least 10 characters (or leave it blank).');
                    }
                    $updates['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $adminRepo->update($id, $updates);
                $auditRepo->log((int) $admin['id'], 'admin_updated', 'Updated admin #' . $id, RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));

                return $response->withHeader('Location', '/admin/admins/' . $id)->withStatus(302);
            });

            $group->post('/admins/{id}/reset-2fa', function (Request $request, Response $response, array $args) use ($adminRepo, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                $target = $adminRepo->findById($id);
                if (!$target) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                $adminRepo->updateTotpSecret($id, null, false);
                $auditRepo->log((int) $admin['id'], 'admin_2fa_reset', 'Reset 2FA enrollment for admin #' . $id, RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));

                return $response->withHeader('Location', '/admin/admins/' . $id)->withStatus(302);
            });

            $group->post('/admins/{id}/delete', function (Request $request, Response $response, array $args) use ($adminRepo, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/admins')->withStatus(302);
                }

                // Prevent self-deletion.
                if ((int) ($admin['id'] ?? 0) === $id) {
                    return $response->withHeader('Location', '/admin/admins/' . $id)->withStatus(302);
                }

                $target = $adminRepo->findById($id);
                if ($target) {
                    $adminRepo->delete($id);
                    $auditRepo->log((int) $admin['id'], 'admin_deleted', 'Deleted admin #' . $id . ' (' . ($target['username'] ?? '') . ')', RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                }

                return $response->withHeader('Location', '/admin/admins')->withStatus(302);
            });

            $group->get('/events', function (Request $request, Response $response) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $events = $eventRepo->listAll();

                return Twig::fromRequest($request)->render($response, 'admin/events.twig', [
                    'admin' => $admin,
                    'events' => $events,
                ]);
            });

            $group->get('/events/new', function (Request $request, Response $response) {
                $admin = (array) $request->getAttribute('admin');
                return Twig::fromRequest($request)->render($response, 'admin/event-edit.twig', [
                    'admin' => $admin,
                    'is_new' => true,
                    'event' => ['status' => 'draft'],
                    'statuses' => ['draft', 'published', 'unlisted', 'archived'],
                        'image_options' => self::listEventImageOptions(),
                    'error' => null,
                ]);
            });

            $group->post('/events/new', function (Request $request, Response $response) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $data = (array) $request->getParsedBody();

                $payload = self::eventPayloadFromRequest($data);
                if ($payload['error'] !== null) {
                    return Twig::fromRequest($request)->render($response, 'admin/event-edit.twig', [
                        'admin' => $admin,
                        'is_new' => true,
                        'event' => $payload['event'],
                        'statuses' => ['draft', 'published', 'unlisted', 'archived'],
                        'image_options' => self::listEventImageOptions(),
                        'error' => $payload['error'],
                    ]);
                }

                unset($payload['event']['start_time_local'], $payload['event']['end_time_local']);
                $id = $eventRepo->create($payload['event']);
                return $response->withHeader('Location', '/admin/events/' . $id)->withStatus(302);
            });

            $group->get('/events/{id}', function (Request $request, Response $response, array $args) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $event = $eventRepo->findById($id);
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $event['start_time_local'] = self::toDatetimeLocal($event['start_time'] ?? null);
                $event['end_time_local'] = self::toDatetimeLocal($event['end_time'] ?? null);

                return Twig::fromRequest($request)->render($response, 'admin/event-edit.twig', [
                    'admin' => $admin,
                    'is_new' => false,
                    'event' => $event,
                    'statuses' => ['draft', 'published', 'unlisted', 'archived'],
                        'image_options' => self::listEventImageOptions(),
                    'error' => null,
                ]);
            });

            $group->post('/events/{id}', function (Request $request, Response $response, array $args) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $existing = $eventRepo->findById($id);
                if (!$existing) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::eventPayloadFromRequest($data);
                if ($payload['error'] !== null) {
                    $payload['event']['id'] = $id;
                    $payload['event']['start_time_local'] = $data['start_time'] ?? '';
                    $payload['event']['end_time_local'] = $data['end_time'] ?? '';

                    return Twig::fromRequest($request)->render($response, 'admin/event-edit.twig', [
                        'admin' => $admin,
                        'is_new' => false,
                        'event' => $payload['event'],
                        'statuses' => ['draft', 'published', 'unlisted', 'archived'],
                        'image_options' => self::listEventImageOptions(),
                        'error' => $payload['error'],
                    ]);
                }

                unset($payload['event']['start_time_local'], $payload['event']['end_time_local']);
                $eventRepo->update($id, $payload['event']);
                return $response->withHeader('Location', '/admin/events/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/variations', function (Request $request, Response $response, array $args) use ($eventRepo, $variationRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-variations.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'variations' => $variationRepo->listByEventId($eventId),
                ]);
            });

            $group->get('/events/{eventId}/variations/new', function (Request $request, Response $response, array $args) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-variation-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => true,
                    'variation' => ['sort_order' => 0],
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/variations/new', function (Request $request, Response $response, array $args) use ($eventRepo, $variationRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::variationPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    return Twig::fromRequest($request)->render($response, 'admin/event-variation-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => true,
                        'variation' => $payload['variation'],
                        'error' => $payload['error'],
                    ]);
                }

                $id = $variationRepo->create($payload['variation']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/variations/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/variations/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $variationRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $variation = $id > 0 ? $variationRepo->findById($id) : null;
                if (!$variation || (int) $variation['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/variations')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-variation-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => false,
                    'variation' => $variation,
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/variations/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $variationRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $existing = $id > 0 ? $variationRepo->findById($id) : null;
                if (!$existing || (int) $existing['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/variations')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::variationPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    $payload['variation']['id'] = $id;
                    return Twig::fromRequest($request)->render($response, 'admin/event-variation-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => false,
                        'variation' => $payload['variation'],
                        'error' => $payload['error'],
                    ]);
                }

                $variationRepo->update($id, $payload['variation']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/variations/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/modifiers', function (Request $request, Response $response, array $args) use ($eventRepo, $modifierRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-modifiers.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'modifiers' => $modifierRepo->listByEventId($eventId),
                ]);
            });

            $group->get('/events/{eventId}/modifiers/new', function (Request $request, Response $response, array $args) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-modifier-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => true,
                    'modifier' => ['modifier_type' => 'text', 'is_required' => 0, 'sort_order' => 0],
                    'types' => ['text', 'checkbox'],
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/modifiers/new', function (Request $request, Response $response, array $args) use ($eventRepo, $modifierRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::modifierPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    return Twig::fromRequest($request)->render($response, 'admin/event-modifier-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => true,
                        'modifier' => $payload['modifier'],
                        'types' => ['text', 'checkbox'],
                        'error' => $payload['error'],
                    ]);
                }

                $id = $modifierRepo->create($payload['modifier']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/modifiers/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/modifiers/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $modifierRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $modifier = $id > 0 ? $modifierRepo->findById($id) : null;
                if (!$modifier || (int) $modifier['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/modifiers')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-modifier-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => false,
                    'modifier' => $modifier,
                    'types' => ['text', 'checkbox'],
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/modifiers/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $modifierRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $existing = $id > 0 ? $modifierRepo->findById($id) : null;
                if (!$existing || (int) $existing['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/modifiers')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::modifierPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    $payload['modifier']['id'] = $id;
                    return Twig::fromRequest($request)->render($response, 'admin/event-modifier-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => false,
                        'modifier' => $payload['modifier'],
                        'types' => ['text', 'checkbox'],
                        'error' => $payload['error'],
                    ]);
                }

                $modifierRepo->update($id, $payload['modifier']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/modifiers/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/addons', function (Request $request, Response $response, array $args) use ($eventRepo, $addonRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-addons.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'addons' => $addonRepo->listByEventId($eventId),
                ]);
            });

            $group->get('/events/{eventId}/addons/new', function (Request $request, Response $response, array $args) use ($eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-addon-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => true,
                    'addon' => [],
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/addons/new', function (Request $request, Response $response, array $args) use ($eventRepo, $addonRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::addonPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    return Twig::fromRequest($request)->render($response, 'admin/event-addon-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => true,
                        'addon' => $payload['addon'],
                        'error' => $payload['error'],
                    ]);
                }

                $id = $addonRepo->create($payload['addon']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/addons/' . $id)->withStatus(302);
            });

            $group->get('/events/{eventId}/addons/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $addonRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $addon = $id > 0 ? $addonRepo->findById($id) : null;
                if (!$addon || (int) $addon['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/addons')->withStatus(302);
                }

                return Twig::fromRequest($request)->render($response, 'admin/event-addon-edit.twig', [
                    'admin' => $admin,
                    'event' => $event,
                    'is_new' => false,
                    'addon' => $addon,
                    'error' => null,
                ]);
            });

            $group->post('/events/{eventId}/addons/{id}', function (Request $request, Response $response, array $args) use ($eventRepo, $addonRepo) {
                $admin = (array) $request->getAttribute('admin');
                $eventId = (int) ($args['eventId'] ?? 0);
                $event = $eventId > 0 ? $eventRepo->findById($eventId) : null;
                if (!$event) {
                    return $response->withHeader('Location', '/admin/events')->withStatus(302);
                }

                $id = (int) ($args['id'] ?? 0);
                $existing = $id > 0 ? $addonRepo->findById($id) : null;
                if (!$existing || (int) $existing['event_id'] !== $eventId) {
                    return $response->withHeader('Location', '/admin/events/' . $eventId . '/addons')->withStatus(302);
                }

                $data = (array) $request->getParsedBody();
                $payload = self::addonPayloadFromRequest($data, $eventId);
                if ($payload['error'] !== null) {
                    $payload['addon']['id'] = $id;
                    return Twig::fromRequest($request)->render($response, 'admin/event-addon-edit.twig', [
                        'admin' => $admin,
                        'event' => $event,
                        'is_new' => false,
                        'addon' => $payload['addon'],
                        'error' => $payload['error'],
                    ]);
                }

                $addonRepo->update($id, $payload['addon']);
                return $response->withHeader('Location', '/admin/events/' . $eventId . '/addons/' . $id)->withStatus(302);
            });

            $group->get('/audit-logs', function (Request $request, Response $response) use ($auditRepo) {
                $admin = (array) $request->getAttribute('admin');
                $logs = $auditRepo->listRecent(200);

                return Twig::fromRequest($request)->render($response, 'admin/audit-logs.twig', [
                    'admin' => $admin,
                    'logs' => $logs,
                ]);
            });

            $group->get('/lockouts', function (Request $request, Response $response) use ($lockoutRepo) {
                $admin = (array) $request->getAttribute('admin');
                $lockouts = $lockoutRepo->listAll();

                return Twig::fromRequest($request)->render($response, 'admin/lockouts.twig', [
                    'admin' => $admin,
                    'lockouts' => $lockouts,
                ]);
            });

            $group->post('/lockouts/clear', function (Request $request, Response $response) use ($lockoutRepo, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $data = (array) $request->getParsedBody();
                $ip = trim((string) ($data['ip'] ?? ''));
                if ($ip !== '') {
                    $lockoutRepo->clear($ip);
                    $auditRepo->log((int) $admin['id'], 'admin_lockout_cleared', 'Cleared lockout for IP ' . $ip, RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                }

                return $response->withHeader('Location', '/admin/lockouts')->withStatus(302);
            });

            $group->get('/purchases', function (Request $request, Response $response) use ($purchaseRepo, $eventRepo) {
                $admin = (array) $request->getAttribute('admin');
                $qp = $request->getQueryParams();
                $eventId = (int) ($qp['event_id'] ?? 0);
                $includePending = (int) ($qp['include_pending'] ?? 0) === 1;

                $events = $eventRepo->listAll();

                $purchases = $eventId > 0
                    ? $purchaseRepo->listRecentByEventId($eventId, 200)
                    : ($includePending ? $purchaseRepo->listRecent(200) : $purchaseRepo->listRecentFinalized(200));

                return Twig::fromRequest($request)->render($response, 'admin/purchases.twig', [
                    'admin' => $admin,
                    'purchases' => $purchases,
                    'events' => $events,
                    'filter_event_id' => $eventId,
                    'include_pending' => $includePending,
                ]);
            });

            $group->get('/purchases/{id}', function (Request $request, Response $response, array $args) use ($purchaseRepo, $purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons) {
                $admin = (array) $request->getAttribute('admin');
                $purchaseId = (int) ($args['id'] ?? 0);
                if ($purchaseId <= 0) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                $purchase = $purchaseRepo->findById($purchaseId);
                if (!$purchase) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                $tickets = $purchaseTickets->listByPurchaseId($purchaseId);
                $modRows = $purchaseTicketModifiers->listByPurchaseId($purchaseId);
                $addonRows = $purchaseTicketAddons->listByPurchaseId($purchaseId);

                $modsByTicketId = [];
                foreach ($modRows as $m) {
                    $tid = (int) ($m['purchase_ticket_id'] ?? 0);
                    $modsByTicketId[$tid] ??= [];
                    $modsByTicketId[$tid][] = $m;
                }

                $addonsByTicketId = [];
                foreach ($addonRows as $a) {
                    $tid = (int) ($a['purchase_ticket_id'] ?? 0);
                    $addonsByTicketId[$tid] ??= [];
                    $addonsByTicketId[$tid][] = $a;
                }

                foreach ($tickets as &$t) {
                    $tid = (int) ($t['id'] ?? 0);
                    $t['modifiers'] = $modsByTicketId[$tid] ?? [];
                    $t['addons'] = $addonsByTicketId[$tid] ?? [];
                }
                unset($t);

                return Twig::fromRequest($request)->render($response, 'admin/purchase-detail.twig', [
                    'admin' => $admin,
                    'purchase' => $purchase,
                    'tickets' => $tickets,
                ]);
            });

            $group->get('/checkin', function (Request $request, Response $response) {
                $admin = (array) $request->getAttribute('admin');
                return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                    'admin' => $admin,
                    'ok' => null,
                    'message' => null,
                    'ticket' => null,
                    'last_token' => (string) ($_SESSION['admin_last_checkin_token'] ?? ''),
                ]);
            });

            $group->post('/checkin', function (Request $request, Response $response) use ($purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $data = (array) $request->getParsedBody();
                $token = trim((string) ($data['qr_token'] ?? ''));
                $_SESSION['admin_last_checkin_token'] = $token;

                if ($token === '') {
                    return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                        'admin' => $admin,
                        'ok' => false,
                        'message' => 'Enter a QR token.',
                        'ticket' => null,
                        'last_token' => $token,
                    ]);
                }

                $ticket = $purchaseTickets->findByQrToken($token);
                if (!$ticket) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_failed', 'Invalid QR token', RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                        'admin' => $admin,
                        'ok' => false,
                        'message' => 'Ticket not found.',
                        'ticket' => null,
                        'last_token' => $token,
                    ]);
                }

                $ticket['modifiers'] = $purchaseTicketModifiers->listByPurchaseTicketId((int) $ticket['id']);
                $ticket['addons'] = $purchaseTicketAddons->listByPurchaseTicketId((int) $ticket['id']);
                if (!empty($ticket['checked_in_at'])) {
                    try {
                        $tz = new \DateTimeZone((string) ($config['app_timezone'] ?? 'America/Vancouver'));
                        // DB timestamps are assumed UTC for display purposes.
                        $dt = new \DateTimeImmutable((string) $ticket['checked_in_at'], new \DateTimeZone('UTC'));
                        $ticket['checked_in_at_display'] = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                    } catch (\Throwable) {
                        $ticket['checked_in_at_display'] = (string) $ticket['checked_in_at'];
                    }
                }

                if (!empty($ticket['refunded_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_refunded', 'Attempted check-in for refunded ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                        'admin' => $admin,
                        'ok' => false,
                        'message' => 'Ticket was refunded.',
                        'ticket' => $ticket,
                        'last_token' => $token,
                    ]);
                }

                if (!empty($ticket['checked_in_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_duplicate', 'Attempted duplicate check-in for ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                        'admin' => $admin,
                        'ok' => false,
                        'message' => 'Already checked in.',
                        'ticket' => $ticket,
                        'last_token' => $token,
                    ]);
                }

                $purchaseTickets->markCheckedIn((int) $ticket['id'], (int) $admin['id']);
                $auditRepo->log((int) $admin['id'], 'ticket_checked_in', 'Checked in ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                $ticket['checked_in_at'] = date('Y-m-d H:i:s');
                $ticket['checked_in_by_admin_id'] = (int) $admin['id'];
                $ticket['checked_in_at_display'] = $ticket['checked_in_at'];

                return Twig::fromRequest($request)->render($response, 'admin/checkin.twig', [
                    'admin' => $admin,
                    'ok' => true,
                    'message' => 'Checked in.',
                    'ticket' => $ticket,
                    'last_token' => $token,
                ]);
            });

            $group->post('/checkin/ajax', function (Request $request, Response $response) use ($purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $raw = (string) $request->getBody();
                $payload = json_decode($raw, true);
                $token = trim((string) (($payload['qr_token'] ?? '') ?? ''));
                $_SESSION['admin_last_checkin_token'] = $token;

                $reply = function (int $code, array $data) use ($response): Response {
                    $json = json_encode($data, JSON_PRETTY_PRINT);
                    $response->getBody()->write($json === false ? '{}' : $json);
                    return $response
                        ->withStatus($code)
                        ->withHeader('Content-Type', 'application/json');
                };

                if ($token === '') {
                    return $reply(400, ['ok' => false, 'message' => 'Enter a QR token.']);
                }

                $ticket = $purchaseTickets->findByQrToken($token);
                if (!$ticket) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_failed', 'Invalid QR token', RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $reply(404, ['ok' => false, 'message' => 'Ticket not found.']);
                }

                $ticket['modifiers'] = $purchaseTicketModifiers->listByPurchaseTicketId((int) $ticket['id']);
                $ticket['addons'] = $purchaseTicketAddons->listByPurchaseTicketId((int) $ticket['id']);
                if (!empty($ticket['checked_in_at'])) {
                    try {
                        $tz = new \DateTimeZone((string) ($config['app_timezone'] ?? 'America/Vancouver'));
                        $dt = new \DateTimeImmutable((string) $ticket['checked_in_at'], new \DateTimeZone('UTC'));
                        $ticket['checked_in_at_display'] = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                    } catch (\Throwable) {
                        $ticket['checked_in_at_display'] = (string) $ticket['checked_in_at'];
                    }
                }

                if (!empty($ticket['refunded_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_refunded', 'Attempted check-in for refunded ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $reply(409, ['ok' => false, 'message' => 'Ticket was refunded.', 'ticket' => $ticket]);
                }

                if (!empty($ticket['checked_in_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_duplicate', 'Attempted duplicate check-in for ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $reply(409, ['ok' => false, 'message' => 'Already checked in.', 'ticket' => $ticket]);
                }

                $purchaseTickets->markCheckedIn((int) $ticket['id'], (int) $admin['id']);
                $auditRepo->log((int) $admin['id'], 'ticket_checked_in', 'Checked in ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                $ticket['checked_in_at'] = date('Y-m-d H:i:s');
                $ticket['checked_in_by_admin_id'] = (int) $admin['id'];
                $ticket['checked_in_at_display'] = $ticket['checked_in_at'];

                return $reply(200, ['ok' => true, 'message' => 'Checked in.', 'ticket' => $ticket]);
            });

            $group->get('/checkin/lookup/ajax', function (Request $request, Response $response) use ($purchaseTickets, $purchaseTicketModifiers, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $q = trim((string) ($request->getQueryParams()['q'] ?? ''));

                $reply = function (int $code, array $data) use ($response): Response {
                    $json = json_encode($data, JSON_PRETTY_PRINT);
                    $response->getBody()->write($json === false ? '{}' : $json);
                    return $response
                        ->withStatus($code)
                        ->withHeader('Content-Type', 'application/json');
                };

                $rows = $purchaseTickets->listForCheckinLookup($q !== '' ? $q : null, 500);
                $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
                $ids = array_values(array_filter($ids, static fn (int $v): bool => $v > 0));

                $modRows = $purchaseTicketModifiers->listByPurchaseTicketIds($ids);
                $modsByTicketId = [];
                foreach ($modRows as $m) {
                    $tid = (int) ($m['purchase_ticket_id'] ?? 0);
                    if ($tid <= 0) {
                        continue;
                    }
                    $modsByTicketId[$tid] ??= [];
                    $modsByTicketId[$tid][] = $m;
                }

                foreach ($rows as &$r) {
                    $tid = (int) ($r['id'] ?? 0);
                    $r['modifiers'] = $modsByTicketId[$tid] ?? [];
                }
                unset($r);

                return $reply(200, ['ok' => true, 'tickets' => $rows]);
            });

            $group->post('/checkin/ajax/by-id', function (Request $request, Response $response) use ($purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $raw = (string) $request->getBody();
                $payload = json_decode($raw, true);
                $ticketId = (int) (($payload['purchase_ticket_id'] ?? 0) ?? 0);

                $reply = function (int $code, array $data) use ($response): Response {
                    $json = json_encode($data, JSON_PRETTY_PRINT);
                    $response->getBody()->write($json === false ? '{}' : $json);
                    return $response
                        ->withStatus($code)
                        ->withHeader('Content-Type', 'application/json');
                };

                if ($ticketId <= 0) {
                    return $reply(400, ['ok' => false, 'message' => 'Missing ticket id.']);
                }

                $ticket = $purchaseTickets->findById($ticketId);
                if (!$ticket) {
                    return $reply(404, ['ok' => false, 'message' => 'Ticket not found.']);
                }

                $ticket['modifiers'] = $purchaseTicketModifiers->listByPurchaseTicketId((int) $ticket['id']);
                $ticket['addons'] = $purchaseTicketAddons->listByPurchaseTicketId((int) $ticket['id']);
                if (!empty($ticket['checked_in_at'])) {
                    try {
                        $tz = new \DateTimeZone((string) ($config['app_timezone'] ?? 'America/Vancouver'));
                        $dt = new \DateTimeImmutable((string) $ticket['checked_in_at'], new \DateTimeZone('UTC'));
                        $ticket['checked_in_at_display'] = $dt->setTimezone($tz)->format('Y-m-d H:i:s');
                    } catch (\Throwable) {
                        $ticket['checked_in_at_display'] = (string) $ticket['checked_in_at'];
                    }
                }

                if (!empty($ticket['refunded_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_refunded', 'Attempted check-in for refunded ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $reply(409, ['ok' => false, 'message' => 'Ticket was refunded.', 'ticket' => $ticket]);
                }

                if (!empty($ticket['checked_in_at'])) {
                    $auditRepo->log((int) $admin['id'], 'ticket_checkin_duplicate', 'Attempted duplicate check-in for ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                    return $reply(409, ['ok' => false, 'message' => 'Already checked in.', 'ticket' => $ticket]);
                }

                $purchaseTickets->markCheckedIn((int) $ticket['id'], (int) $admin['id']);
                $auditRepo->log((int) $admin['id'], 'ticket_checked_in', 'Checked in ticket #' . $ticket['id'], RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));
                $ticket['checked_in_at'] = date('Y-m-d H:i:s');
                $ticket['checked_in_by_admin_id'] = (int) $admin['id'];
                $ticket['checked_in_at_display'] = $ticket['checked_in_at'];

                return $reply(200, ['ok' => true, 'message' => 'Checked in.', 'ticket' => $ticket]);
            });

            $group->post('/purchases/{id}/resend-email', function (Request $request, Response $response, array $args) use ($purchaseRepo, $purchaseTickets, $purchaseTicketModifiers, $purchaseTicketAddons, $mailer, $emailRenderer, $config) {
                $purchaseId = (int) ($args['id'] ?? 0);
                if ($purchaseId <= 0) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                $purchase = $purchaseRepo->findById($purchaseId);
                if (!$purchase) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                $toEmail = trim((string) ($purchase['email'] ?? ''));
                if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    return $response->withHeader('Location', '/admin/purchases/' . $purchaseId)->withStatus(302);
                }

                $tickets = $purchaseTickets->listByPurchaseId($purchaseId);
                $modRows = $purchaseTicketModifiers->listByPurchaseId($purchaseId);
                $addonRows = $purchaseTicketAddons->listByPurchaseId($purchaseId);

                $modsByTicketId = [];
                foreach ($modRows as $m) {
                    $tid = (int) ($m['purchase_ticket_id'] ?? 0);
                    $modsByTicketId[$tid] ??= [];
                    $modsByTicketId[$tid][] = $m;
                }

                $addonsByTicketId = [];
                foreach ($addonRows as $a) {
                    $tid = (int) ($a['purchase_ticket_id'] ?? 0);
                    $addonsByTicketId[$tid] ??= [];
                    $addonsByTicketId[$tid][] = $a;
                }

                $ticketsForEmail = [];
                $embeddedImages = [];
                foreach ($tickets as $t) {
                    $ticketId = (int) ($t['id'] ?? 0);
                    $qrToken = (string) ($t['qr_token'] ?? '');
                    if ($ticketId <= 0 || $qrToken === '') {
                        continue;
                    }

                    $qr = new QrCode(data: $qrToken, size: 280, margin: 12);
                    $qrPng = (new PngWriter())->write($qr)->getString();
                    $cid = 'ticket-' . $ticketId . '@qrcode';
                    $embeddedImages[] = [
                        'cid' => $cid,
                        'data' => $qrPng,
                        'filename' => 'ticket-' . $ticketId . '.png',
                        'mime' => 'image/png',
                    ];

                    $ticketsForEmail[] = [
                        'purchase_ticket_id' => $ticketId,
                        'event_name' => (string) ($t['event_name'] ?? ''),
                        'variation_name' => (string) ($t['variation_name'] ?? ''),
                        'unit_price_cents' => (int) ($t['unit_price_cents'] ?? 0),
                        'modifiers' => $modsByTicketId[$ticketId] ?? [],
                        'addons' => $addonsByTicketId[$ticketId] ?? [],
                        'qr_token' => $qrToken,
                        'qr_cid' => $cid,
                    ];
                }

                try {
                    $subject = (string) (($config['app_name'] ?? 'Simple Event Checkout') . ' — Order Confirmation');
                    $html = $emailRenderer->render('email/order-confirmation.twig', [
                        'app' => $config,
                        'purchase_id' => $purchaseId,
                        'total_cents' => (int) ($purchase['total_cents'] ?? 0),
                        'currency' => (string) ($purchase['currency'] ?? ($config['store_currency'] ?? 'CAD')),
                        'tickets' => $ticketsForEmail,
                    ]);
                    $mailer->send($toEmail, $toEmail, $subject, $html, null, $embeddedImages);
                    $purchaseRepo->markReceiptSent($purchaseId);
                } catch (\Throwable) {
                    // Ignore; admin can try again.
                    $purchaseRepo->markReceiptFailed($purchaseId, 'Admin resend failed (see server logs).');
                }

                return $response->withHeader('Location', '/admin/purchases/' . $purchaseId)->withStatus(302);
            });

            $group->post('/purchases/{id}/mark-refunded', function (Request $request, Response $response, array $args) use ($purchaseRepo, $purchaseTickets, $auditRepo, $config) {
                $admin = (array) $request->getAttribute('admin');
                $purchaseId = (int) ($args['id'] ?? 0);
                if ($purchaseId <= 0) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                $purchase = $purchaseRepo->findById($purchaseId);
                if (!$purchase) {
                    return $response->withHeader('Location', '/admin/purchases')->withStatus(302);
                }

                if (($purchase['payment_status'] ?? '') === 'refunded') {
                    return $response->withHeader('Location', '/admin/purchases/' . $purchaseId)->withStatus(302);
                }

                $purchaseRepo->update($purchaseId, [
                    'payment_status' => 'refunded',
                ]);
                $purchaseTickets->markRefundedByPurchaseId($purchaseId);

                $auditRepo->log((int) $admin['id'], 'purchase_refunded', 'Marked purchase #' . $purchaseId . ' as refunded', RequestUtil::clientIp($config, $request), $request->getHeaderLine('User-Agent'), $request->getHeaderLine('Referer'));

                return $response->withHeader('Location', '/admin/purchases/' . $purchaseId)->withStatus(302);
            });

            $group->post('/logout', function (Request $request, Response $response) {
                $adminAuth = $request->getAttribute('adminAuth');
                if ($adminAuth instanceof AdminAuth) {
                    $token = $_COOKIE[$adminAuth->cookieName()] ?? '';
                    if ($token !== '') {
                        $adminAuth->revokeSession($token);
                    }
                    setcookie($adminAuth->cookieName(), '', [
                        'expires' => time() - 3600,
                        'path' => '/admin',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            });
        })
            ->add(function (Request $request, $handler) use ($adminAuth) {
                return $handler->handle($request->withAttribute('adminAuth', $adminAuth));
            })
            ->add(new AdminMiddleware($adminAuth));

        // App 2FA enrollment (requires login).
        $app->get('/admin/2fa/enroll', function (Request $request, Response $response) use ($adminAuth, $adminRepo, $config) {
            $admin = $adminAuth->getCurrentAdmin();
            if (!$admin) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            if (empty($_SESSION['admin_2fa_secret'])) {
                // OTPHP defaults to a very large secret (64 bytes), which produces a very dense QR code.
                // 20 bytes (160 bits) is typical for TOTP and results in a more reasonable enrollment QR.
                $totp = TOTP::create(secretSize: 20);
                $totp->setLabel((string) $admin['username']);
                $totp->setIssuer($config['app_name'] ?? 'Simple Event Checkout');
                $_SESSION['admin_2fa_secret'] = $totp->getSecret();
            }

            $totp = TOTP::create((string) $_SESSION['admin_2fa_secret']);
            $totp->setLabel((string) $admin['username']);
            $totp->setIssuer($config['app_name'] ?? 'Simple Event Checkout');

            return Twig::fromRequest($request)->render($response, 'admin/2fa-enroll.twig', [
                'admin' => $admin,
                'secret' => $_SESSION['admin_2fa_secret'],
                'provisioning_uri' => $totp->getProvisioningUri(),
                'error' => null,
            ]);
        });

        $app->post('/admin/2fa/enroll', function (Request $request, Response $response) use ($adminAuth, $adminRepo, $config) {
            $admin = $adminAuth->getCurrentAdmin();
            if (!$admin) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            $secret = (string) ($_SESSION['admin_2fa_secret'] ?? '');
            if ($secret === '') {
                return $response->withHeader('Location', '/admin/2fa/enroll')->withStatus(302);
            }

            $data = (array) $request->getParsedBody();
            $code = trim((string) ($data['code'] ?? ''));
            $totp = TOTP::create($secret);
            $totp->setLabel((string) $admin['username']);
            $totp->setIssuer($config['app_name'] ?? 'Simple Event Checkout');

            if ($code === '') {
                return Twig::fromRequest($request)->render($response, 'admin/2fa-enroll.twig', [
                    'admin' => $admin,
                    'secret' => $secret,
                    'provisioning_uri' => $totp->getProvisioningUri(),
                    'error' => 'Enter the code from your authenticator app.',
                ]);
            }

            if (!$totp->verify($code, null, 1)) {
                return Twig::fromRequest($request)->render($response, 'admin/2fa-enroll.twig', [
                    'admin' => $admin,
                    'secret' => $secret,
                    'provisioning_uri' => $totp->getProvisioningUri(),
                    'error' => 'Invalid authentication code.',
                ]);
            }

            $adminRepo->updateTotpSecret((int) $admin['id'], $secret, true);
            unset($_SESSION['admin_2fa_secret']);

            return $response->withHeader('Location', '/admin')->withStatus(302);
        });

        $app->post('/admin/2fa/enroll/reset', function (Request $request, Response $response) use ($adminAuth) {
            $admin = $adminAuth->getCurrentAdmin();
            if (!$admin) {
                return $response->withHeader('Location', '/admin/login')->withStatus(302);
            }

            unset($_SESSION['admin_2fa_secret']);
            return $response->withHeader('Location', '/admin/2fa/enroll')->withStatus(302);
        });
    }


    private static function rotateSessionSecurity(): void
    {
        // Prevent session fixation after successful login.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        // Rotate CSRF token on login.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function sendAdminEmail2faCode(array $config, array $admin, Mailer $mailer, EmailRenderer $renderer): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['admin_2fa_email_code_hash'] = hash('sha256', $code);
        $_SESSION['admin_2fa_email_expires_at'] = time() + 600;
        $_SESSION['admin_2fa_email_attempts'] = 0;

        $html = $renderer->render('email/2fa.twig', [
            'app' => $config,
            'code' => $code,
            'expires_minutes' => 10,
        ]);

        $mailer->send((string) $admin['email'], (string) $admin['username'], ($config['app_name'] ?? 'Simple Event Checkout') . ' 2FA Code', $html);
    }

    private static function toDatetimeLocal(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        // MySQL DATETIME "YYYY-MM-DD HH:MM:SS" -> input[type=datetime-local] "YYYY-MM-DDTHH:MM"
        $value = str_replace(' ', 'T', $value);
        return substr($value, 0, 16);
    }

    private static function eventPayloadFromRequest(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        $short = trim((string) ($data['short_description'] ?? ''));
        $long = (string) ($data['long_description'] ?? '');
        $location = trim((string) ($data['location'] ?? ''));
        $status = (string) ($data['status'] ?? 'draft');

        $imagePathRaw = trim((string) ($data['image_path'] ?? ''));

        $start = trim((string) ($data['start_time'] ?? ''));
        $end = trim((string) ($data['end_time'] ?? ''));

        $priceCentsRaw = trim((string) ($data['price_cents'] ?? ''));
        $stockLimitRaw = trim((string) ($data['stock_limit'] ?? ''));

        $error = null;
        if ($name === '' || $slug === '' || $short === '') {
            $error = 'Name, slug, and short description are required.';
        }

        $allowed = ['draft', 'published', 'unlisted', 'archived'];
        if (!in_array($status, $allowed, true)) {
            $status = 'draft';
        }

        $imageOptions = self::listEventImageOptions();
        $imagePath = null;
        if ($imagePathRaw !== '') {
            if (!in_array($imagePathRaw, $imageOptions, true)) {
                $error = $error ?? 'Invalid image selection.';
            } else {
                $imagePath = $imagePathRaw;
            }
        }

        $event = [
            'name' => $name,
            'slug' => $slug,
            'short_description' => $short,
            'long_description' => $long !== '' ? $long : null,
            'location' => $location !== '' ? $location : null,
            'status' => $status,
            'image_path' => $imagePath,
            'start_time' => $start !== '' ? str_replace('T', ' ', $start) . ':00' : null,
            'end_time' => $end !== '' ? str_replace('T', ' ', $end) . ':00' : null,
            'price_cents' => $priceCentsRaw !== '' ? (int) $priceCentsRaw : null,
            'stock_limit' => $stockLimitRaw !== '' ? (int) $stockLimitRaw : null,
        ];

        // Values for re-rendering the form on error.
        $event['start_time_local'] = $start;
        $event['end_time_local'] = $end;

        return ['event' => $event, 'error' => $error];
    }

    private static function listEventImageOptions(): array
    {
        $dir = dirname(__DIR__, 2) . '/assets/img/products';
        if (!is_dir($dir)) {
            return [];
        }

        $allowedExt = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];
        $out = [];

        foreach (scandir($dir) ?: [] as $file) {
            // Ignore dotfiles (e.g. .gitkeep) and other hidden entries.
            if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
                continue;
            }
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                continue;
            }

            // Store as a web path (not filesystem path).
            $out[] = '/assets/img/products/' . $file;
        }

        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private static function variationPayloadFromRequest(array $data, int $eventId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $priceRaw = trim((string) ($data['price_cents'] ?? ''));
        $stockRaw = trim((string) ($data['stock_limit'] ?? ''));
        $sortRaw = trim((string) ($data['sort_order'] ?? '0'));

        $error = null;
        if ($name === '' || $priceRaw === '') {
            $error = 'Name and price are required.';
        }

        $variation = [
            'event_id' => $eventId,
            'name' => $name,
            'price_cents' => (int) $priceRaw,
            'stock_limit' => $stockRaw !== '' ? (int) $stockRaw : null,
            'sort_order' => (int) $sortRaw,
        ];

        return ['variation' => $variation, 'error' => $error];
    }

    private static function modifierPayloadFromRequest(array $data, int $eventId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['modifier_type'] ?? 'text');
        $required = (int) ($data['is_required'] ?? 0);
        $sortRaw = trim((string) ($data['sort_order'] ?? '0'));

        $allowedTypes = ['text', 'checkbox'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $error = null;
        if ($name === '') {
            $error = 'Name is required.';
        }

        $modifier = [
            'event_id' => $eventId,
            'name' => $name,
            'modifier_type' => $type,
            'is_required' => $required ? 1 : 0,
            'sort_order' => (int) $sortRaw,
        ];

        return ['modifier' => $modifier, 'error' => $error];
    }

    private static function addonPayloadFromRequest(array $data, int $eventId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $short = trim((string) ($data['short_description'] ?? ''));
        $priceRaw = trim((string) ($data['price_cents'] ?? ''));
        $stockRaw = trim((string) ($data['stock_limit'] ?? ''));

        $error = null;
        if ($name === '' || $short === '') {
            $error = 'Name and short description are required.';
        }

        $addon = [
            'event_id' => $eventId,
            'name' => $name,
            'short_description' => $short,
            'price_cents' => $priceRaw !== '' ? (int) $priceRaw : null,
            'stock_limit' => $stockRaw !== '' ? (int) $stockRaw : null,
        ];

        return ['addon' => $addon, 'error' => $error];
    }
}
