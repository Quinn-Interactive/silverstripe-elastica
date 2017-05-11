<?php

namespace Heyday\Elastica;

use Elastica\Document;
use Elastica\Type\Mapping;
use Psr\Log\LoggerInterface;

/**
 * Adds elastic search integration to a data object.
 */
class Searchable extends \DataExtension
{
    public static $published_field = 'SS_Published';

    /**
     * @config
     * @var array
     */
    public static $mappings = array(
        'Boolean' => 'boolean',
        'Decimal' => 'double',
        'Double' => 'double',
        'Enum' => 'text',
        'Float' => 'float',
        'HTMLText' => 'text',
        'HTMLVarchar' => 'text',
        'Int' => 'integer',
        'SS_Datetime' => 'date',
        'Text' => 'text',
        'Varchar' => 'text',
        'Year' => 'integer',
        'File' => 'attachment',
        'Date' => 'date'
    );

    /**
     * @config
     * @var array
     */
    private static $exclude_relations = array();

    /**
     * @var ElasticaService
     */
    private $service;

    /**
     * @param ElasticaService $service
     * @param LoggerInterface $logger
     */
    public function __construct(ElasticaService $service, LoggerInterface $logger = null)
    {
        $this->service = $service;
        $this->logger = $logger;
        parent::__construct();
    }

    /**
     * Returns an array of fields to be indexed. Additional configuration can be attached to these fields.
     *
     * Format: array('FieldName' => array('type' => 'text'));
     *
     * FieldName can be a field in the database or a method name
     *
     * @return array|\scalar
     */
    public function indexedFields()
    {
        return $this->owner->stat('indexed_fields');
    }

    /**
     * Return an array of dependant class names. These are classes that need to be reindexed when an instance of the
     * extended class is updated or when a relationship to it changes.
     * @return array|\scalar
     */
    public function dependentClasses()
    {
        return $this->owner->stat('dependent_classes');
    }

    /**
     * @return string
     */
    public function getElasticaType()
    {
        return get_class($this->owner);
    }

    /**
     * Gets an array of elastic field definitions.
     * This is also where we set the type of field ($spec['type']) and the analyzer for the field ($spec['analyzer']) if needed.
     * First we go through all the regular fields belonging to pages, then to the dataobjects related to those pages
     *
     * @return array
     */
    protected function getElasticaFields()
    {
        return array_merge(
            array(self::$published_field => array('type' => 'boolean')),
            $this->getSearchableFields(),
            $this->getReferenceSearchableFields()
        );
    }

    /**
     * Get the searchable fields for the owner data object
     * @return array
     */
    protected function getSearchableFields()
    {
        $result = array();

        $fields = array_merge($this->owner->inheritedDatabaseFields(), $this->owner->stat('fixed_fields'));

        foreach ($this->owner->indexedFields() as $fieldName => $params) {

            if (isset($params['type'])) {

                $result[$fieldName] = $params;

            } else {

                $fieldName = $params;

                if (array_key_exists($fieldName, $fields)) {

                    $dataType = $this->stripDataTypeParameters($fields[$fieldName]);

                    if (array_key_exists($dataType, self::$mappings)) {
                        $spec['type'] = self::$mappings[$dataType];

                        $result[$fieldName] = array('type' => self::$mappings[$dataType]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get the searchable fields for the relationships of the owner data object
     * @return array
     */
    protected function getReferenceSearchableFields()
    {
        $result = array();

        $relations = array_merge($this->owner->has_many(), $this->owner->has_one(), $this->owner->many_many());

        foreach ($this->owner->indexedFields() as $fieldName => $params) {

            if (is_int($fieldName)) {
                $fieldName = $params;
            }

            if (array_key_exists($fieldName, $relations)) {

                $className = $relations[$fieldName];
                $related = singleton($className);
                $fields = $related->inheritedDatabaseFields();

                if ($related->hasExtension('Heyday\\Elastica\\Searchable')) {

                    foreach ($related->indexedFields() as $relatedFieldName => $relatedParams) {

                        if (is_int($relatedFieldName)) {
                            $relatedFieldName = $relatedParams;
                        }

                        $concatenatedFieldName = "{$fieldName}_{$relatedFieldName}";

                        if (isset($params[$relatedFieldName]['type'])) {

                            $result[$concatenatedFieldName] = $params[$relatedFieldName];

                        } else if (isset($relatedParams[$relatedFieldName]['type'])) {

                            $result[$concatenatedFieldName] = $relatedParams;

                        } else if (isset($relatedParams['type'])) {

                            $result[$concatenatedFieldName] = $relatedParams;

                        } else if (array_key_exists($relatedFieldName, $fields)) {

                            $dataType = $this->stripDataTypeParameters($fields[$relatedFieldName]);

                            if (array_key_exists($dataType, self::$mappings)) {
                                $spec['type'] = self::$mappings[$dataType];

                                $result[$concatenatedFieldName] = array('type' => self::$mappings[$dataType]);
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param $dataType
     * @return string
     */
    protected function stripDataTypeParameters($dataType)
    {
        if (($pos = strpos($dataType, '('))) {
            $dataType = substr($dataType, 0, $pos);
        }

        return $dataType;
    }

    /**
     * @param $dateString
     * @return bool|string
     */
    protected function formatDate($dateString)
    {
        return date('Y-m-d\TH:i:s', strtotime($dateString));
    }

    /**
     * @return bool|\Elastica\Type\Mapping
     */
    public function getElasticaMapping()
    {
        $fields = $this->getElasticaFields();

        if (count($fields)) {
            $mapping = new Mapping();
            $mapping->setProperties($this->getElasticaFields());

            return $mapping;
        }

        return false;
    }

    /**
     * @param Document $document
     */
    protected function setPublishedStatus($document)
    {
        $isLive = true;
        if ($this->owner->hasExtension('Versioned')) {
            if ($this->owner instanceof \SiteTree) {
                $isLive = $this->owner->isPublished();
            }
        }

        $document->set(self::$published_field, (bool) $isLive);
    }

    /**
     * Assigns value to the fields indexed from getElasticaFields()
     *
     * @return Document
     */
    public function getElasticaDocument()
    {
        $document = new Document($this->owner->ID);

        $this->setPublishedStatus($document);

        $possibleFields = array_merge($this->owner->inheritedDatabaseFields(), $this->owner->stat('fixed_fields'));

        foreach ($this->getElasticaFields() as $field => $config) {

            if (array_key_exists($field, $possibleFields) ||
                $this->owner->hasMethod('get' . $field)
            ) {

                $this->setValue($config, $field, $document, $this->owner->$field);

            } else {

                $possibleRelations = array_merge($this->owner->has_many(), $this->owner->has_one(), $this->owner->many_many());

                if (strstr($field, '_')) {
                    list($relation, $fieldName) = explode('_', $field);
                }
                else {
                    $relation = false;
                }

                if ($relation && array_key_exists($relation, $possibleRelations)) {

                    $related = $this->owner->$relation();

                    if ($related instanceof \DataObject && $related->exists()) {

                        $possibleFields = $related->inheritedDatabaseFields();

                        if (array_key_exists($fieldName, $possibleFields)) {

                            switch ($config['type']) {
                                case 'date':
                                    if ($related->$fieldName) {
                                        $document->set($field, $this->formatDate($related->$fieldName));
                                    }
                                    break;
                                default:
                                    $document->set($field, $related->$fieldName);
                                    break;
                            }

                        } else if ($config['type'] == 'attachment') {

                            $file = $related->$fieldName();

                            if ($file instanceof \File && $file->exists()) {
                                $document->addFile($field, $file->getFullPath());
                            }
                        }

                    } else if ($related instanceof \DataList && $related->count()) {

                        $relatedData = [];

                        foreach ($related as $relatedItem) {
                            $data = null;

                            $possibleFields = $relatedItem->inheritedDatabaseFields();

                            if (array_key_exists($fieldName, $possibleFields) ||
                                $related->hasMethod('get' . $fieldName)
                            ) {
                                switch ($config['type']) {
                                    case 'date':
                                        if ($relatedItem->$fieldName) {
                                            $data = $this->formatDate($relatedItem->$fieldName);
                                        }
                                        break;
                                    default:
                                        $data = $relatedItem->$fieldName;
                                        break;
                                }

                            } else if ($config['type'] == 'attachment') {
                                if ($relatedItem->hasMethod('get' . $fieldName)) {
                                    $methodName = 'get' . $fieldName;
                                    $data = $relatedItem->$methodName();
                                } else {
                                    $file = $relatedItem->$fieldName();

                                    if ($file instanceof \File && $file->exists()) {
                                        $data = base64_encode(file_get_contents($file->getFullPath()));
                                    }

                                }
                            }

                            if (!is_null($data)) {
                                $relatedData[] = $data;
                            }
                        }

                        if (count($relatedData)) {
                            $document->set($field, $relatedData);
                        }
                    }
                }
            }
        }

        return $document;
    }

    /**
     * Updates the record in the search index, or removes it as necessary.
     */
    public function onAfterWrite()
    {
        $reading_mode = \Versioned::get_reading_mode();
        \Versioned::set_reading_mode('Stage.Live');

        $versionToIndex = \DataObject::get($this->owner->ClassName)->byID($this->owner->ID);
        if (is_null($versionToIndex)) {
            $versionToIndex = $this->owner;
        }

        if (($versionToIndex instanceof \SiteTree && $versionToIndex->ShowInSearch) ||
            (!$versionToIndex instanceof \SiteTree && ($versionToIndex->hasMethod('getShowInSearch') && $versionToIndex->ShowInSearch)) ||
            (!$versionToIndex instanceof \SiteTree && !$versionToIndex->hasMethod('getShowInSearch'))
        ) {
            $this->service->index($versionToIndex);
        } else {
            $this->service->remove($versionToIndex);
        }

        $this->updateDependentClasses();

        \Versioned::set_reading_mode($reading_mode);
    }

    /**
     * Removes the record from the search index.
     */
    public function onAfterDelete()
    {
        $this->service->remove($this->owner);
        $this->updateDependentClasses();
    }

    /**
     * Update dependent classes after the extended object has been removed from a ManyManyList
     */
    public function onAfterManyManyRelationRemove()
    {
        $this->updateDependentClasses();
    }

    /**
     * Update dependent classes after the extended object has been added to a ManyManyList
     */
    public function onAfterManyManyRelationAdd()
    {
        $this->updateDependentClasses();
    }

    /**
     * Updates the records of all instances of dependent classes.
     */
    protected function updateDependentClasses()
    {
        $classes = $this->dependentClasses();
        if($classes) {
            foreach ($classes as $class) {
                $list = \DataList::create($class);

                foreach ($list as $object) {

                    if ($object instanceof \DataObject &&
                        $object->hasExtension('Heyday\\Elastica\\Searchable')
                    ) {
                        if (($object instanceof \SiteTree && $object->ShowInSearch) ||
                            (!$object instanceof \SiteTree)
                        ) {
                            $this->service->index($object);
                        } else {
                            $this->service->remove($object);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $config
     * @param $fieldName
     * @param \Elastica\Document $document
     * @param $fieldValue
     */
    public function setValue($config, $fieldName, $document, $fieldValue)
    {
        switch ($config['type']) {
            case 'date':
                if ($fieldValue) {
                    $document->set($fieldName, $this->formatDate($fieldValue));
                }
                break;
            default:
                $document->set($fieldName, $fieldValue);
                break;
        }
    }

}
