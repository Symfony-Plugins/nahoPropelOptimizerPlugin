<?php

require_once sfConfig::get('sf_symfony_lib_dir').'/addon/propel/builder/SfObjectBuilder.php';

require_once dirname(__FILE__) . '/NahoBuilder.php';

class SfOptimizedObjectBuilder extends NahoObjectBuilder
{
  
  public function build()
  {
    // Get original built code
    $objectCode = parent::build();
    
    // Remove useless includes
    if (!DataModelBuilder::getBuildProperty('builderAddIncludes')) {
      //remove all inline includes:
      //peer class include inline the mapbuilder classes
      $objectCode = preg_replace("/include_once\s*.*Base.*Peer\.php.*\s*/", "", $objectCode);
    }
    
    return $objectCode;
  }
  
  protected function addFKAccessor(&$script, ForeignKey $fk)
  {
  	// Make original modifications
  	parent::addFKAccessor($script, $fk);
  	
    // With the explicit joins support, the related object returned can be hydrated with all NULL values, in this case we could simply return NULL
    if (!DataModelBuilder::getBuildProperty('builderHydrateNULLs')) {
	  	$varName = $this->getFKVarName($fk);
	  	$return = 'return $this->' . $varName . ';';
	  	$check_null_hydrated_script = '
	  	if (!is_null($this->' . $varName . ') && !$this->' . $varName . '->isNew() && is_null($this->' . $varName . '->getPrimaryKey())) {
	  	  return NULL;
	  	}
	  	' . $return;
	  	$script = str_replace($return, $check_null_hydrated_script, $script);
	  }
  }
  
  protected function addGenericAccessor(&$script, $col)
  {
    $clo = strtolower($col->getName());
    
    $accessor = '';
    parent::addGenericAccessor($accessor, $col);
    if (DataModelBuilder::getBuildProperty('builderAddTypeCasting')) {
      $cast = null;
      switch ($col->getType()) {
        case PropelTypes::BIGINT:
        case PropelTypes::INTEGER:
        case PropelTypes::NUMERIC:
        case PropelTypes::SMALLINT:
        case PropelTypes::TINYINT:
          $cast = '(int)';
          break;
        case PropelTypes::DECIMAL:
        case PropelTypes::DOUBLE:
        case PropelTypes::FLOAT:
        case PropelTypes::REAL:
          $cast = '(float)';
          break;
        case PropelTypes::BOOLEAN:
          $cast = '(boolean)';
          break;
        case PropelTypes::VARCHAR:
        case PropelTypes::LONGVARCHAR:
          $cast = '(string)';
          break;
      }
      
      if ($cast) {
        $accessor = str_replace('return $this->'.$clo, 'return is_null($this->'.$clo.') ? null : '.$cast.' $this->'.$clo, $accessor);
      }
    }
      
    $script .= $accessor;
  }
  
}
