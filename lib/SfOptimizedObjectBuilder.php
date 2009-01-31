<?php

include_once 'addon/propel/builder/SfObjectBuilder.php';

class SfOptimizedObjectBuilder extends SfObjectBuilder
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
  
}
