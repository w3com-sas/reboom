<?php

use W3com\BoomBundle\Annotation\MetadataReader;
use W3com\BoomBundle\Annotation\EntityColumnMeta;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\Log\NullLogger;
use W3com\BoomBundle\Annotation\EntityMeta;
use W3com\BoomBundle\DependencyInjection\W3comBoomExtension;
use W3com\BoomBundle\Form\BoomTypeGuesser;
use W3com\BoomBundle\HanaEntity\UserTablesMD;
use W3com\BoomBundle\Service\BoomManager;

/**
 * @EntityMeta(read="sl", write="sl", aliasRead="Legacy")
 * @\W3com\BoomBundle\Annotation\EntitySynchronizedData(TableName="LEGACY", TableType="bott_NoObject")
 */
class LegacyFixture extends \W3com\BoomBundle\HanaEntity\AbstractEntity
{
    /**
     * @EntityColumnMeta(column="OldCode", isKey=true, description="Code", type="string")
     * @\W3com\BoomBundle\Annotation\SynchronizedData(Name="Code", Type="db_Alpha", Mandatory="tYES")
     */
    protected $code;
}

/** @EntityMeta(read="sl", write="sl", aliasRead="Legacy") */
#[EntityMeta(read: 'ods', write: 'sl', aliasRead: 'Native')]
#[\W3com\BoomBundle\Annotation\EntitySynchronizedData(TableName: 'NATIVE', TableType: 'bott_NoObject')]
class MixedFixture
{
    /** @EntityColumnMeta(column="OldCode") */
    #[EntityColumnMeta(column: 'NewCode', isKey: true)]
    #[\W3com\BoomBundle\Annotation\SynchronizedData(Name: 'Code', Type: 'db_Alpha', Mandatory: 'tYES')]
    public $code;
}

final class CompatibilityTest extends TestCase
{
    public function testExistingAnnotationsAndFormGuesser(): void
    {
        $reader = new MetadataReader();
        $meta = $reader->getClassAnnotation(new ReflectionClass(LegacyFixture::class), EntityMeta::class);
        self::assertSame('Legacy', $meta->aliasRead);
        self::assertSame('OldCode', $reader->getPropertyAnnotation(new ReflectionProperty(LegacyFixture::class, 'code'), EntityColumnMeta::class)->column);
        self::assertSame('code', (new LegacyFixture())->getFieldByDescription('Code'));
        self::assertSame('Native', $reader->getClassAnnotation(new ReflectionClass(MixedFixture::class), EntityMeta::class)->aliasRead);
        self::assertSame('NewCode', $reader->getPropertyAnnotation(new ReflectionProperty(MixedFixture::class, 'code'), EntityColumnMeta::class)->column);
        foreach ([LegacyFixture::class => 'LEGACY', MixedFixture::class => 'NATIVE'] as $fixture => $table) {
            $reflection = new ReflectionClass($fixture);
            self::assertSame($table, $reader->getClassAnnotation($reflection, \W3com\BoomBundle\Annotation\EntitySynchronizedData::class)->TableName);
            self::assertSame('tYES', $reader->getPropertyAnnotation($reflection->getProperty('code'), \W3com\BoomBundle\Annotation\SynchronizedData::class)->Mandatory);
        }
        self::assertSame('UserTablesMD', $reader->getClassAnnotation(new ReflectionClass(UserTablesMD::class), EntityMeta::class)->aliasRead);
        self::assertNotNull((new BoomTypeGuesser())->guessType(UserTablesMD::class, 'tableName'));
    }

    public function testGeneratedEntityUsesAttributes(): void
    {
        $dir = sys_get_temp_dir() . '/boom-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $manager = $this->createMock(BoomManager::class);
            $manager->config = ['entity_directory' => $dir, 'app_namespace' => 'GeneratedBoomTest'];
            $generator = $this->createMock(\W3com\BoomBundle\Service\BoomGenerator::class);
            $entity = new \W3com\BoomBundle\Generator\Model\Entity();
            $entity->setName('GeneratedEntity');
            $entity->setTable('Table');
            $property = new \W3com\BoomBundle\Generator\Model\Property();
            $property->setName('code');
            $property->setField('Code');
            $property->setDescription('A "quoted" description');
            $property->setFieldType('string');
            $property->setHasQuotes(true);
            $property->setVar('string');
            $property->setIsKey(true);
            $entity->setProperties([$property]);
            (new \W3com\BoomBundle\Generator\ClassCreator($manager, $generator))->generateClass($entity);
            require $dir . '/GeneratedEntity.php';
            $reader = new MetadataReader();
            $class = new ReflectionClass('GeneratedBoomTest\\HanaEntity\\GeneratedEntity');
            self::assertSame('Table', $reader->getClassAnnotation($class, EntityMeta::class)->aliasRead);
            self::assertSame('A "quoted" description', $reader->getPropertyAnnotation($class->getProperty('code'), EntityColumnMeta::class)->description);
            $inspector = $this->createMock(\W3com\BoomBundle\Generator\SLInspector::class);
            $inspector->method('addMetaToEntity')->willReturn($entity);
            $generator->method('getSLInspector')->willReturn($inspector);
            (new \W3com\BoomBundle\Generator\ClassCreator($manager, $generator))->addAndRemovePropertiesToExistingClass([], [], $entity);
            $rewritten = file_get_contents($dir . '/GeneratedEntity.php');
            self::assertStringContainsString('#[EntityMeta(', $rewritten);
            self::assertStringContainsString('#[EntityColumnMeta(', $rewritten);
        } finally {
            @unlink($dir . '/GeneratedEntity.php');
            @rmdir($dir);
        }
    }

    public function testCollectorHandlesRequestsWithAndWithoutSapActivity(): void
    {
        $manager = (new ReflectionClass(BoomManager::class))->newInstanceWithoutConstructor();
        $collector = new \W3com\BoomBundle\DataCollector\HanaDataCollector($manager);
        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = new \Symfony\Component\HttpFoundation\Response();

        $collector->collect($request, $response);
        self::assertSame([], $collector->getData());
        self::assertSame(0, $collector->countErrors());

        $manager->addToCollectedData('SL', 500, '/Items', [], '{}');
        $collector->collect($request, $response);
        self::assertCount(1, $collector->getData());
        self::assertSame('/Items', $collector->getData()[0]['uri']);
        self::assertSame(1, $collector->countErrors());
    }

    public function testServiceLayerRequest(): void
    {
        $handler = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200, [], '{"value":[{"Code":"A"}]}')]);
        $client = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($handler)]);
        $manager = $this->createMock(BoomManager::class);
        $manager->config = ['service_layer' => ['max_login_attempts' => 1]];
        $manager->stopwatch = new \Symfony\Component\Stopwatch\Stopwatch();
        $manager->method('getCurrentSLClient')->willReturn($client);
        self::assertSame([['Code' => 'A']], (new \W3com\BoomBundle\RestClient\SLRestClient($manager))->get('Items'));
    }

    public function testContainerCompilesWithoutDebugStopwatch(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setDefinition('logger', new Definition(NullLogger::class));
        $container->setDefinition(RequestStack::class, new Definition(RequestStack::class));
        $container->setDefinition(Symfony\Component\EventDispatcher\EventDispatcherInterface::class, new Definition(EventDispatcher::class));
        $container->setDefinition(KernelInterface::class, (new Definition())->setSynthetic(true)->setPublic(true));
        (new W3comBoomExtension())->load([['service_layer' => ['cookies_storage_path' => sys_get_temp_dir(), 'connections' => ['default' => ['uri' => 'https://example.test', 'username' => 'test', 'database' => 'test']]], 'odata_service' => ['connections' => ['default' => ['uri' => 'https://example.test', 'username' => 'test', 'password' => 'test']]]]], $container);
        foreach ([\W3com\BoomBundle\Command\ConvertAnnotationsCommand::class, \W3com\BoomBundle\Command\MakeSLEntityCommand::class, \W3com\BoomBundle\Command\MakeODSEntityCommand::class, \W3com\BoomBundle\Command\SynchronizeCommand::class, \W3com\BoomBundle\Command\CreateUDTCommand::class, \W3com\BoomBundle\Command\ClearCacheCommand::class] as $command) {
            $container->getDefinition($command)->setPublic(true);
        }
        $container->compile();
        $container->set(KernelInterface::class, $this->createMock(KernelInterface::class));
        self::assertInstanceOf(BoomManager::class, $container->get(BoomManager::class));
        foreach ([\W3com\BoomBundle\Command\ConvertAnnotationsCommand::class, \W3com\BoomBundle\Command\MakeSLEntityCommand::class, \W3com\BoomBundle\Command\MakeODSEntityCommand::class, \W3com\BoomBundle\Command\SynchronizeCommand::class, \W3com\BoomBundle\Command\CreateUDTCommand::class, \W3com\BoomBundle\Command\ClearCacheCommand::class] as $command) {
            self::assertNotEmpty($container->get($command)->getName());
        }
    }
}
