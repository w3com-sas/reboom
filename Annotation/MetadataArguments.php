<?php

namespace W3com\BoomBundle\Annotation;

/** Accept Doctrine's array constructor and PHP attribute named arguments. */
trait MetadataArguments
{
    public function __construct(...$arguments)
    {
        if (count($arguments) === 1 && isset($arguments[0]) && is_array($arguments[0])) {
            $arguments = $arguments[0];
        }
        foreach ($arguments as $name => $value) {
            if (!is_string($name) || !property_exists($this, $name)) {
                throw new \InvalidArgumentException(sprintf('Unknown metadata argument "%s" for %s.', $name, static::class));
            }
            $this->$name = $value;
        }
    }
}
