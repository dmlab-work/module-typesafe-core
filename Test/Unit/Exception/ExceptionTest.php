<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Exception;

use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<TypeSafeException>}>
     */
    public static function classProvider(): array
    {
        return [
            'base' => [TypeSafeException::class],
            'not configured' => [NotConfiguredException::class],
            'authentication' => [AuthenticationException::class],
            'rate limit' => [RateLimitException::class],
            'budget' => [BudgetExceededException::class],
            'circuit' => [CircuitOpenException::class],
            'request' => [RequestException::class],
            'transport' => [TransportException::class],
            'invalid answer' => [InvalidAnswerException::class],
        ];
    }

    #[DataProvider('classProvider')]
    public function testFamilyKeepsParentConstructor(string $class): void
    {
        $cause = new \RuntimeException('inner');
        $exception = new $class(new Phrase('TypeSafe answered HTTP %1.', [503]), $cause, 503);

        self::assertInstanceOf(TypeSafeException::class, $exception);
        self::assertInstanceOf(LocalizedException::class, $exception);
        self::assertSame('TypeSafe answered HTTP 503.', $exception->getMessage());
        self::assertSame(503, $exception->getCode());
        self::assertSame($cause, $exception->getPrevious());
        self::assertFalse((new \ReflectionClass($class))->isFinal());
        self::assertStringContainsString('@api', (string)(new \ReflectionClass($class))->getDocComment());
    }

    #[DataProvider('classProvider')]
    public function testPhraseOnlyConstruction(string $class): void
    {
        $exception = new $class(new Phrase('TypeSafe is disabled.'));

        self::assertSame('TypeSafe is disabled.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testRateLimitCarriesRetryAfterAndStatus(): void
    {
        $phrase = new Phrase('TypeSafe rate limit reached; retry after %1 s.', [120]);
        $exception = new RateLimitException($phrase, null, 429, 120);

        self::assertSame(120, $exception->getRetryAfter());
        self::assertSame(429, $exception->getCode());
        self::assertSame('TypeSafe rate limit reached; retry after 120 s.', $exception->getMessage());
    }

    public function testRateLimitRetryAfterDefaultsToNull(): void
    {
        self::assertNull((new RateLimitException(new Phrase('TypeSafe rate limit reached.')))->getRetryAfter());
    }

    public function testInvalidAnswerIsATransportFailure(): void
    {
        self::assertInstanceOf(TransportException::class, new InvalidAnswerException(new Phrase('bad answer')));
    }
}
