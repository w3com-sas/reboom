<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use W3com\BoomBundle\Command\ConvertAnnotationsCommand;
use W3com\BoomBundle\Migration\AnnotationConverter;

final class ConvertAnnotationsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/boom-convert-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $file) unlink($file);
        rmdir($this->dir);
    }

    private function source(): string
    {
        return <<<'SRC'
<?php
namespace ConvertFixture;
use W3com\BoomBundle\Annotation\{EntityMeta as Meta, EntityColumnMeta, EntitySynchronizedData, SynchronizedData};
/**
 * Keep this documentation.
 * @Meta(
 *     read="sl",
 *     write="sl",
 *     aliasRead="Table"
 * )
 * @EntitySynchronizedData(TableName="TABLE", TableType="bott_NoObject")
 */
class Entity {
    /**
     * Field documentation.
     * @EntityColumnMeta(column="Code", isKey=true, description="A (quoted) value", type="string")
     * @SynchronizedData(Name="Code", Type="db_Alpha", Mandatory="tYES")
     */
    protected $code;
}
SRC;
    }

    public function testDryRunConversionAndIdempotence(): void
    {
        $file = $this->dir . '/Entity.php';
        $source = $this->source();
        file_put_contents($file, $source);
        $tester = new CommandTester(new ConvertAnnotationsCommand(['entity_directory' => $this->dir]));
        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame($source, file_get_contents($file));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $converted = file_get_contents($file);
        self::assertStringContainsString('Keep this documentation.', $converted);
        self::assertStringContainsString('Field documentation.', $converted);
        self::assertStringNotContainsString('@EntityColumnMeta', $converted);
        self::assertStringContainsString('#[\\W3com\\BoomBundle\\Annotation\\EntityMeta(', $converted);
        self::assertSame($converted, (new AnnotationConverter())->convert($converted));
        require $file;
        $reader = new \W3com\BoomBundle\Annotation\MetadataReader();
        $class = new ReflectionClass('ConvertFixture\\Entity');
        self::assertSame('Table', $reader->getClassAnnotation($class, \W3com\BoomBundle\Annotation\EntityMeta::class)->aliasRead);
        self::assertSame('TABLE', $reader->getClassAnnotation($class, \W3com\BoomBundle\Annotation\EntitySynchronizedData::class)->TableName);
        self::assertSame('A (quoted) value', $reader->getPropertyAnnotation($class->getProperty('code'), \W3com\BoomBundle\Annotation\EntityColumnMeta::class)->description);
        self::assertSame('tYES', $reader->getPropertyAnnotation($class->getProperty('code'), \W3com\BoomBundle\Annotation\SynchronizedData::class)->Mandatory);
    }

    public function testConflictDoesNotChangeFileAndReportsFailure(): void
    {
        $source = str_replace('class Entity {', "#[Meta(read: 'ods')]\nclass Entity {", $this->source());
        $file = $this->dir . '/Entity.php';
        file_put_contents($file, $source);
        $tester = new CommandTester(new ConvertAnnotationsCommand(['entity_directory' => $this->dir]));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame($source, file_get_contents($file));
        self::assertStringContainsString('Both annotation and attribute', $tester->getDisplay());
    }
}
