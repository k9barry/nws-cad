<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\FileWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileWatcher::class)]
#[UsesClass(\NwsCad\Config::class)]
#[UsesClass(\NwsCad\Logger::class)]
#[UsesClass(\NwsCad\Logging\RedactingProcessor::class)]
#[UsesClass(\NwsCad\Logging\SecretRegistry::class)]
#[UsesClass(\NwsCad\AegisXmlParser::class)]
#[UsesClass(\NwsCad\Database::class)]
final class FileWatcherSetOnTickTest extends TestCase
{
    public function testSetOnTickAcceptsCallableAndCanBeInspected(): void
    {
        $watcher = new FileWatcher();

        $count   = 0;
        $watcher->setOnTick(static function () use (&$count): void { $count++; });

        $refl = new \ReflectionClass($watcher);
        $prop = $refl->getProperty('onTick');
        $prop->setAccessible(true);
        $cb = $prop->getValue($watcher);
        $this->assertIsCallable($cb);
        $cb();
        $cb();
        $this->assertSame(2, $count);
    }
}
