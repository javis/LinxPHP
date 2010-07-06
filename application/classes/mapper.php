<?php
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
class Mapper {

  /* options */
    static protected $_convert_to_lowercase = true;

    /* cache */
    static protected $_load_cache = array();

    static protected function get_attributes($comments_string) {
        $attributes = preg_replace('%/\*\*|^\s*?\*/\s*|^\s*?\*(?:\s?$| ){0,1}%sm', '', $comments_string);
        return Spyc::YAMLLoadString($attributes);
    }

    static protected function get_class_schema($class_name) {
        $schema = array();

        $schema['type'] = $class_name;


        $function = new ReflectionClass($class_name);

        // get type name from class name
        if (method_exists('ReflectionClass', 'getShortName'))
            $schema['type'] = $function->getShortName();


        $schema['attributes'] = self::get_attributes($function->getDocComment());



        if (self::$_convert_to_lowercase) {
            $schema['type'] = strtolower($schema['type']);
        }

        $properties = $function->getDefaultProperties();

        $schema['properties'] = array();
        foreach ($properties as $property=>$value) {
            $prop = array();
            $prop['value'] = $value;
            //$prop['value'] = $object->$property;

            $method=new ReflectionProperty($class_name,$property);

            // obtenemos los comentarios de la propiedad
            $prop['attributes'] = self::get_attributes($method->getDocComment());


            $schema['properties'][$property] = $prop;
        }


        return $schema;
    }

    static protected function get_object_schema($object) {
        $schema = self::get_class_schema(get_class($object));

        foreach ($schema['properties'] as $property=>&$prop) {


            $prop['value'] = $object->$property;


        }
        return $schema;
    }
    /**
     ANSI data type	Oracle        MySql           PostGreSQL            Most Portable
     integer         NUMBER(38)    integer(11)     integer               integer
     smallint        NUMBER(38)    smallint(6)     smallint              smallint
     tinyint         *	tinyint(4)  *               numeric(4,0)
     numeric(p,s)    NUMBER(p,s)   decimal(p,s)    numeric(p,s)          numeric(p,s)
     varchar(n)      VARCHAR2(n)   varchar(n)      character varying(n)	varchar(n)
     char(n)         CHAR(n)       varchar(n)      character(n)          char(n)
     datetime        DATE          datetime        timestamp no timezone have to autodetect
     float           FLOAT(126)    float           double precision      float
     real            FLOAT(63)     double          real                  real
     */


    static protected function get_sql_table_schema($object_or_classname) {
        if (is_object($object_or_classname)) {
            $obj_schema = self::get_object_schema($object_or_classname);
        }
        else {
            $obj_schema = self::get_class_schema($object_or_classname);
        }


        $schema = array();

        $schema['table_name'] = $obj_schema['type'];

        if (isset($schema['attributes']['table']))
            $schema['table_name'] = $schema['attributes']['table'];

        $schema['fields'] = array();

        $schema['primary_key'] = array();


        foreach ($obj_schema['properties'] as $property_name => $property_attributes) {


            $field = array();

            //$field['name'] = $property_name;

            $field['value'] = $property_attributes['value'];

            $length = (int) (isset($property_attributes['attributes']['length']))?$property_attributes['attributes']['length']:'';

            if (isset($property_attributes['attributes']['primary_key'])
                and $property_attributes['attributes']['primary_key']==true) {
                $schema['primary_key'][] = $property_name;
                $field['primary_key'] = true;
            }
            $type = 'VARCHAR(255)';

            $pdo_bind_params = array('data_type'=>PDO::PARAM_STR,'length'=>(!empty($length))?$length:255);

            if (isset($property_attributes['attributes']['type'])) {
                switch ($property_attributes['attributes']['type']) {
                    case 'string':


                        $type = 'VARCHAR';
                        if (!empty($length))
                            $type .= "($length)";
                        else
                            $type .= "(255)";

                        $pdo_bind_params = array('data_type'=>PDO::PARAM_STR,'length'=>(!empty($length))?$length:255);

                        break;
                    case 'integer':

                        $pdo_bind_params = array('data_type'=>PDO::PARAM_INT);
                        $type = 'INTEGER';
                        break;
          /*
          case 'float':
            $type = 'FLOAT';
            break;          
          case 'date':
            $type = 'DATE';
            break;
          case 'datetime':
            $type = 'DATETIME';
            break;
           */
                    default:
                    # unrecognized type! let's see if it's a class name

                        if (class_exists($property_attributes['attributes']['type'])) {
                          
                            $type_classname = $property_attributes['attributes']['type'];
                            $type_schema = self::get_class_schema($type_classname);

                            # we're going to define fore keys for this relationship
                            if (!isset($property_attributes['attributes']['relationship'])) {
                            # relationship must be deffined in comments!
                                throw new Exception("relationship attribute must be deffined for field $property_name in mode {$obj_schema['type']} ");
                            }
                            //die(    $property_attributes['attributes']['relationship']);
                            switch ($property_attributes['attributes']['relationship']) {
                                case 'childs':
                                // parents doesnt need a sql property for their childs
                                    break;
                                case 'parent':
                                    // childs must define the relationship to a parent in SQL
                                //case 'childs':
                                    // we will add fore keys to this table

                                    if (is_object($property_attributes['value']))
                                    // if the relationship is not empty we'll get the values for the fields
                                    $type_sql_schema = self::get_sql_table_schema($property_attributes['value']);
                                    else
                                    // if the relationship is empty we'll get the schema from the class name
                                    $type_sql_schema = self::get_sql_table_schema($type_classname);

                                    foreach ($type_sql_schema['primary_key'] as $type_primary_key) {
                                        // we're going to copy the declaration of the primary keys
                                        // to build the fore keys
                                        $field = array();
                                        $field['value'] = $type_sql_schema['fields'][$type_primary_key]['value'];
                                        $field['pdo_bind_params'] = $type_sql_schema['fields'][$type_primary_key]['pdo_bind_params'];
                                        $field['data_type'] = $type_sql_schema['fields'][$type_primary_key]['data_type'];

                                        $schema['fields'][$type_sql_schema['table_name'].'_'.$type_primary_key] = $field;

                                    }

                                    break;


                            }
                            // continue with next property
                            continue 2; // 2 because the sentence SWITCH is considered a loop structure :O
                        }

                        # run an event to proccess the unrecognized type
                        $type = Event::run('mapper.data_type_declaration',$property_attributes['attributes']['type'],$pdo_bind_params);
                        break;
                }
            }
            else {
            # default type if it's not set
                $type = 'VARCHAR(255)';
            }

            if ($field['value'] == null)
                $pdo_bind_params = array('data_type'=>PDO::PARAM_NULL);

            $field['pdo_bind_params'] = $pdo_bind_params;
            $field['data_type'] = $type;

            $schema['fields'][$property_name] = $field;

        }

        if (count($schema['primary_key'])==0) {
            throw new Exception('Objects must have a PRIMARY ID property.');
        }

        return $schema;
    }

    static public function save($object) {
        if (self::update($object)==0) {
            try {
                self::insert($object);
            }
            catch(PDOException $e ){
            // if there where an update == 0 because the object wasn't modified
            // then the insert will give an duplicated id error
            // we chatch here
            }
        }
    }



    static public function insert($object) {
        $sql_schema = self::get_sql_table_schema($object);

        if (!db::table_exists($sql_schema['table_name'])) {
            self::create_table($object);
        }

        $fields_names = array();
        $fields_values = array();
        $bind_params = array();

        foreach ($sql_schema['fields'] as $field=>$attributes) {

            $fields_names[]=$field;

            $value = $attributes['value'];

            if (!is_scalar($value) and !$value==null)
                throw new Exception('Field Values must be scalars!');


            # proccess value with hooks (just in case it needs to be processed)
            Event::run('mapper.process_field_value',$field,$attributes,$value);
            $fields_values[':'.$field] = $value;
            $bind_params[':'.$field] = $attributes['pdo_bind_params'];
        }

        $fields = implode(',', $fields_names);

        $params = implode(',',array_keys($fields_values));


        $sql = "INSERT INTO {$sql_schema['table_name']} ($fields) VALUES ($params)";

        return db::execute($sql, $fields_values,$bind_params);

    }
    /**
     *
     *
     * @return <type>
     */
    static public function update($object) {
        $sql_schema = self::get_sql_table_schema($object);

        if (!db::table_exists($sql_schema['table_name'])) {
            self::create_table($object);
            self::insert($object);
            return;
        }

        $field_updates = '';
        $fields_values = array();
        $bind_params = array();
        foreach ($sql_schema['fields'] as $field=>$attributes) {
            if (!empty($field_updates))
                $field_updates .= ", ";

            $field_updates .= "$field = :$field";

            $value = $attributes['value'];


            if (!is_scalar($value) and !$value==null)
                throw new Exception('Field Values must be scalars!');

            # proccess value with hooks (just in case it needs to be processed)
            Event::run('mapper.process_field_value',$field,$attributes,$value);
            $fields_values[':'.$field] = $value;
            $bind_params[':'.$field] = $attributes['pdo_bind_params'];
        }

        $where_id = '';

        if (count($sql_schema['primary_key'])==0) {
            throw new Exception('Objects must have a PRIMARY ID property.');
        }

        foreach ($sql_schema['primary_key'] as $key) {
            if (!empty($where_id))
                $where_id .= " AND ";

            $where_id .= "$key = :$key";
        }


        $sql = "UPDATE {$sql_schema['table_name']}
    SET $field_updates
    WHERE $where_id";


        return db::execute($sql, $fields_values,$bind_params);

    }

    static public function delete($object) {
        $sql_schema = self::get_sql_table_schema($object);

        if (!db::table_exists($sql_schema['table_name'])) {
            self::create_table($object);
            self::insert($object);
            return;
        }

        $where_id = '';



        $fields_values = array();
        $bind_params = array();

        foreach ($sql_schema['primary_key'] as $key) {
            if (!empty($where_id))
                $where_id .= " AND ";

            $where_id .= "$key = :$key";

            $attributes = $sql_schema['fields'][$key];

            $value = $attributes['value'];

            # proccess value with hooks (just in case it needs to be processed)
            Event::run('mapper.process_field_value',$key,$attributes,$value);
            $fields_values[':'.$key] = $value;
            $bind_params[':'.$key] = $attributes['pdo_bind_params'];
        }


        $sql = "DELETE FROM {$sql_schema['table_name']}
    WHERE $where_id";


        return db::execute($sql, $fields_values,$bind_params);

    }

    static protected function create_table($object) {
        $sql_schema = self::get_sql_table_schema($object);

        # field declarations
        $fields_declaration = "";
        foreach ($sql_schema['fields'] as $field=>$attributes) {

            $declaration = "$field {$attributes['data_type']}";

            if (isset($attributes['primary_key']) and $attributes['primary_key']==true
                and count($sql_schema['primary_key'])==1) {

                $declaration .= ' PRIMARY KEY';
            }

            if (!empty($fields_declaration))
                $fields_declaration .= ", ";

            $fields_declaration .= "\n".$declaration;

        }

        # composite primary key
        if (isset($sql_schema['primary_key']) and count($sql_schema['primary_key'])>1) {
            $fields_declaration .= ", PRIMARY KEY (".implode(',', $sql_schema['primary_key']).")";
        }

        $sql = "CREATE TABLE {$sql_schema['table_name']}
    ({$fields_declaration}
    )";

        db::execute($sql);

    }


    static protected function add_to_cache($object){
      $classname = get_class($object);

      $sql_schema = self::get_sql_table_schema($object);

      $id = array();

      foreach ($sql_schema['primary_key'] as $key) {
            
            $value = $sql_schema['fields'][$key]['value'];

            $id[$key] = (string) $value;
      }

      $key = md5($classname . json_encode($id));

      self::$_load_cache[$key] = $object;

    }
    static protected function is_in_cache($classname,$id){
      if (!is_array($id)){
        $sql_schema = self::get_sql_table_schema($classname);
        $id=array($sql_schema['primary_key'][0]=> (string) $id);
        
      }

      $key = md5($classname . json_encode($id));
      

      return isset(self::$_load_cache[$key]);
    }
    static protected function get_from_cache($classname,$id){
      if (!is_array($id)){
        $sql_schema = self::get_sql_table_schema($classname);
        $id=array($sql_schema['primary_key'][0]=> (string) $id);
      }

      $key = md5($classname . json_encode($id));

      return self::$_load_cache[$key];
    }


    static public function get_by_id($classname,$id){
      $sql_schema = self::get_sql_table_schema($classname);

      if (!db::table_exists($sql_schema['table_name'])) {
            return;
      }

      if (self::is_in_cache($classname,$id))
      return self::get_from_cache($classname,$id);


      $where_id = '';

      if (count($sql_schema['primary_key']) != count($id)){
        throw new Exception('Incorrect number of values for primary key');
      }

        $fields_values = array();
        $bind_params = array();

        foreach ($sql_schema['primary_key'] as $key) {
            if (!empty($where_id))
                $where_id .= " AND ";

            $where_id .= "$key = :$key";

            $attributes = $sql_schema['fields'][$key];

            if (!is_array($id))
            $value = $id;
            else{
              if (!isset($id[$key]))
              throw new Exception("Missing key '$key' in primary keys argument");

              $value = $id[$key];
            }

            # proccess value with hooks (just in case it needs to be processed)
            Event::run('mapper.process_field_value',$key,$attributes,$value);

            $fields_values[':'.$key] = $value;
            $bind_params[':'.$key] = $attributes['pdo_bind_params'];
        }




        $sql = "SELECT * FROM {$sql_schema['table_name']}";
        
            $sql .= " WHERE $where_id";


        $results = db::query($sql, $fields_values,$bind_params,$classname);
        
        if (isset($results[0])){
          self::add_to_cache($results[0]);
          self::fill_relationship($results[0]);
          return $results[0];
        }
        else
        return;
    }



    static public function get($classname,$conditions=null) {
        $sql_schema = self::get_sql_table_schema($classname);

        if (!db::table_exists($sql_schema['table_name'])) {
            return;
        }

    /*
    foreach ($sql_schema['fields'] as $field=>$attributes){
    
    }
    */

        $sql = "SELECT * FROM {$sql_schema['table_name']}";
        if (!empty ($conditions))
            $sql .= " WHERE $conditions";


        $return = db::query($sql, $fields_values = array(),$bind_params = array(),$classname);

        foreach($return as $object){
          self::add_to_cache($object);
          self::fill_relationship($object);
        }

        return $return;

    }

    static protected function fill_relationship($object){

        $obj_schema = self::get_object_schema($object);
        $sql_schema = self::get_sql_table_schema($object);


        foreach ($obj_schema['properties'] as $property_name => $property_attributes) {

          if (is_null($object->$property_name)){

            if (isset($property_attributes['attributes']['type']) AND class_exists($property_attributes['attributes']['type'])) {

                  $type_classname = $property_attributes['attributes']['type'];
                  $type_schema = self::get_class_schema($type_classname);

                  # we're going to define fore keys for this relationship
                  if (!isset($property_attributes['attributes']['relationship'])) {
                  # relationship must be deffined in comments!
                      throw new Exception("relationship attribute must be deffined for field $property_name in mode {$obj_schema['type']} ");
                  }

                  switch ($property_attributes['attributes']['relationship']) {
                      case 'childs':

                        $conditions = '';

                        foreach ($sql_schema['primary_key'] as $primary_key) {

                             if (!empty($conditions))
                              $conditions .= " AND ";

                              $value = $sql_schema['fields'][$primary_key]['value'];

                              $field = $sql_schema['table_name'].'_'.$primary_key;

                              $conditions .= " $field = '$value' ";

                        }
                        /*TODO: el parametro conditions del get no me gusta mucho porque los valores no se pueden pasar como parametros */
                          // parents doesnt need a sql property for their childs
                          $childs = self::get($type_classname, $conditions);


                          // asignamos los childs at last

                          $object->$property_name = $childs;

                          break;
                      case 'parent':             
                        //
                        
                          $type_sql_schema = self::get_sql_table_schema($type_classname);

                          $fore_keys = array();

                          foreach ($type_sql_schema['primary_key'] as $type_primary_key) {


                              $sql_field = $type_sql_schema['table_name'].'_'.$type_primary_key;

                              $fore_keys[$type_primary_key] = $object->$sql_field;

                              if (!in_array($sql_field,array_keys($obj_schema['properties'])))
                              unset($object->$sql_field); # remove property because it was not defined in the original class

                          }

                          $object->$property_name = self::get_by_id($type_classname, $fore_keys);
                        

                          


                          break;
                      }
            }
          }

        }
    }

}
?>
