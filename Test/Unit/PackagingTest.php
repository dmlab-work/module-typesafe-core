<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PackagingTest extends TestCase
{
    private const HEADER_LINE = 'Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';
    private const PHRASE_DIRS = ['Api', 'Block', 'Console', 'Controller', 'Cron', 'Exception', 'Model'];

    public function testComposerMetadata(): void
    {
        $composer = $this->composer();

        self::assertSame('dmlab/module-typesafe-core', $composer['name']);
        self::assertSame('magento2-module', $composer['type']);
        self::assertSame('0.0.1', $composer['version']);
        self::assertSame('OSL-3.0', $composer['license']);
    }

    public function testRequireSetIsExact(): void
    {
        $composer = $this->composer();

        self::assertSame(
            [
                'php' => '~8.3.0||~8.4.0||~8.5.0',
                'ext-curl' => '*',
                'magento/framework' => '^103.0.9',
                'magento/module-backend' => '^102.0.9',
                'magento/module-config' => '^101.2.9',
                'symfony/console' => '^6.4||^7.0',
                'psr/log' => '^2.0||^3.0',
            ],
            $composer['require']
        );
        self::assertSame(['phpunit/phpunit' => '^12.0'], $composer['require-dev']);
        self::assertStringNotContainsString('>=', $this->read('composer.json'));
    }

    public function testAutoload(): void
    {
        $autoload = $this->composer()['autoload'];

        self::assertSame(['registration.php'], $autoload['files']);
        self::assertSame(['DmLab\\TypeSafeCore\\' => ''], $autoload['psr-4']);
    }

    public function testEverySourceFileCarriesTheLicenseHeader(): void
    {
        $files = $this->files(['.'], ['php', 'xml', 'phtml', 'js']);
        self::assertNotEmpty($files);

        $missing = [];
        foreach ($files as $file) {
            if (!str_contains($this->read($file), self::HEADER_LINE)) {
                $missing[] = $file;
            }
        }
        self::assertSame([], $missing, 'Files without the OSL-3.0 header.');
    }

    public function testEveryPhraseLiteralIsTranslated(): void
    {
        $csv = $this->translations();
        $phrases = [];
        foreach ($this->files(self::PHRASE_DIRS, ['php']) as $file) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $this->read($file), $matches);
            foreach ($matches[1] as $literal) {
                $phrases[] = stripcslashes($literal);
            }
        }
        self::assertNotEmpty($phrases);

        self::assertSame([], array_values(array_diff(array_unique($phrases), $csv)), 'Phrases missing in en_US.csv.');
    }

    public function testEveryConfigAclAndScriptStringIsTranslated(): void
    {
        $phrases = [];
        $system = simplexml_load_file($this->path('etc/adminhtml/system.xml'));
        foreach ($system->xpath('//*[@translate]') as $node) {
            foreach (explode(' ', (string)$node['translate']) as $child) {
                if (trim((string)$node->{$child}) !== '') {
                    $phrases[] = trim((string)$node->{$child});
                }
            }
        }
        foreach (simplexml_load_file($this->path('etc/acl.xml'))->xpath('//resource[@title]') as $resource) {
            $phrases[] = (string)$resource['title'];
        }
        foreach ($this->files(['view'], ['js']) as $file) {
            preg_match_all("/\\\$t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $this->read($file), $matches);
            array_push($phrases, ...$matches[1]);
        }
        self::assertContains('Monthly Token Budget', $phrases);
        self::assertContains('TypeSafe Section', $phrases);
        self::assertContains('Connection test request failed.', $phrases);

        self::assertSame(
            [],
            array_values(array_diff(array_unique($phrases), $this->translations())),
            'Phrases missing in en_US.csv.'
        );
    }

    public function testCsvRowsAreIdentityPairs(): void
    {
        $handle = fopen($this->path('i18n/en_US.csv'), 'rb');
        self::assertNotFalse($handle);
        try {
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                self::assertCount(2, $row);
                self::assertSame($row[0], $row[1]);
            }
        } finally {
            fclose($handle);
        }
    }

    public function testLicenseIsOsl3(): void
    {
        self::assertStringStartsWith('Open Software License ("OSL") v. 3.0', $this->read('LICENSE'));
    }

    public function testChangelogAndGitattributes(): void
    {
        self::assertStringContainsString("\n## 0.0.1\n", $this->read('CHANGELOG.md'));

        $attributes = $this->read('.gitattributes');
        self::assertStringContainsString('/Test export-ignore', $attributes);
        self::assertStringContainsString('/.github export-ignore', $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function composer(): array
    {
        return json_decode($this->read('composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string[]
     */
    private function translations(): array
    {
        $phrases = [];
        $handle = fopen($this->path('i18n/en_US.csv'), 'rb');
        self::assertNotFalse($handle);
        try {
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $phrases[] = (string)$row[0];
            }
        } finally {
            fclose($handle);
        }

        return $phrases;
    }

    /**
     * @param string[] $dirs
     * @param string[] $extensions
     * @return string[] paths relative to the module root
     */
    private function files(array $dirs, array $extensions): array
    {
        $root = $this->path('');
        $files = [];
        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $relative = substr($file->getPathname(), strlen($root));
                $relative = str_starts_with($relative, './') ? substr($relative, 2) : $relative;
                if (preg_match('#(^|/)(vendor|\.[^/]+)/#', $relative)
                    || !in_array($file->getExtension(), $extensions, true)
                ) {
                    continue;
                }
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));
        self::assertNotFalse($contents);

        return $contents;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }
}
