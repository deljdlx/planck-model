<?php

namespace Planck\Model;



use Phi\Model\Entity as PhiEntity;
use Planck\Application\Application;
use Planck\Helper\StringUtil;
use Planck\Model\Exception\DoesNotExist;
use Planck\Model\Exception\NotUnique;
use Planck\Model\Interfaces\Timestampable;
use Planck\Traits\IsApplicationObject;
use Planck\Traits\Listenable;

class Repository extends \Phi\Model\Repository
{


    use Listenable;
    use IsApplicationObject;

    /**
     * @var Application
     */
    protected $application;

    /**
     * @var Model
     */
    protected $model;

    private $transactionStarted = false;

    private $describeData;

    /**
     * @var EntityDescriptor;
     */
    protected $descriptor;



    public function __construct(Model $model)
    {
        $this->model = $model;

        $source = $this->model->getSource();

        parent::__construct($source);
    }

    public function now()
    {
        return date('Y-m-d H:i:s');
    }




    public function loadForeignEntities(
        $foreignEntityRepositoryName,
        $relationRepositoryName,
        $entityIdKey,
        $foreignEntityIdKey,
        $entity
    )
    {

        $foreignEntityRepository = $this->model->getRepository($foreignEntityRepositoryName);

        $relationRepository =  $this->model->getRepository($relationRepositoryName);

        $foreignEntityFields = $foreignEntityRepository->getEntityFieldsString('foreignEntity.');




        $query ="
            SELECT ".$foreignEntityFields." FROM ".$foreignEntityRepository->getTableName()." foreignEntity
            JOIN ".$relationRepository->getTableName()." relation
                ON foreignEntity.id = relation.".$foreignEntityIdKey."
            WHERE
                relation.".$entityIdKey." = :entity_id
        ";

        $rows = $this->queryAndFetch($query,  array(
            ':entity_id' => $entity->getId()
        ));

        return $foreignEntityRepository->getDataset($rows);

    }



    public function describe($toDescriptor = false)
    {

        if(!$this->describeData) {
            $this->describeData = $this->getTableDescriptor();
        }



        if($toDescriptor) {
            $descriptor = new EntityDescriptor($this, $this->describeData);
            return $descriptor;
        }
        else {
            return $this->describeData;
        }
    }

    /**
     * @return EntityDescriptor
     */
    public function getDescriptor()
    {
        if(!$this->descriptor) {
            $descriptor = str_replace('\Repository\\', '\Descriptor\\', get_class($this));

            if(class_exists($descriptor)) {
                $this->descriptor = new $descriptor($this);
            }
            else {
                $this->descriptor = new EntityDescriptor($this);
            }
        }
        return $this->descriptor;

    }


    /**
     * @param $where
     * @param Join $joins[]
     * @param string $extraQuery
     */
    public function getManyWithJoin($conditions, array $parameters, array $joins, $extraQuery = '')
    {


        $prefix = $this->getTableName().'.';
        $tableAlias = $this->getTableName().'__';

        $selectedFields = $this->getEntityFieldsString(
            $prefix,
            $tableAlias,
            false
        );


        $joinPart = '';

        foreach ($joins as $join) {
            $selectedFields = array_merge($selectedFields, $join->getSelectedFields());
            $joinPart .= $join->getJoin();
        }



        if(is_string($conditions)) {
          $where = $this->getTableName().".".$conditions;
        }
        else if(is_array($conditions)) {
            $where = array_shift($conditions);
            foreach ($conditions as $condition) {
                $where .= ' AND '. $this->getTableName().".".$condition;
            }



        }

        $query = "
            SELECT
            ".implode(','."\n", $selectedFields)."
            FROM ".$this->getTableName()."
                ".$joinPart."
            WHERE
                ".$where."
            ".$extraQuery."
        ";

        $rows = $this->queryAndFetch($query, $parameters);

        //=======================================================

        $returnValues = [];

        foreach ($rows as $values) {
            $result = [];

            $entity = $this->getEntityInstance();
            foreach ($values as $alias => $value) {

                if(preg_match('`^'.$tableAlias.'`', $alias)) {
                    $fieldName = preg_replace('`^'.$tableAlias.'`', '', $alias);
                    $entity->setValue($fieldName, $value);
                }
            }
            $result[get_class($entity)] = $entity;

            foreach ($joins as $join) {
                $joinedEntity = $join->getForeignEntity($values);
                $result[get_class($joinedEntity)] = $joinedEntity;
            }

            $returnValues[] = $result;
        }


        return $returnValues;





    }

    public function getPrimaryKeyFieldName()
    {
        return $this->getDescriptor()->getPrimaryKeyField()->getName();
    }



    public function getEntityFields()
    {
        $descriptors = $this->describe();


        if(empty($descriptors)) {
            throw new Exception('Descriptor is empty (Repository : '.get_class($this).') Do you you have initialized your database ?');
        }

        $fields = [];


        foreach ($descriptors as $descriptor) {
            $fields[] = $descriptor->getName();

        }
        return $fields;
    }

    public function getEntityFieldsString($prefix = null, $alias = null, $toString = true)
    {
        $fields = array();

        $descriptors = $this->describe();


        foreach ($descriptors as $descriptor) {
            $fieldName = $descriptor->getName();
            $field = $prefix.$fieldName;

            if($alias) {
                $field .= ' as '.$alias.$fieldName;
            }

            $fields[] = $field;
        }

        if($toString) {
            return implode(",\n", $fields);
        }
        else {
            return $fields;
        }

    }


    /**
     * @param $search
     * @param array|null $fields
     * @param null $offset
     * @param null $limit
     * @param null $totalRows
     * @return \Planck\Model\Entity[]
     */
    public function search($search, array &$fields = null, $offset = null, $limit = null)
    {

        if($fields === null) {
            $fields = [];

            $idField = $this->getDescriptor(true)->getIdFieldName();


            if($idField) {
                $fields [] = $idField;
            }

            $labelField = $this->getDescriptor(true)->getLabelFieldName();
            if($labelField) {
                $fields [] = $labelField;
            }
        }


        foreach ($fields as $field) {
            $conditions[] = $field.' LIKE :search';
        }


        $parameters = ['%'.$search.'%'];


        $query =
            "
                SELECT ".implode(',', $fields)." FROM ".$this->getTableName()."
                WHERE 
                    ".implode(' OR ', $conditions)."
            ";



        return $this->getSegmentByQuery($query, $parameters, $offset, $limit);




    }


    public function getSegmentByQuery($query, array $parameters = array(), $offset = null, $limit = null, $cast = null)
    {


        $segment = new Segment(
            $this,
            $offset,
            $limit
        );


        $limitedQuery = $query;
        $limitedQueryParameters = $parameters;


        if($limit !== null || $offset !== null) {
            $limitedQuery.=" LIMIT :limit ";
            if($limit !== null) {
                $limitedQueryParameters[':limit'] = (int) $limit;
            }
            else {
                $limitedQueryParameters[':limit'] = -1;
            }
        }

        if($offset !== null) {
            $limitedQuery .= " OFFSET :offset ";
            $limitedQueryParameters[':offset'] = (int) $offset;
        }

        $dataset = $this->queryAndGetDataset($limitedQuery, $limitedQueryParameters, $cast);




        $countQuery = "
            SELECT COUNT(*) as total FROM (".$query.") countTable 
        ";

        $entities = $dataset->getAll();

        $totalRows = (int) $this->queryAndFetchValue($countQuery, $parameters, 'total');

        $segment->setEntities($entities);
        $segment->setTotal($totalRows);


        return $segment;
    }


    public function getSegment(array $fields = null, $offset = null, $limit = null, $extraQuery = '')
    {

        if(!empty($fields)) {

            foreach ($fields as &$value) {
                $value = $this->escapeField($value);
            }
            $fieldList = implode(',', $fields);
        }
        else {
            $fieldList = '*';
        }

        $query = "
            SELECT ".$fieldList." FROM ".$this->getTableName()." ".$extraQuery."
        ";

        return $this->getSegmentByQuery($query, array(), $offset, $limit);
    }





    /**
     * @param Entity $entity
     * @param bool $dryRun
     * @return Entity
     * @throws Exception
     */
    public function insert($entity, $dryRun = false)
    {

        if($dryRun) {
            $this->startTransaction();
        }


        if(!$entity->doBeforeInsert()) {
            throw new Exception('Before insert hook retourned false');
        }


        $descriptors = $this->describe();

        if(!$entity->getId()) {
            $entity->setValue('id', null);
        }

        $fields = array();
        $placeholders = array();
        $values = array();

        if($entity instanceof Timestampable) {
            $entity->setValue('creation_date', $this->now());
        }

        foreach ($descriptors as $descriptor) {
            if(!$descriptor->isVirtual()) {
                $fieldName = $descriptor->getName();

                if($entity->getValue($fieldName) !== null) {
                    $value = $entity->getValue($fieldName);
                    $fields[] = $fieldName;
                    $placeholders[] = ':'.$fieldName;
                    $values[':'.$fieldName] = $value;
                }
            }
        }

        $query = "
            INSERT INTO ".$this->getTableName()." (
                ".implode(',', $fields)."
            ) VALUES (
                ".implode(',', $placeholders)."
            );
        ";

        $this->query($query, $values);
        $entity->setPrimaryKey($this->getLastInsertId());
        return $entity;
    }

    /**
     * @param Entity $entity
     * @param bool $dryRun
     * @return Entity
     * @throws Exception
     */
    public function update($entity, $dryRun = false)
    {

        if($dryRun) {
            $this->startTransaction();
        }

        if(!$entity->doBeforeUpdate()) {
            throw new Exception('Before Update hook retourned false');
        }

        $descriptors = $this->describe();

        $fields = array();

        $values = array();


        $needUpdate = false;
        foreach ($descriptors as $descriptor) {

            $fieldName = $descriptor->getName();
            if($entity->isFieldUpdated($fieldName) && !$descriptor->isVirtual()) {
                $needUpdate = true;
                $value = $entity->getValue($fieldName);
                $fields[] = $fieldName.' = :'.$fieldName;
                $values[':'.$fieldName] = $value;
            }
        }

        if($needUpdate) {


            $values[':id'] = $entity->getId();

            $query = "
                UPDATE ".$this->getTableName()." SET
                    ".implode(',', $fields)."
                WHERE
                    ".$entity->getPrimaryKeyFieldName()." = :id
            ";


            $this->query($query, $values);
        }
        return $entity;
    }


    /**
     * @param PhiEntity $object
     * @param bool $dryRun
     * @return Entity
     */
    public function store(PhiEntity $object, $dryRun = false)
    {

        if($dryRun) {
            $this->startTransaction();
        }

        if(!$object->doBeforeStore()) {
            throw new Exception('Before store hook retourned false');
        }


        if(!$object->getId()) {
            return $this->insert($object, $dryRun);
        }
        else {
            return $this->update($object, $dryRun);
        }
    }



    public function delete($entity, $dryRun = false)
    {
        if($dryRun) {
            $this->startTransaction();
        }
        $query = "
              DELETE FROM ".$this->getTableName()."
              WHERE
                ".$entity->getPrimaryKeyFieldName()." = :id
        ";

        $this->query($query, array(
            ':id' => $entity->getId()
        ));
        return $entity;
    }


    /**
     * @param $query
     * @param $parameters
     * @param null $cast
     * @return Dataset
     */
    public function queryAndGetDataset($query, $parameters= array(), $cast = null)
    {
        $rows = $this->queryAndFetch($query, $parameters);
        return $this->getDataset($rows, $cast);
    }


    /**
     * @param $field
     * @param null $value
     * @param string $orderBy
     * @param bool $caseInsensitive
     * @return Dataset
     */
    public function getBy($field, $value = null, $queryExtra = '', $caseInsensitive = true)
    {


        if(is_array($field)) {
            return $this->getByArray($field, $queryExtra, $caseInsensitive);
        }


        $collate = '';


        $query = '
        SELECT * FROM '.$this->getTableName().'
        WHERE '.$field.' = :value '.$collate.'
            '.$queryExtra.'
        ';


        $rows = $this->queryAndFetch($query, array(
            ':value' => $value
        ));


        return $this->getDataset($rows);
    }


    /**
     * @param array $fields
     * @param string $orderBy
     * @param bool $caseInsensitive
     * @return Dataset
     */
    public function getByArray(array $fields, $queryExtra = '', $caseInsensitive = true)
    {

        $collate = '';
        if($caseInsensitive) {
            /**
             * @todo
             */
            //$collate = 'COLLATE NOCASE';
        }

        $where = '';
        $parameters = array();
        foreach ($fields as $fieldName => $value) {
            if($this->getDescriptor()->fieldExists($fieldName)) {

                if($value !== null) {
                    if ($where == '') {
                        $where .= $fieldName . '=:' . $fieldName . ' ' . $collate . ' ';
                    }
                    else {
                        $where .= 'AND ' . $fieldName . '=:' . $fieldName . ' ' . $collate . ' ';
                    }
                    $parameters[':' . $fieldName] = $value;
                }

            }
        }

        $query = '
                SELECT * FROM '.$this->getTableName().'
                WHERE '.$where.'
                '.$queryExtra.'
            ';

        $rows = $this->queryAndFetch($query, $parameters);

        return $this->getDataset($rows);

    }


    /**
     * @param $field
     * @param $value
     * @param string $orderBy
     * @param bool $caseInsensitive
     * @return Entity
     * @throws \Exception
     */
    public function getOneBy($field, $value, $queryExtra = '', $caseInsensitive = true)
    {
        $records = $this->getBy($field, $value, $queryExtra, $caseInsensitive);
        if(count($records) === 1) {
            return $records[0];
        }
        else {
            if(count($records)) {
                throw new NotUnique('getBy() returned more than one record');
            }
            else {
                throw new DoesNotExist('getBy() returned no record');
            }

        }
    }


    /**
     * @param $qName
     * @return Dataset
     */
    public function getByQName($qName)
    {
        $records = $this->getBy('qname', $qName);
        return $records->first();
    }


    public function getEntityInstance($cast = null)
    {
        $instance = parent::getEntityInstance($cast);
        $instance = $this->model->decorateEntity($instance);
        return $instance;
    }


    public function getById($id)
    {

        $instance = $this->getEntityInstance();

        $query = '
          SELECT * FROM '.$this->getTableName().'
          WHERE '.$instance->getPrimaryKeyFieldName().' = :id
        ';
        $values = $this->queryAndFetchOne(
            $query,
            array(':id' => $id)
        );

        if(empty($values)) {
            throw new DoesNotExist('No object ('.$this->getTableName().') with id = '.$id.' (type : '.get_class($this).')');
        }


        $instance->setValues($values);
        return $instance;
    }


    public function commit()
    {
        if($this->transactionStarted) {
            $this->database->query('COMMIT;');
            $this->transactionStarted = false;
        }

        return $this;
    }

    public function startTransaction()
    {
        if(!$this->transactionStarted) {
            $this->database->query('BEGIN  TRANSACTION;');
            $this->transactionStarted = true;
        }

        return $this;
    }

    public function getFingerPrint()
    {
        return json_encode(array(
            'type' => 'repository',
            'instance' => get_class($this)
        ));
    }


    public function getName()
    {
        return str_replace('\\', '-', get_class($this));
    }



    public function getDataset($rows, $cast = null, $valueFilter = null)
    {
        $phiDataset = parent::getDataset($rows, $cast, $valueFilter);

        $dataset = new Dataset();
        $dataset->loadFromDataset($phiDataset);
        return $dataset;
    }


    /**
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }


}

