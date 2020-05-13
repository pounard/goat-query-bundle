<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\Twig;

use Doctrine\SqlFormatter\SqlFormatter;
use Twig\TwigFunction;
use Twig\Extension\AbstractExtension;

/**
 * @codeCoverageIgnore
 */
final class ProfilerExtension extends AbstractExtension
{
    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        return [
            new TwigFunction(
                'goat_format_sql',
                static function (string $raw): string {
                    if (\class_exists(SqlFormatter::class)) {
                        return SqlFormatter::format($raw, true);
                    }
                    return \str_replace("\n", "<br/>", \str_replace("\n\n", "\n", \trim($raw)));
                },
                ['safe' => ['html']]
            ),
        ];
    }
}
