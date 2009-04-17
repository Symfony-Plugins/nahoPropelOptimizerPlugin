<?php

require_once sfConfig::get('sf_symfony_lib_dir').'/addon/propel/builder/SfPeerBuilder.php';

require_once dirname(__FILE__) . '/NahoBuilder.php';

class SfOptimizedPeerBuilder extends NahoPeerBuilder
{
  
  public function build()
  {
    // Get original built code
    $peerCode = parent::build();
    
    // Remove useless includes
    if (!DataModelBuilder::getBuildProperty('builderAddIncludes')) {
      //remove all inline includes:
      //peer class include inline the mapbuilder classes
      $peerCode = preg_replace("/(include|require)_once\s*.*\.php.*\s*/", "", $peerCode);
    }
    
    // Remove cache in retrieveByPk
    if (!DataModelBuilder::getBuildProperty('builderNoCacheRetrieveByPk')) {
      //cache result for retrieveByPk
      $this->addCachedRetrieveByPk($peerCode);
    }
    
    // Change implicit joins (all inner) to explicit INNER or LEFT, depending on the fact the key can be null or not
    if (!DataModelBuilder::getBuildProperty('builderImplicitJoins')) {
      foreach ($this->getTable()->getColumns() as $column) {
        if ($column->isForeignKey()) {
          $colName = PeerBuilder::getColumnName($column, $this->getTable()->getPhpName());
          $from = '/->addJoin\(' . preg_quote($colName, '/') . '\s*,\s*([^,]*?)\)/';
          if ($column->isNotNull()) {
            $to = '->addJoin(' . $colName . ', $1, Criteria::INNER_JOIN)';
          } else {
            $to = '->addJoin(' . $colName . ', $1, Criteria::LEFT_JOIN)';
          }
          $peerCode = preg_replace($from, $to, $peerCode);
        }
      }
    }
    
    // remove calls to Propel::import(), which prevent to extend plugin's model classes
    if (!DataModelBuilder::getBuildProperty('builderAddPropelImports')) {
      $from = '/Propel::import\((.*?)\)/';
      $to = 'substr($1, ($pos=strrpos($1,\'.\'))?$pos+1:0)';
      $peerCode = preg_replace($from, $to, $peerCode);
    }
    
    if (DataModelBuilder::getBuildProperty('builderJoinWithAlias')) {
      // Add support for aliases in TablePeer::addSelectColumns
      if (preg_match('/public static function addSelectColumns\((.*?)\)\n(\s*)\{(.*?)\n\2\}/ism', $peerCode, $m)) {
        $from = '/\$criteria->addSelectColumn\(([a-z_0-9]+)::([a-z_0-9]+)\);/i';
        $to = '$criteria->addSelectColumn(is_null($alias) ? $1::$2 : $1::alias($alias, $1::$2));';
        $body = preg_replace($from, $to, $m[3]);
        $peerCode = str_replace('public static function addSelectColumns(Criteria $criteria)', 'public static function addSelectColumns(Criteria $criteria, $alias = null)', $peerCode);
        $peerCode = str_replace($m[3], $body, $peerCode);
      } else {
        throw new Exception('Bug ! Cannot find addSelectColumns, please put builder.joinWithAlias to false, and open a new ticket for this bug');
      }
      
      // Add aliases in the do...Join... functions
      preg_match_all('/function do(Select|Count)Join([a-z_0-9]*)\(.*?\)\n(\s*)\{(.*?)\n\3\}/ism', $peerCode, $m, PREG_SET_ORDER);
      foreach ($m as $join) {
        $name = 'do' . $join[1] . 'Join' . $join[2];
        $body = $join[4];
        $this->addAliasesInJoinFunction($name, $body, $peerCode);
      }
    }
    
    if (!DataModelBuilder::getBuildProperty('builderRemoveGenericJoin')) {
      $peer = $this->getTable()->getPhpName() . 'Peer';
      
      
      
      /****************** getRelationInfo **********************/
      
      
      
      $comment = <<<STR
  /**
   * Exract information from the relation array
   *
   * @param array $fields TablePeer::FIELD_NAME => "RelatedTable"
   * @return array :
   *   class   => related classname
   *   column  => left column (ColumnMap object)
   *   related => right column (ColumnMap object)
   */
STR;

      $function = <<<STR
  protected static function getRelationInfo(array \$fields)
  {
    \$tmap = $peer::getTableMap();
    foreach (\$fields as \$field => \$related_classname) {
      \$column = \$tmap->getColumn(\$field);
      // check foreign key
      if (!\$column->isForeignKey()) {
        throw new Exception(\$field . ' is not a foreign key');
      }
      // check class
      if (!class_exists(\$related_classname)) {
        throw new Exception('Class "' . \$related_classname . '" does not exist');
      }
      // check table
      \$related_table = constant(\$related_classname.'Peer::TABLE_NAME');
      if (strtolower(\$related_table) != strtolower(\$column->getRelatedTableName())) {
        throw new Exception(\$related_classname.'Peer::TABLE_NAME must be "' . \$column->getRelatedTableName() . '" (current value is "' . \$related_table . '"');
      }
      \$related_map = call_user_func(array(\$related_classname.'Peer', 'getTableMap'));
      \$related_column = \$related_map->getColumn(\$column->getRelatedName());
      // complete info
      \$fields[\$field] = array(
        'class'   => \$related_classname,
        'column'  => \$column,
        'related' => \$related_column,
      );
    }
    
    return \$fields;
  }
STR;

      $fixWhereAlias = <<<STR
// Use alias in the WHERE clause
      \$map = call_user_func(array(\$info['class'].'Peer', 'getTableMap'));
      foreach (\$map->getColumns() as \$column) {
        \$key = \$column->getFullyQualifiedName();
        if (\$criteria->containsKey(\$key)) {
          \$comparison = \$criteria->getComparison(\$key);
          \$value = \$criteria->getValue(\$key);
          \$criteria->remove(\$key);
          \$field = call_user_func(array(\$info['class'].'Peer', 'alias'), \$alias, \$key);
          \$criteria->add(\$field, \$value, \$comparison);
        }
      }
STR;
      
      $this->addFunction($peerCode, $comment, $function);
      
      
      
      /****************** doSelectJoin **********************/
      
      
      
      $comment = <<<STR
  /**
   * Selects a collection of Analyse objects pre-filled with all related objects.
   *
   * @param      array \$fields TablePeer::FIELD_NAME => "RelatedClassName"
   * @return     array Array of Analyse objects.
   * @throws     PropelException Any exceptions caught during processing will be
   *     rethrown wrapped into a PropelException.
   */
STR;

      if (DataModelBuilder::getBuildProperty('builderJoinWithAlias')) {
        $addColumns1 = <<<STR
\$c->addAlias('t', $peer::TABLE_NAME);
    $peer::addSelectColumns(\$c, 't');
STR;
        $addColumns2 = <<<STR
\$c->addAlias('t'.\$x, constant(\$info['class'].'Peer::TABLE_NAME'));
    call_user_func(array(\$info['class'].'Peer','addSelectColumns'), \$c, 't'.\$x);
STR;
        $setJoin = <<<STR
\$left = $peer::alias('t', \$field);
      \$right = call_user_func(array(\$info['class'].'Peer', 'alias'), 't'.\$info['x'], \$info['related']->getFullyQualifiedName());
      $fixWhereAliasForSelect
STR;
      } else {
        $addColumns1 = $peer . '::addSelectColumns($c);';
        $addColumns2 = 'call_user_func(array($info[\'class\'].\'Peer\',\'addSelectColumns\'), $c, constant($info[\'class\'].\'Peer::TABLE_NAME\'));';
        $setJoin = <<<STR
\$left = $peer::TABLE_NAME;
      \$right = \$info['related']->getFullyQualifiedName();
STR;
      }
      
      if (!DataModelBuilder::getBuildProperty('builderImplicitJoins')) {
        $addJoin = '$c->addJoin($left, $right, $info[\'column\']->isNotNull() ? Criteria::INNER_JOIN : Criteria::LEFT_JOIN);';
      } else {
        $addJoin = '$c->addJoin($left, $right);';
      }

      if (DataModelBuilder::getBuildProperty('builderJoinWithAlias')) {
        $fixWhereAliasForSelect = str_replace('$criteria', '$c', $fixWhereAlias);
				$fixWhereAliasForSelect = str_replace('$alias', '\'t\'.$info[\'x\']', $fixWhereAliasForSelect);
				$addJoin .= "\n      \n$fixWhereAliasForSelect";
			}

      $function = <<<STR
  public static function doSelectJoin(array \$fields, Criteria \$c, \$con = null)
  {

    foreach (sfMixer::getCallables('Base$peer:doSelectJoin:doSelectJoin') as \$callable)
    {
      call_user_func(\$callable, 'Base$peer', \$c, \$con);
    }


    \$c = clone \$c;

    // Set the correct dbName if it has not been overridden
    if (\$c->getDbName() == Propel::getDefaultDB()) {
      \$c->setDbName(self::DATABASE_NAME);
    }

    // Complete and check the information got from tableMap
    \$fields = self::getRelationInfo(\$fields);
    
    \$x = 1;
    
    $addColumns1
    \$startcol[\$x] = ($peer::NUM_COLUMNS - $peer::NUM_LAZY_LOAD_COLUMNS) + 1;
    
    // addSelectColumns
    foreach (\$fields as \$field => \$info) {
      \$fields[\$field]['x'] = ++\$x;
      $addColumns2
      \$startcol[\$x] = \$startcol[\$x-1] + constant(\$info['class'].'Peer::NUM_COLUMNS');
    }
    
    // addJoins
    foreach (\$fields as \$field => \$info) {
      $setJoin
      $addJoin
    }

    // Select
    \$rs = BasePeerRedi::doSelect(\$c, \$con);
    \$results = array();

    // Fill foreign keys of the retrieved results
    while(\$rs->next()) {
      \$obj = array();
      \$x = 1;
      
      \$omClass = $peer::getOMClass();
      \$cls = substr(\$omClass, (\$pos=strrpos(\$omClass,'.'))?\$pos+1:0);
      \$obj[\$x] = new \$cls();
      \$obj[\$x]->hydrate(\$rs);
      
      foreach (\$fields as \$field => \$info) {
        \$x++;
        // Add objects for joined XXX rows
        \$omClass = call_user_func(array(\$info['class'].'Peer','getOMClass'));
        \$cls = substr(\$omClass, (\$pos=strrpos(\$omClass,'.'))?\$pos+1:0);
        \$obj[\$x] = new \$cls();
        \$obj[\$x]->hydrate(\$rs, \$startcol[\$x-1]);
        
        // Add to the collection in 1-n relations
        \$newObject = true;
        for (\$j=0, \$resCount=count(\$results); \$j < \$resCount; \$j++) {
          \$temp_obj1 = \$results[\$j];
          // getter
          \$get = 'get'.\$info['related']->getTable()->getPhpName();
          if (!method_exists(\$temp_obj1, \$get)) {
            \$get .= 'RelatedBy' . \$info['column']->getPhpName();
          }
          \$temp_obj2 = call_user_func(array(\$temp_obj1, \$get)); // CHECKME
          if (\$temp_obj2 && \$temp_obj2->getPrimaryKey() === \$obj[\$x]->getPrimaryKey()) {
            \$newObject = false;
            // adder
            \$add = 'add' . \$info['column']->getTable()->getPhpName();
            if (!method_exists(\$temp_obj2, \$add)) {
              \$add .= 'RelatedBy' . \$info['column']->getPhpName();
            }
            call_user_func(array(\$temp_obj2, \$add), \$obj[1]); // CHECKME
            break;
          }
        }
        
        // initialize if new
        if (\$newObject) {
          // init
          \$init = 'init' . \$info['column']->getTable()->getPhpName() . 's';
          if (!method_exists(\$obj[\$x], \$init)) {
            \$init .= 'RelatedBy' . \$info['column']->getPhpName();
          }
          call_user_func(array(\$obj[\$x], \$init));
          // add
          \$add = 'add' . \$info['column']->getTable()->getPhpName();
          if (!method_exists(\$obj[\$x], \$add)) {
            \$add .= 'RelatedBy' . \$info['column']->getPhpName();
          }
          call_user_func(array(\$obj[\$x], \$add), \$obj[1]);
        }
        
      }
      
      \$results[] = \$obj[1];
    }
    
    return \$results;
  }
  
STR;

      $this->addFunction($peerCode, $comment, $function);
      
      
      
      /****************** doCountJoin **********************/
      
      
      
      $comment = <<<STR
  /**
   * Returns the number of rows matching criteria, joining all the related objects
   *
   * @param      array \$fields TablePeer::FIELD_NAME => "RelatedTable"
   * @param      Criteria \$c
   * @param      boolean \$distinct Whether to select only distinct columns (You can also set DISTINCT modifier in Criteria).
   * @param      Connection \$con
   * @return     int Number of matching rows.
   */
STR;

      if (DataModelBuilder::getBuildProperty('builderJoinWithAlias')) {
        $setJoin = <<<STR
\$alias = 't' . (\$i++);
      \$criteria->addAlias(\$alias, constant(\$info['class'].'Peer::TABLE_NAME'));
      \$right = call_user_func(array(\$info['class'].'Peer', 'alias'), \$alias, \$info['related']->getFullyQualifiedName());
STR;
      } else {
        $setJoin = '$right = $info[\'related\']->getFullyQualifiedName();';
      }
      
      if (!DataModelBuilder::getBuildProperty('builderImplicitJoins')) {
        $addJoin = '$criteria->addJoin($field, $right, $info[\'column\']->isNotNull() ? Criteria::INNER_JOIN : Criteria::LEFT_JOIN);';
      } else {
        $addJoin = '$criteria->addJoin($field, $right);';
      }

      if (DataModelBuilder::getBuildProperty('builderJoinWithAlias')) {
				$addJoin .= "\n      $fixWhereAlias";
			}
      
      $function = <<<STR
  public static function doCountJoin(array \$fields, Criteria \$criteria, \$distinct = false, \$con = null)
  {
    // we're going to modify criteria, so copy it first
    \$criteria = clone \$criteria;

    // clear out anything that might confuse the ORDER BY clause
    \$criteria->clearSelectColumns()->clearOrderByColumns();
    if (\$distinct || in_array(Criteria::DISTINCT, \$criteria->getSelectModifiers())) {
      \$criteria->addSelectColumn($peer::COUNT_DISTINCT);
    } else {
      \$criteria->addSelectColumn($peer::COUNT);
    }

    // just in case we're grouping: add those columns to the select statement
    foreach(\$criteria->getGroupByColumns() as \$column)
    {
      \$criteria->addSelectColumn(\$column);
    }

    \$fields = self::getRelationInfo(\$fields);
    
    // addJoins with aliases
    \$i = 1;
    foreach (\$fields as \$field => \$info) {
      $setJoin
      $addJoin
    }
    
    \$rs = $peer::doSelectRS(\$criteria, \$con);
    if (\$rs->next()) {
      return \$rs->getInt(1);
    } else {
      // no rows returned; we infer that means 0 matches.
      return 0;
    }
  }
STR;
      
      $this->addFunction($peerCode, $comment, $function);
    }
    
    // Checks that $temp_obj2 is defined in doSelectJoin* functions
    if (!DataModelBuilder::getBuildProperty('builderDoNotFixBugGetPrimaryKeyOnNonObject')) {
      $peerCode = preg_replace('/if *\(\$(.*?)->getPrimaryKey\(\) === \$(.*?)->getPrimaryKey\(\)\)/', 'if (\$$1 && \$$2 && \$$1->getPrimaryKey() === \$$2->getPrimaryKey())', $peerCode);
    }
    
    return $peerCode;
  }
  
  /**
   * Enter description here...
   *
   * @param unknown_type $peerCode
   * @param unknown_type $comment
   * @param unknown_type $function
   */
  protected function addFunction(&$peerCode, $comment, $function)
  {
    if (DataModelBuilder::getBuildProperty('builderAddComments')) {
      $function = $comment . "\n" . $function;
    }
    
    $peerCode = str_replace("}\n\n}", "}\n\n" . rtrim($function) . "\n\n}", $peerCode);
  }
   
  /**
   * Enter description here...
   *
   * @param unknown_type $name
   * @param unknown_type $body
   * @param unknown_type $peerCode
   * @return unknown
   */
  protected function addAliasesInJoinFunction($name, $body, &$peerCode)
  {
    // 6 cases possible
    if (preg_match('/^do(Select|Count)Join((?:All)?)((?:Except)?)(.*)$/i', $name, $m)) {
      $select = ($m[1] == 'Select');
      $all = ($m[2] == 'All');
      $except = ($m[3] == 'Except');
      $related = $m[4];
      // 6 cas possibles
      $doSelectJoinAll = $select && $all && !$except;
      $doSelectJoinXXX = $select && !$all && $related;
      $doSelectJoinAllExceptXXX = $select && $all && $except;
      $doCountJoinAll = !$select && $all && !$except;
      $doCountJoinXXX = !$select && !$all && $related;
      $doCountJoinAllExceptXXX = !$select && $all && $except;
    } else {
      
      return false;
    }
    
    $old_body = $body;
    
    if ($doSelectJoinAll || $doSelectJoinAllExceptXXX) {
      // Add alias to secondary tables
      $GLOBALS['_temp_'] = 0;
      $from = "/([a-z_0-9]+Peer)::addSelectColumns\(\\\$c\);\n(\s*)\\\$startcol([13456789]\d*) =/ie";
      $to = "'\$c->addAlias(\'t'.(++\$GLOBALS['_temp_']).'\', $1::TABLE_NAME);\n$2$1::addSelectColumns(\$c, \'t'.\$GLOBALS['_temp_'].'\');\n$2\$startcol$3 ='";
      $body = preg_replace($from, $to, $body);
      // Add alias to main table
      $from = "/([a-z_0-9]+Peer)::addSelectColumns\(\\\$c\);\n(\s*)\\\$startcol2 =/ie";
      $to = "'\$c->addAlias(\'t\', $1::TABLE_NAME);\n$2$1::addSelectColumns(\$c, \'t\');\n$2\$startcol2 ='";
      $body = preg_replace($from, $to, $body);
      // Add alias in the addJoin's
      $GLOBALS['_temp_'] = 0;
      $from = "/\\\$c->addJoin\(([a-z_0-9]+Peer)::([a-z_0-9]+), ([a-z_0-9]+Peer)::([a-z_0-9]+)/ie";
      $to = "'\$c->addJoin($1::alias(\'t\', $1::$2), $3::alias(\'t'.(++\$GLOBALS['_temp_']).'\', $3::$4)'";
      $body = preg_replace($from, $to, $body);
    }
    
    elseif ($doCountJoinAll || $doCountJoinAllExceptXXX) {
      $GLOBALS['_temp_'] = 0;
      $from = "/([ \t]*)\\\$criteria->addJoin\((.*?), ([a-z_0-9]+Peer)::([a-z_0-9]+)/ie";
      $to = "'$1\$criteria->addAlias(\'t'.(++\$GLOBALS['_temp_']).'\', $3::TABLE_NAME);\n$1\$criteria->addJoin($2, $3::alias(\'t'.\$GLOBALS['_temp_'].'\', $3::$4)'";
      $body = preg_replace($from, $to, $body);
    }
    
    //Replace in main code
    $peerCode = str_replace($old_body, $body, $peerCode);
  }

  protected function addCachedRetrieveByPk(&$script)
  {
    $script = str_replace(
      'public static function retrieveByPK($pk, $con = null)'."\n\t".'{'."\n\t\t",
      'public static function retrieveByPK($pk, $con = null)'."\n\t".'{'."\n\t\t".
        'static $instances = array();'."\n\t\t".
        'if (isset($instances[$pk]) && !is_null($instances[$pk])) return $instances[$pk];'."\n\t\t",
      $script
    );
    
    $script = str_replace(
      'return !empty($v) > 0 ? $v[0] : null;',
      'return $instances[$pk] = (!empty($v) > 0 ? $v[0] : null);',
      $script
    );
  }
  
}
