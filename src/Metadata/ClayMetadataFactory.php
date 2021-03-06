<?php

namespace Lexide\Reposition\Clay\Metadata;

use Lexide\Clay\Model\NameConverterTrait;
use Lexide\Reposition\Exception\MetadataException;
use Lexide\Reposition\Metadata\EntityMetadata;
use Lexide\Reposition\Metadata\EntityMetadataFactoryInterface;

class ClayMetadataFactory implements EntityMetadataFactoryInterface
{
    use NameConverterTrait;

    protected $getters = [];

    protected $setters = [];

    protected $adders = [];

    protected $owningClass = [];

    /**
     * {@inheritDoc}
     */
    public function createMetadata($reference)
    {
        // clear the method arrays
        $this->getters = [];
        $this->setters = [];
        $this->adders = [];

        $ref = new \ReflectionClass($reference);

        $this->findClayMethods($ref);

        $entityMetadata = new EntityMetadata($ref->getName());

        /*
        For each setter (or adder) that has a getter (e.g. a property that is fully accessible) check if it has a
        relationship to another entity class, otherwise detect the property type
        */

        foreach ($this->setters as $property => $setterMethod) {
            if (empty($this->getters[$property])) {
                // can't continue without a getter
                continue;
            }
            /** @var \ReflectionMethod $setterMethod */
            /** @var \ReflectionParameter $valueParam */
            $valueParam = $setterMethod->getParameters()[0];
            if ($valueParam->isArray()) {
                // this is a collection, check for an adder so we can check type on the collection elements
                if (!empty($this->adders[$property])) {
                    $setterMethod = $this->adders[$property];
                    $arguments = $setterMethod->getParameters();
                    // take the last argument, as the value parameter will be #2 for adders that apply to associative arrays
                    $valueParam = end($arguments);
                }
            }
            $class = $valueParam->getClass();
            // ignore properties that have relationships with other entities
            if (empty($class) || empty($class->getNamespaceName())) {

                /** @var \ReflectionMethod $getterMethod */
                $getterMethod = $this->getters[$property];
                $getter = $getterMethod->getName();
                $setter = $setterMethod->getName();

                if ($valueParam->isArray()) {
                    $type = EntityMetadata::FIELD_TYPE_ARRAY;
                } else {
                    // detect type
                    $type = $this->detectPropertyType($this->owningClass[$property], $getter, $setter, $property);
                }
                $fieldMetadata = [
                    EntityMetadata::METADATA_FIELD_TYPE => $type,
                    EntityMetadata::METADATA_FIELD_GETTER => $getter,
                    EntityMetadata::METADATA_FIELD_SETTER => $setter,
                ];

                // underscore the property name
                $property = $this->toSplitCase($property);

                $entityMetadata->addFieldMetadata($property, $fieldMetadata);
            }
        }

        return $entityMetadata;

    }

    public function createEmptyMetadata()
    {
        return new EntityMetadata("");
    }

    /**
     * search through all the public methods of this class and save all the getters, setters and adders
     *
     * @param \ReflectionClass $ref
     * @param bool $isSubclass
     */
    protected function findClayMethods(\ReflectionClass $ref, $isSubclass = false)
    {
        $publicMethods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            $name = $method->getName();
            $property = lcfirst(substr($name, 3));
            $matches = [];
            if (preg_match("/^(get|set|add)[A-Z]+.*/", $name, $matches)) {
                switch ($name[0]) {
                    case "g":
                        $collection = "getters";
                        break;
                    case "s":
                        $collection = "setters";
                        break;
                    case "a":
                        $collection = "adders";
                        break;
                }
                $this->{$collection}[$property] = $method;
                if (empty($this->owningClass[$property])) {
                    $this->owningClass[$property] = $ref;
                }
            }
        }

        if (!$isSubclass) {
            $defaultProperties = $ref->getDefaultProperties();
            if (!empty($defaultProperties["modelDiscriminatorMap"])) {
                $this->findSubclassClayMethods($defaultProperties["modelDiscriminatorMap"], $ref->getNamespaceName());
            }
        }
    }

    /**
     * @param array $discriminator
     * @param string $parentNamespace
     * @throws MetadataException
     */
    protected function findSubclassClayMethods(array $discriminator, $parentNamespace)
    {
        // get defaults for if we're not using FQCNs
        $namespace = !empty($discriminator["subclassNamespace"])? $discriminator["subclassNamespace"]: $parentNamespace;
        $suffix = !empty($discriminator["subclassSuffix"])? $discriminator["subclassSuffix"]: "";

        if (empty($discriminator["map"])) {
            throw new MetadataException("Invalid entity inheritance configuration. The discriminator map was missing");
        }
        $map = $discriminator["map"];
        foreach ($map as $type => $class) {
            // if the class is "true", use the type instead
            if ($class === true) {
                $class = $type;
            }
            $class = $this->toStudlyCaps($class);

            // check each form to see if the class exists (FQCN, with namespace, with namespace and suffix)
            if (!class_exists($class)) {
                $class = $namespace . "\\" . $class;
                if (!class_exists($class)) {
                    $class .= $suffix;
                    if (!class_exists($class)) {
                        throw new MetadataException("Invalid entity inheritance configuration. The subclass type '$type' did not map to a class that exists");
                    }
                }
            }

            $ref = new \ReflectionClass($class);

            $this->findClayMethods($ref, true);
        }
    }

    protected function detectPropertyType(\ReflectionClass $ref, $getter, $setter, $property)
    {
        // if the entity has a constructor, check it doesn't have any required arguments
        $constructor = $ref->getConstructor();
        if (!empty($constructor) && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new MetadataException("Unable to detect property type for '{$ref->getName()}::$property'. Cannot create an instance of the class, as it has a constructor with required arguments");
        }
        $instance = $ref->newInstance();

        // setup detection data
        $scalarData = [
            EntityMetadata::FIELD_TYPE_BOOL => true,
            EntityMetadata::FIELD_TYPE_INT => 46,
            EntityMetadata::FIELD_TYPE_FLOAT => 23.653,
            EntityMetadata::FIELD_TYPE_STRING => "test"
        ];
        $complexData = [
            EntityMetadata::FIELD_TYPE_DATETIME => new \DateTime(),
            EntityMetadata::FIELD_TYPE_ARRAY => [1, 2, 3]
        ];

        // detect types. It is possible we will have more than one test come back positive.
        $results = [];
        foreach ($scalarData as $type => $value) {
            $this->checkFieldType($results, $instance, $setter, $getter, $type, $value);
        }

        // if we don't have any matches so far, try more complex data
        if (empty($results)) {
            foreach ($complexData as $type => $value) {
                $this->checkFieldType($results, $instance, $setter, $getter, $type, $value);
            }
        }

        $count = count($results);

        // if we detected one type, return that
        if ($count == 1) {
            return key($results);
        }
        // if we detected both integer and float, it's a float
        if ($count == 2 && !empty($results[EntityMetadata::FIELD_TYPE_INT]) && !empty($results[EntityMetadata::FIELD_TYPE_FLOAT])) {
            return EntityMetadata::FIELD_TYPE_FLOAT;
        }
        // if we can't tell, default to the string type
        return EntityMetadata::FIELD_TYPE_STRING;
    }

    protected function checkFieldType(&$results, $instance, $setter, $getter, $type, $value)
    {
        // set the value on this property, get it back again and compare the two values
        // catch exceptions. Means the input was invalid for this property
        try {
            $instance->{$setter}($value);
        } catch (\Exception $e) {
            return;
        }
        $result = $instance->{$getter}();

        // check if the original value is exactly the same what we got back
        if ($value === $result) {
            $results[$type] = true;
        }
    }

} 
