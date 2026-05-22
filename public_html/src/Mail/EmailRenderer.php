<?php

declare(strict_types=1);

namespace App\Mail;

use Slim\Views\Twig;

final class EmailRenderer
{
    public function __construct(private Twig $twig)
    {
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->fetch($template, $data);
    }
}
