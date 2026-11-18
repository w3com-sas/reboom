<?php

namespace W3com\BoomBundle\Annotation;

use Doctrine\Common\Annotations\AnnotationReader;

/** Reads native attributes first and falls back to legacy Doctrine annotations. */
final class MetadataReader
{
    private AnnotationReader $legacy;

    public function __construct()
    {
        $this->legacy = new AnnotationReader();
    }

    public function getClassAnnotation(\ReflectionClass $class, string $name): ?object
    {
        $attributes = $class->getAttributes($name, \ReflectionAttribute::IS_INSTANCEOF);
        return $attributes ? $attributes[0]->newInstance() : $this->legacy->getClassAnnotation($class, $name);
    }

    public function getPropertyAnnotation(\ReflectionProperty $property, string $name): ?object
    {
        $attributes = $property->getAttributes($name, \ReflectionAttribute::IS_INSTANCEOF);
        return $attributes ? $attributes[0]->newInstance() : $this->legacy->getPropertyAnnotation($property, $name);
    }

    public function getClassAnnotations(\ReflectionClass $class): array
    {
        return array_values(array_filter(array_map(fn ($name) => $this->getClassAnnotation($class, $name), [EntityMeta::class, EntitySynchronizedData::class])));
    }

    public function getPropertyAnnotations(\ReflectionProperty $property): array
    {
        return array_values(array_filter(array_map(fn ($name) => $this->getPropertyAnnotation($property, $name), [EntityColumnMeta::class, SynchronizedData::class])));
    }
}
