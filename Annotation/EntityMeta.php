<?php

namespace W3com\BoomBundle\Annotation;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("CLASS")
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class EntityMeta
{
    use MetadataArguments;
    /**
     * @Annotation\Enum({"sl","ods","odsl"})
     */
    public $read;

    /**
     * @Annotation\Enum({"sl","ods","odsl"})
     */
    public $write;

    /**
     * @var string
     */
    public $aliasRead = null;

    /**
     * @var string
     */
    public $aliasWrite = null;

    /**
     * @var string
     */
    public $aliasSearch = null;

    /**
     * @var string
     */
    public $description = '';

    /**
     * @var bool
     */
    public $synchro = false;

    /**
     * @var bool
     */
    public $isComplex = false;
}
